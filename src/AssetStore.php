<?php

namespace IlBronza\StaticSite;

use Illuminate\Filesystem\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class AssetStore
{
    private array $copied = [];

    private array $roots = [];

    private const EXTENSIONS = ['css', 'js', 'mjs', 'json', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'mp4', 'webm', 'mp3', 'wav', 'txt', 'xml'];

    public function __construct(private string $directory, private Filesystem $files)
    {
        foreach (config('static-site.assets', []) as $entry) {
            $root = realpath($entry['source']);
            if (! $root || ! is_dir($root)) {
                throw new RuntimeException('Asset directory does not exist: '.$entry['source']);
            }
            $entry['source'] = $root;
            $entry['destination'] = rtrim(Paths::relative($entry['destination']), '/');
            $this->roots[] = $entry;
        }
    }

    public function copyAll(callable $rewrite): void
    {
        foreach ($this->roots as $entry) {
            if (($entry['copy'] ?? 'referenced') !== 'all') {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($entry['source'], \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->isLink()) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($entry['source']) + 1);
                if (! $this->allowed($relative)) {
                    continue;
                }
                $this->copy('/'.$entry['destination'].'/'.$relative, $rewrite);
            }
        }
    }

    public function copy(string $path, callable $rewrite): bool
    {
        $relative = rawurldecode(Paths::relative($path));
        if (isset($this->copied[$relative])) {
            return true;
        }
        if (! $this->allowed($relative)) {
            return false;
        }

        foreach ($this->roots as $entry) {
            $prefix = $entry['destination'].'/';
            if (! str_starts_with($relative, $prefix)) {
                continue;
            }
            $source = realpath($entry['source'].'/'.substr($relative, strlen($prefix)));
            if (! $source || ! is_file($source) || ! str_starts_with($source, $entry['source'].DIRECTORY_SEPARATOR)) {
                continue;
            }

            $this->copied[$relative] = true;
            $target = $this->directory.'/'.$relative;
            $this->files->ensureDirectoryExists(dirname($target));
            if (strtolower(pathinfo($source, PATHINFO_EXTENSION)) === 'css') {
                $content = $this->css(file_get_contents($source), '/'.$relative, $rewrite);
                $this->files->put($target, $content);
            } else {
                if (! $this->files->copy($source, $target)) {
                    throw new RuntimeException('Cannot copy asset: '.$source);
                }
            }

            return true;
        }

        return false;
    }

    public function css(string $css, string $base, callable $rewrite): string
    {
        $css = preg_replace_callback('~url\(\s*([\'"]?)(.*?)\1\s*\)~is', fn ($m) => 'url("'.$rewrite(trim($m[2]), $base, true).'")', $css);

        return preg_replace_callback('~(@import\s+)([\'"])([^\'"]+)\2~i', fn ($m) => $m[1].$m[2].$rewrite($m[3], $base, true).$m[2], $css);
    }

    private function allowed(string $path): bool
    {
        foreach (explode('/', $path) as $part) {
            if (str_starts_with($part, '.')) {
                return false;
            }
        }

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }
}
