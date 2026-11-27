<?php

$vendor = getenv('STATIC_SITE_TEST_VENDOR') ?: __DIR__.'/../vendor';
if (! is_file($vendor.'/autoload.php')) {
    $vendor = __DIR__.'/../../.ibtest/vendor';
}
$loader = require $vendor.'/autoload.php';
$loader->addPsr4('IlBronza\\StaticSite\\', __DIR__.'/../src/', true);
$loader->addPsr4('IlBronza\\StaticSite\\Tests\\', __DIR__.'/', true);

require __DIR__.'/runtime.php';
