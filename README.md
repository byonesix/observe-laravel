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
composer require byonesix/observe-laravel:^0.5
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

## What is recorded

The package records each request, queued job, command and scheduled task as a trace. Inside it, the package records these spans:

| Span | Name | Attributes |
| --- | --- | --- |
| Database query | The SQL, with string literals replaced by `?` | Connection and driver. |
| Outgoing HTTP request | Method and host | Status code and URL. |
| Cache call | The operation and key, for example `get plan:17` | Store, and `cache.hit` on a `get`. A failed write or forget has the error status. |
| Job dispatch | The job class | Connection, queue, job ID and delay. |
| Mail | The mailable class, the notification class for mail that a notification sends, or `mail` | Mailer. |
| Notification | The notification class and channel, for example `OrderShipped via mail` | Channel. |

Mail and notification spans do not record recipients, subjects or message content. Cache spans do not record values.

The package does not record a cache call whose key is the ID of an active session. A cache-backed session driver (`redis`, `memcached`, `dynamodb` or `apc`) uses the session ID as its cache key, so its reads, writes and the forget after a regenerate are not recorded.

A cache span name contains the full key. Do not put personal data in cache keys. Some keys that Laravel packages make do contain it: the login throttle of Fortify and Breeze uses the email address and IP address, for example `jane@example.com|203.0.113.7`, and that key appears in span names.

A dispatch span links to the job that it queued: the job's trace continues from the dispatch span. A trace that is not sampled still sends its dispatch spans, and the job span of each dispatch that ran in the same process, so that jobs stay linked to the request that started them.

Each execution keeps at most 2,000 spans, of which at most 500 are cache spans. The package counts the spans that it drops and sends the count with the execution.

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
