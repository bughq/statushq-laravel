<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Metrics push
    |--------------------------------------------------------------------------
    |
    | CPU, memory and disk are pushed from the scheduler to a StatusHQ metrics
    | monitor. Push rather than pull because it is the only way to get a true
    | reading per node: a pulled endpoint describes whichever server happened
    | to answer, which behind a load balancer is a coin toss.
    |
    | The token is the credential — it identifies the monitor, so treat it the
    | way you would an API key.
    |
    */

    'metrics' => [

        'enabled' => env('STATUSHQ_METRICS_ENABLED', true),

        'token' => env('STATUSHQ_METRICS_TOKEN'),

        'url' => env('STATUSHQ_URL', 'https://statushq.org'),

        /*
         * Registers the sampler on Laravel's scheduler. Turn this off to call
         * `statushq:report` from your own cron or supervisor instead — the
         * command is the same either way.
         *
         * Note that CPU usage is a rate: the first run after a deploy stores
         * a baseline and reports nothing. The second run, a minute later, is
         * the first one that sends.
         */
        'schedule' => env('STATUSHQ_METRICS_SCHEDULE', true),

        /*
         * Which machine the sample describes. Defaults to the hostname, which
         * is what distinguishes four app servers pushing to one monitor.
         */
        'host' => env('STATUSHQ_HOST'),

        'disk_path' => env('STATUSHQ_DISK_PATH', '/'),

        'timeout' => env('STATUSHQ_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health endpoint
    |--------------------------------------------------------------------------
    |
    | The pull side: a JSON endpoint StatusHQ polls, in the same schema
    | spatie/laravel-health and Oh Dear use. Pull is what tells you the app is
    | reachable from the outside — something a push can never prove, because a
    | silent agent and a dead network look identical.
    |
    */

    'health' => [

        'enabled' => env('STATUSHQ_HEALTH_ENABLED', true),

        /*
         * Deliberately not spatie/laravel-health's default path, so that
         * installing both packages does not collide on one route. If you are
         * replacing Oh Dear and want the URL to stay identical, set this to
         * 'oh-dear-health-check-results'.
         */
        'path' => env('STATUSHQ_HEALTH_PATH', 'statushq-health-check-results'),

        /*
         * Presented by the caller in the `oh-dear-health-check-secret` header.
         *
         * Without one the route is not registered at all, so the URL 404s like
         * any other unknown path. Installing a package must not put an
         * unauthenticated description of your application's internals on the
         * public internet as a side effect.
         */
        'secret' => env('STATUSHQ_HEALTH_SECRET', env('OH_DEAR_HEALTH_CHECK_SECRET')),

        /*
         * The escape hatch: serve the endpoint with no secret at all.
         *
         * Reasonable behind a private network or as a Kubernetes liveness
         * probe, where the endpoint is not reachable from outside and the
         * prober cannot send a header. Anywhere else it publishes your check
         * names, disk usage and memory totals to whoever asks.
         */
        'allow_unauthenticated' => env('STATUSHQ_HEALTH_ALLOW_UNAUTHENTICATED', false),

        'middleware' => [],

        /*
         * Thresholds for the built-in host checks, as percentages. At or above
         * `warn` is degraded; at or above `fail` is down.
         */
        'thresholds' => [
            'cpu' => ['warn' => 75, 'fail' => 90],
            'memory' => ['warn' => 80, 'fail' => 95],
            'disk' => ['warn' => 70, 'fail' => 90],
        ],
    ],

];
