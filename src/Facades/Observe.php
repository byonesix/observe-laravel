<?php

namespace Observe\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Observe\Laravel\Recorder;

/**
 * @method static void attributes(\Closure $resolver)
 * @method static \Observe\Laravel\TraceContext|null context()
 * @method static mixed span(string $name, \Closure $callback, array $attributes = [])
 */
final class Observe extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Recorder::class;
    }
}
