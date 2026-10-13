<?php

namespace Observe\Laravel;

use Closure;
use Illuminate\Http\Request;

final readonly class RecordRequest
{
    public function __construct(private Recorder $recorder) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $this->recorder->beginRequest($request);

        return $next($request);
    }
}
