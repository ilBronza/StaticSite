<?php

namespace IlBronza\StaticSite;

use RuntimeException;

final class Paths
{
    public static function relative(string $path): string
    {
        $path = ltrim($path, '/');
        $decoded = rawurldecode($path);
        if ($path === '' || preg_match('~[\\\\\x00-\x1f?#]~', $decoded)
            || preg_match('~(^|/)\.{1,2}(/|$)~', $decoded) || str_contains($decoded, ':')) {
            throw new RuntimeException('Unsafe export path: '.$path);
        }

        return $path;
    }

    public static function page(string $path): string
    {
        if (strpbrk($path, '?#') !== false) {
            throw new RuntimeException('Query states require an explicit static output path: '.$path);
        }
        $path = trim($path, '/');
        if ($path === '') {
            return '/';
        }
        self::relative($path);

        return '/'.$path.(pathinfo($path, PATHINFO_EXTENSION) ? '' : '/');
    }

    public static function file(string $path): string
    {
        return $path === '/' ? 'index.html' : rawurldecode(self::relative($path)).(str_ends_with($path, '/') ? 'index.html' : '');
    }

    public static function key(string $url): string
    {
        $parts = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($parts === false) {
            throw new RuntimeException('Invalid URL: '.$url);
        }
        parse_str($parts['query'] ?? '', $query);
        $sort = function (array &$values) use (&$sort): void {
            ksort($values);
            foreach ($values as &$value) {
                if (is_array($value)) {
                    $sort($value);
                }
            }
        };
        $sort($query);

        return ($parts['path'] ?? '/').($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }
}
