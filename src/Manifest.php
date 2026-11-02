<?php

namespace IlBronza\StaticSite;

use IlBronza\StaticSite\Contracts\PageSource;
use IlBronza\StaticSite\Http\Middleware\Exportable;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use RuntimeException;

final class Manifest
{
    public function __construct(private Router $router) {}

    /** @return array<string, array{page: Page, source: string, path: string}> */
    public function collect(): array
    {
        $collection = $this->router->getRoutes();
        if (method_exists($collection, 'refreshNameLookups')) {
            $collection->refreshNameLookups();
        }
        app('url')->setRoutes($collection);
        $routes = [];
        foreach ($this->router->getRoutes() as $route) {
            $name = $route->getName();
            $selected = in_array(Exportable::class, $route->gatherMiddleware(), true)
                || ($name && Str::is(config('static-site.routes', []), $name));
            if (! $selected || ($name && Str::is(config('static-site.exclude', []), $name))) {
                continue;
            }
            if (! $name || ! in_array('GET', $route->methods(), true)) {
                throw new RuntimeException('Exportable routes must be named GET routes: '.$route->uri());
            }
            $routes[$name] = $route;
        }

        $pages = [];
        $handled = [];
        foreach (config('static-site.sources', []) as $class) {
            $source = app($class);
            if (! $source instanceof PageSource) {
                throw new RuntimeException($class.' must implement PageSource.');
            }
            foreach ($source->routes() as $name) {
                if (! isset($routes[$name])) {
                    throw new RuntimeException('Page source claims a route that is not exportable: '.$name);
                }
                $handled[$name] = true;
            }
            foreach ($source->pages() as $page) {
                if (! $page instanceof Page || ! in_array($page->route, $source->routes(), true)) {
                    throw new RuntimeException('A page source returned an undeclared route.');
                }
                $pages[] = $page;
            }
        }
        foreach ($routes as $name => $route) {
            if (isset($handled[$name])) {
                continue;
            }
            if ($route->parameterNames()) {
                throw new RuntimeException('Missing page source for parameterized route: '.$name);
            }
            $pages[] = new Page($name);
        }
        if (! $pages) {
            throw new RuntimeException('No pages have been registered for export.');
        }

        $manifest = [];
        $paths = [];
        foreach ($pages as $page) {
            if (! isset($routes[$page->route])) {
                throw new RuntimeException('Page is not on an exportable route: '.$page->route);
            }
            if ($page->query && $page->path === null) {
                throw new RuntimeException('Query states require an explicit static output path: '.$page->route);
            }
            $routePath = route($page->route, $page->parameters, false);
            $separator = str_contains($routePath, '?') ? '&' : '?';
            $source = $routePath.($page->query ? $separator.http_build_query($page->query, '', '&', PHP_QUERY_RFC3986) : '');
            $path = Paths::page($page->path ?? $routePath);
            if (in_array(Paths::file($path), ['sitemap.xml', 'robots.txt', '404.html', '_redirects', '.htaccess', '.static-site.json'], true)) {
                throw new RuntimeException('Page uses a reserved output path: '.$path);
            }
            $key = Paths::key($source);
            if (isset($manifest[$key])) {
                if ($manifest[$key]['path'] !== $path) {
                    throw new RuntimeException('Duplicate source with different output: '.$source);
                }

                continue;
            }
            if (isset($paths[$path])) {
                throw new RuntimeException('Multiple pages write to the same output: '.$path);
            }
            $paths[$path] = true;
            $manifest[$key] = compact('page', 'source', 'path');
        }

        return $manifest;
    }
}
