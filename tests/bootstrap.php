<?php

use Orchestra\Testbench\TestCase;

$vendor = getenv('STATIC_SITE_TEST_VENDOR') ?: __DIR__.'/../vendor';
if (! is_file($vendor.'/autoload.php')) {
    $vendor = __DIR__.'/../../.ibtest/vendor';
}
$loader = require $vendor.'/autoload.php';
$loader->addPsr4('IlBronza\\StaticSite\\', __DIR__.'/../src/', true);
$loader->addPsr4('IlBronza\\StaticSite\\Tests\\', __DIR__.'/', true);

// Testbench must never write into a shared vendor installation.
$runtime = __DIR__.'/runtime';
if (! is_dir($runtime.'/bootstrap')) {
    $source = dirname((new ReflectionClass(TestCase::class))->getFileName(), 2).'/laravel';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        $destination = $runtime.substr($file->getPathname(), strlen($source));
        if ($file->isDir()) {
            if (! is_dir($destination)) {
                mkdir($destination, 0777, true);
            }
        } elseif (! is_file($destination)) {
            copy($file->getPathname(), $destination);
        }
    }
}
