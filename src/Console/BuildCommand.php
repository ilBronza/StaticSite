<?php

namespace IlBronza\StaticSite\Console;

use IlBronza\StaticSite\Builder;
use Illuminate\Console\Command;
use Throwable;

final class BuildCommand extends Command
{
    protected $signature = 'static-site:build {--output= : Absolute output directory} {--url= : Public HTTP(S) origin}';

    protected $description = 'Build and validate a complete static website without publishing it remotely';

    public function handle(Builder $builder): int
    {
        try {
            $report = $builder->build($this->option('output'), $this->option('url'));
            $this->info('Exported '.$report['pages'].' pages ('.$report['redirects'].' redirects) to '.$report['output']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            if ($this->output->isVerbose()) {
                throw $exception;
            }

            return self::FAILURE;
        }
    }
}
