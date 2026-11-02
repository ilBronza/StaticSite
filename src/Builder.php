<?php

namespace IlBronza\StaticSite;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

final class Builder
{
    public function __construct(private Manifest $manifest, private Renderer $renderer, private Filesystem $files, private ExportContext $context) {}

    public function build(?string $output = null, ?string $origin = null): array
    {
        if (! app()->runningInConsole() || $this->context->building) {
            throw new RuntimeException('Static builds may only run from the console, one build at a time.');
        }
        $origin = rtrim($origin ?? config('static-site.url'), '/');
        $parts = parse_url($origin);
        if (! $parts || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! empty($parts['path'])) {
            throw new RuntimeException('The public URL must be an HTTP(S) origin, without a path or credentials.');
        }
        $output = rtrim($output ?? config('static-site.output'), DIRECTORY_SEPARATOR);
        if (! str_starts_with($output, DIRECTORY_SEPARATOR) || str_contains($output, '/../') || $output === '') {
            throw new RuntimeException('The output must be an absolute directory path.');
        }
        $this->files->ensureDirectoryExists(dirname($output));
        $output = realpath(dirname($output)).DIRECTORY_SEPARATOR.basename($output);
        if (is_link($output) || (file_exists($output) && ! is_file($output.'/.static-site.json'))) {
            throw new RuntimeException('Refusing to replace an output directory not owned by static-site: '.$output);
        }
        foreach ([base_path(), public_path(), ...array_column(config('static-site.assets', []), 'source')] as $protected) {
            $protected = realpath($protected) ?: $protected;
            if ($output === $protected || str_starts_with($protected.'/', $output.'/')) {
                throw new RuntimeException('Output cannot replace an application or asset directory.');
            }
        }
        foreach (array_column(config('static-site.assets', []), 'source') as $source) {
            $source = realpath($source) ?: $source;
            if (str_starts_with($output.'/', rtrim($source, '/').'/')) {
                throw new RuntimeException('Output cannot be inside an asset directory.');
            }
        }

        $lock = fopen($output.'.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another static build is already running.');
        }
        $stage = $output.'.build-'.bin2hex(random_bytes(6));
        $backup = $output.'.previous-'.bin2hex(random_bytes(6));
        $originalConfig = config()->all();
        $originalLocale = app()->getLocale();
        // Resolve these before snapshotting: recreating Laravel's URL singleton
        // after its route rebinding callback exists can recurse on a second build.
        foreach (['url', 'auth', 'session'] as $name) {
            app($name);
        }
        $services = [];
        foreach (['request', 'url', 'auth', 'auth.driver', 'session', 'session.store'] as $name) {
            $services[$name] = app()->resolved($name) ? app($name) : null;
        }

        try {
            $this->context->building = true;
            config()->set(['session.driver' => 'array', 'session.cookie' => 'static_site_build']);
            foreach (config('static-site.build_config', []) as $key => $value) {
                config()->set($key, is_string($value) ? str_replace('{url}', $origin, $value) : $value);
            }
            foreach (['url', 'auth', 'session'] as $name) {
                app()->instance($name, clone app($name));
            }
            app()->forgetInstance('auth.driver');
            app('url')->forceRootUrl($origin);
            app('url')->useAssetOrigin($origin);
            Facade::clearResolvedInstances();

            $manifest = $this->manifest->collect();
            $this->files->makeDirectory($stage, 0755, true);
            $assets = new AssetStore($stage, $this->files);
            $document = new Document($manifest, $assets, $origin);
            $assets->copyAll($document->url(...));
            $redirects = [];
            $sitemap = [];

            foreach ($manifest as $entry) {
                $response = $this->renderer->render($entry['source'], $origin);
                $status = $response->getStatusCode();
                if (in_array($status, [301, 302, 307, 308], true)) {
                    $target = $document->url($response->headers->get('Location'), $entry['source']);
                    $redirects[parse_url($entry['source'], PHP_URL_PATH)] = ['target' => $target, 'status' => $status];
                    $escaped = htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $html = '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url='.$escaped.'"><link rel="canonical" href="'.$escaped.'"><title>Redirect</title></head><body><a href="'.$escaped.'">Continue</a></body></html>';
                } else {
                    if ($status !== 200 || ! str_contains($response->headers->get('Content-Type', ''), 'text/html') || $response->getContent() === false) {
                        throw new RuntimeException('Expected an HTML page, received HTTP '.$status.' for '.$entry['source']);
                    }
                    $html = $document->rewrite($response->getContent(), $entry['source']);
                    if ($entry['page']->sitemap) {
                        $sitemap[] = $origin.$entry['path'];
                    }
                }
                $target = $stage.'/'.Paths::file($entry['path']);
                if (file_exists($target)) {
                    throw new RuntimeException('Page collides with an exported asset: '.$entry['path']);
                }
                $this->files->ensureDirectoryExists(dirname($target));
                $this->files->put($target, $html);
            }

            if (config('static-site.sitemap')) {
                $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
                foreach (array_unique($sitemap) as $url) {
                    $xml .= '<url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>';
                }
                $this->files->put($stage.'/sitemap.xml', $xml.'</urlset>');
            }
            $this->files->put($stage.'/robots.txt', config('static-site.robots'));
            $notFound = config('static-site.not_found_view');
            $this->files->put($stage.'/404.html', $notFound ? $document->rewrite(view($notFound)->render(), '/404.html') : '<!doctype html><html lang="en"><meta charset="utf-8"><title>404</title><h1>404</h1><p>Page not found.</p><a href="/">Home</a></html>');
            $apache = "DirectoryIndex index.html\nErrorDocument 404 /404.html\n";
            $netlify = '';
            if ($redirects) {
                $apache .= "<IfModule mod_rewrite.c>\nRewriteEngine On\n";
            }
            foreach ($redirects as $source => $redirect) {
                $target = $redirect['target'];
                $status = $redirect['status'];
                $apache .= 'RewriteRule ^'.preg_quote(trim($source, '/'), '#').'/?$ "'.$target.'" [R='.$status.",L,NE]\n";
                $netlify .= $source.' '.$target.' '.$status."\n";
                if ($source !== '/') {
                    $netlify .= rtrim($source, '/').'/ '.$target.' '.$status."\n";
                }
            }
            if ($redirects) {
                $apache .= "</IfModule>\n";
            }
            $this->files->put($stage.'/.htaccess', $apache);
            $this->files->put($stage.'/_redirects', $netlify);
            $report = ['generated_at' => gmdate('c'), 'url' => $origin, 'pages' => count($manifest), 'redirects' => count($redirects), 'routes' => array_map(fn ($entry) => ['route' => $entry['page']->route, 'source' => $entry['source'], 'path' => $entry['path']], array_values($manifest))];
            $this->files->put($stage.'/.static-site.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            if (is_dir($output) && ! rename($output, $backup)) {
                throw new RuntimeException('Cannot retain previous export.');
            }
            if (! rename($stage, $output)) {
                if (is_dir($backup)) {
                    rename($backup, $output);
                }
                throw new RuntimeException('Cannot publish the completed export directory.');
            }
            if (is_dir($backup)) {
                $this->files->deleteDirectory($backup);
            }

            return ['output' => $output, ...$report];
        } finally {
            if (is_dir($stage)) {
                $this->files->deleteDirectory($stage);
            }
            config()->set($originalConfig);
            app()->setLocale($originalLocale);
            foreach ($services as $name => $service) {
                if ($service !== null) {
                    app()->instance($name, $service);
                } else {
                    app()->forgetInstance($name);
                }
            }
            Facade::clearResolvedInstances();
            $this->context->building = false;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
