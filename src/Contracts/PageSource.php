<?php

namespace IlBronza\StaticSite\Contracts;

use IlBronza\StaticSite\Page;

interface PageSource
{
    /** Names handled even when this source currently has no published records. */
    public function routes(): array;

    /** @return iterable<Page> */
    public function pages(): iterable;
}
