<?php

namespace IlBronza\StaticSite\Console;

use IlBronza\StaticSite\Manifest;
use Illuminate\Console\Command;

final class RoutesCommand extends Command
{
    protected $signature = 'static-site:routes';

    protected $description = 'List all source URLs and their static output paths, without rendering pages';

    public function handle(Manifest $manifest): int
    {
        $rows = [];
        foreach ($manifest->collect() as $entry) {
            $rows[] = [$entry['page']->route, $entry['source'], $entry['path']];
        }
        $this->table(['Route', 'Source', 'Static path'], $rows);
        $this->info(count($rows).' pages.');

        return self::SUCCESS;
    }
}
