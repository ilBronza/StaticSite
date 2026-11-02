<?php

return [
    'url' => env('STATIC_SITE_URL', env('APP_URL', 'http://localhost')),
    'output' => base_path('dist'),

    // Routes explicitly opt in with the Exportable middleware, or by name here.
    'routes' => [],
    'exclude' => [],

    // PageSource implementations enumerate parameterized routes and query states.
    'sources' => [],

    // Public files only. Never point a resource at the application or storage root.
    // ['source' => public_path('assets'), 'destination' => 'assets']
    'assets' => [],

    // Overrides apply only inside the CLI build, then are restored.
    'build_config' => [],
    'allowed_query_parameters' => [],
    'query_defaults' => [],
    'json_url_keys' => ['url', 'href', 'src', 'image', 'poster', 'action', 'path', 'publicUrl'],
    'sitemap' => true,
    'robots' => "User-agent: *\nDisallow: /\n",
];
