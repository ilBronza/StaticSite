<?php

namespace IlBronza\StaticSite\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** A route marker; it does not change normal requests or authorization. */
final class Exportable
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }
}
