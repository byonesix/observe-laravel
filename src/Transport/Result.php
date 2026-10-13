<?php

namespace Observe\Laravel\Transport;

enum Result
{
    case Accepted;
    case Dropped;
    case Unauthorized;
    case Failed;
    case Skipped;
    case Spooled;

    public static function fromHttp(int $curlErrno, int $status): self
    {
        return match (true) {
            $curlErrno !== 0 => self::Failed,
            $status >= 200 && $status < 300 => self::Accepted,
            $status === 401 => self::Unauthorized,
            $status === 429, $status >= 500, $status === 0 => self::Failed,
            default => self::Dropped,
        };
    }
}
