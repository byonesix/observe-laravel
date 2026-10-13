# observe-laravel

Sends errors, logs and traces from a Laravel app to Observe.

## Requirements

- PHP 8.4 or later, with `ext-curl`, `ext-json` and `ext-zlib`.
- Laravel 12 or 13.

## Install

Add the repository to the app's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/byonesix/observe-laravel" }
]
```

Then require the package:

```sh
composer require byonesix/observe-laravel:^0.3
```

## Configure

| Variable | Default | Meaning |
| --- | --- | --- |
| `OBSERVE_KEY` | none | The ingest key. Nothing is sent without it. |
| `OBSERVE_ENDPOINT` | none | The ingest URL. Nothing is sent without it. |
| `OBSERVE_ENABLED` | `true` | Set to `false` to turn the package off. |
| `OBSERVE_SERVICE` | `APP_NAME` | The service name. |
| `OBSERVE_RELEASE` | see [Release](#release) | The release that each event belongs to. |
| `OBSERVE_MIN_LEVEL` | `info` | The lowest log level that is sent. |
| `OBSERVE_SAMPLE_RATE` | `0.1` | The share of traces that is kept. Failed and slow traces are always kept. |
| `OBSERVE_SCRUB_KEYS` | none | Extra keys to scrub, comma-separated. |
| `OBSERVE_CLIENT_AGENTS` | none | User-agent product names to record as `client.name`, comma-separated. |
| `OBSERVE_AGENT` | `false` | Set to `true` to hand data to the [agent](#agent). |

To change other settings, publish the config file:

```sh
php artisan vendor:publish --tag=observe-config
```

## Agent

With the agent, PHP workers write their data to `storage/observe/agent` and the agent sends it. Without it, each worker sends directly.

Run `php artisan observe:agent` as a long-running process, as the same user as PHP-FPM, and set `OBSERVE_AGENT=true`. The agent prints a line when it starts, when sending starts working or fails, and a summary every minute. Once a minute it checks the installed package version, and it restarts when a deploy brings a new version. Restart it yourself after you change `OBSERVE_` settings. When the agent is not running, workers send directly.

`ext-pcntl` lets the agent finish its sends before it stops.

## Release

The package picks the release in this order:

1. `OBSERVE_RELEASE`.
2. The contents of a `REVISION` file in the app's base path.
3. The `FORGE_DEPLOY_COMMIT` environment variable.

## Attributes

Register a closure in `AppServiceProvider::boot()` to add attributes to every event:

```php
use Observe\Laravel\Facades\Observe;

Observe::attributes(fn () => [
    'tenant_id' => tenant()?->id,
]);
```
