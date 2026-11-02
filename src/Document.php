<?php

namespace IlBronza\StaticSite;

use DOMDocument;
use DOMXPath;
use RuntimeException;

final class Document
{
    private array $targets = [];

    private array $localHosts;

    public function __construct(private array $manifest, private AssetStore $assets, string $origin)
    {
        foreach ($manifest as $key => $entry) {
            $this->targets[$key] = $entry;
            $this->targets[Paths::key($entry['path'])] = $entry;
        }
        $this->localHosts = array_unique(array_filter([
            parse_url($origin, PHP_URL_HOST), parse_url(config('app.url'), PHP_URL_HOST),
            parse_url(config('app.asset_url', ''), PHP_URL_HOST),
        ]));
    }

    public function rewrite(string $html, string $source): string
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_HTML_NODEFDTD);
            foreach (iterator_to_array($dom->childNodes) as $node) {
                if ($node->nodeType === XML_PI_NODE) {
                    $dom->removeChild($node);
                }
            }
            $xpath = new DOMXPath($dom);
            if ($xpath->query('//base')->length) {
                throw new RuntimeException('Base elements are not supported in static exports: '.$source);
            }
            foreach ($xpath->query('//*[@href or @src or @action or @poster or @srcset or @style]') as $element) {
                foreach (['href', 'src', 'action', 'poster'] as $attribute) {
                    if (! $element->hasAttribute($attribute)) {
                        continue;
                    }
                    $resource = in_array($attribute, ['src', 'poster'], true)
                        || ($element->tagName === 'link' && ! in_array($element->getAttribute('rel'), ['canonical', 'alternate'], true));
                    $element->setAttribute($attribute, $this->url($element->getAttribute($attribute), $source, $resource));
                }
                if ($element->hasAttribute('srcset') && ! str_starts_with($element->getAttribute('srcset'), 'data:')) {
                    $candidates = array_map(function ($candidate) use ($source) {
                        $parts = preg_split('/\s+/', trim($candidate), 2);

                        return $this->url($parts[0], $source, true).(isset($parts[1]) ? ' '.$parts[1] : '');
                    }, explode(',', $element->getAttribute('srcset')));
                    $element->setAttribute('srcset', implode(', ', $candidates));
                }
                if ($element->hasAttribute('style')) {
                    $element->setAttribute('style', $this->assets->css($element->getAttribute('style'), $source, $this->url(...)));
                }
            }
            foreach ($xpath->query('//style') as $style) {
                $style->nodeValue = $this->assets->css($style->textContent, $source, $this->url(...));
            }
            foreach ($xpath->query('//form') as $form) {
                if (strtolower($form->getAttribute('method') ?: 'get') !== 'get') {
                    throw new RuntimeException('A static page cannot submit a server form: '.$source);
                }
            }
            foreach ($xpath->query('//input[@name="_token"]') as $token) {
                throw new RuntimeException('A CSRF token was found in a static page: '.$source);
            }
            foreach ($xpath->query('//script[@type="application/json"]') as $script) {
                $value = json_decode($script->textContent, flags: JSON_THROW_ON_ERROR);
                $walk = function (mixed $value, bool $isUrl = false, bool $resource = false) use (&$walk, $source): mixed {
                    if (is_object($value)) {
                        foreach ($value as $key => $item) {
                            $value->$key = $walk($item, $isUrl || in_array($key, config('static-site.json_url_keys', []), true), $resource || in_array($key, ['src', 'image', 'poster'], true));
                        }

                        return $value;
                    }
                    if (is_array($value)) {
                        return array_map(fn ($item) => $walk($item, $isUrl, $resource), $value);
                    }
                    if ($isUrl && is_string($value) && preg_match('~^(?:https?://|/)~', $value)) {
                        return $this->url($value, $source, $resource);
                    }

                    return $value;
                };
                $script->textContent = json_encode($walk($value), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
            }

            return $dom->saveHTML();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function url(string $url, string $source, bool $resource = false): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || str_starts_with($url, '#') || preg_match('~^(?:mailto:|tel:|data:|blob:)~i', $url)) {
            return $url;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            throw new RuntimeException('Invalid URL in '.$source.': '.$url);
        }
        if (isset($parts['host']) && ! in_array($parts['host'], $this->localHosts, true)) {
            if ($resource) {
                throw new RuntimeException('External resource is not self-contained: '.$url);
            }

            return $url;
        }
        if (isset($parts['scheme']) && ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new RuntimeException('Unsupported URL in '.$source.': '.$url);
        }
        $path = $parts['path'] ?? '';
        if ($path === '') {
            $path = isset($parts['host']) ? '/' : (parse_url($source, PHP_URL_PATH) ?: '/');
        }
        if (! str_starts_with($path, '/')) {
            $path = rtrim(dirname(parse_url($source, PHP_URL_PATH) ?: '/'), '/').'/'.$path;
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            if ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }
        $path = '/'.implode('/', $segments);
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';
        $key = Paths::key($path.$query);
        if (isset($this->targets[$key])) {
            return $this->targets[$key]['path'].$fragment;
        }
        if ($query && isset($this->targets[Paths::key($path)])) {
            $entry = $this->targets[Paths::key($path)];
            parse_str($parts['query'], $parameters);
            $canonical = $parameters;
            foreach (config('static-site.query_defaults', [])[$entry['page']->route] ?? [] as $name => $default) {
                if (isset($canonical[$name]) && is_scalar($canonical[$name]) && (string) $canonical[$name] === (string) $default) {
                    unset($canonical[$name]);
                }
            }
            $canonicalKey = Paths::key($path.($canonical ? '?'.http_build_query($canonical, '', '&', PHP_QUERY_RFC3986) : ''));
            if (isset($this->targets[$canonicalKey])) {
                return $this->targets[$canonicalKey]['path'].$fragment;
            }
            $allowed = config('static-site.allowed_query_parameters', [])[$entry['page']->route] ?? [];
            if (! array_diff(array_keys($parameters), $allowed)) {
                return $entry['path'].$query.$fragment;
            }
        }
        if ($this->assets->copy($path, $this->url(...))) {
            return $path.$query.$fragment;
        }

        throw new RuntimeException('Unexported local URL in '.$source.': '.$url);
    }
}
