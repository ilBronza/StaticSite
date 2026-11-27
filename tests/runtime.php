<?php

use Illuminate\Filesystem\Filesystem;
use function Orchestra\Testbench\default_skeleton_path;

$shared = dirname(__DIR__, 2).'/.ibtest';
$runtime = is_dir($shared)
    ? $shared.'/testbench/'.basename(dirname(__DIR__))
    : sys_get_temp_dir().'/ilbronza-testbench-'.substr(hash('sha256', __DIR__), 0, 16);

if (is_file($runtime.'/bootstrap/app.php'))
{
    return $runtime;
}

$files = new Filesystem();
$staging = $runtime.'.'.bin2hex(random_bytes(8));
mkdir($staging, 0700, true);

try
{
    if (! $files->copyDirectory(default_skeleton_path(), $staging))
    {
        throw new RuntimeException("Impossibile copiare lo scheletro Testbench in $staging");
    }

    if (! $files->moveDirectory($staging, $runtime) && ! is_file($runtime.'/bootstrap/app.php'))
    {
        throw new RuntimeException("Impossibile inizializzare Testbench in $runtime");
    }
}
finally
{
    if (is_dir($staging))
    {
        $files->deleteDirectory($staging);
    }
}

return $runtime;
