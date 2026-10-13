<?php

return [

    'enabled' => env('OBSERVE_ENABLED', true),

    'key' => env('OBSERVE_KEY'),

    'endpoint' => env('OBSERVE_ENDPOINT'),

    'service' => env('OBSERVE_SERVICE', env('APP_NAME', 'laravel')),

    'release' => env('OBSERVE_RELEASE'),

    'min_level' => env('OBSERVE_MIN_LEVEL', 'info'),

    'sample_rate' => env('OBSERVE_SAMPLE_RATE', 0.1),

    'slow_ms' => [
        'request' => 1000,
        'job' => 1000,
        'command' => 10000,
        'scheduled_task' => 10000,
    ],

    'scrub' => [
        'keys' => env('OBSERVE_SCRUB_KEYS', []),
    ],

    'client_agents' => env('OBSERVE_CLIENT_AGENTS', []),

    'capture' => [
        'query_bindings' => false,
        'request_body' => true,
        'deprecations' => false,
    ],

    'ignore_commands' => [
        'queue:work', 'queue:listen', 'horizon', 'horizon:*', 'schedule:run', 'schedule:work',
        'schedule:finish', 'octane:*', 'serve', 'tinker', 'pail', 'reverb:start', 'pulse:*', 'nightwatch:*',
        'observe:*', 'package:discover', 'list', 'help',
    ],

    'transport' => [
        'connect_timeout_ms' => 500,
        'timeout_ms' => 1500,
    ],

    'agent' => [
        'enabled' => env('OBSERVE_AGENT', false),
        'path' => null,
        'max_mb' => 256,
        'max_files' => 25000,
    ],

];
