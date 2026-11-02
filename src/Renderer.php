<?php

namespace IlBronza\StaticSite;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;

final class Renderer
{
    public function __construct(private Kernel $kernel) {}

    public function render(string $source, string $origin): Response
    {
        $app = app();
        $app->forgetScopedInstances();
        $app['auth']->forgetGuards();
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        Facade::clearResolvedInstances();

        // No cookies, credentials, or administrator session enter an export.
        $request = Request::create(rtrim($origin, '/').$source, 'GET', server: ['HTTP_ACCEPT' => 'text/html']);
        $request->attributes->set('static-site.building', true);
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);

        return $response;
    }
}
