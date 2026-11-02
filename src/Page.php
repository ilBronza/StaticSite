<?php

namespace IlBronza\StaticSite;

final class Page
{
    public function __construct(
        public readonly string $route,
        public readonly array $parameters = [],
        public readonly array $query = [],
        public readonly ?string $path = null,
        public readonly bool $sitemap = true,
    ) {}
}
