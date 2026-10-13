<?php

namespace Observe\Laravel;

use Illuminate\Database\QueryException;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;

final class Stacktrace
{
    public const SNIPPET_RADIUS = 5;

    private const MAX_CHAIN = 10;

    private const MAX_FRAMES = 100;

    private const MAX_FILE_BYTES = 2_000_000;

    private const MAX_LINE_BYTES = 500;

    private array $roots;

    private array $files = [];

    public function __construct(string $basePath)
    {
        $roots = [rtrim($basePath, '/').'/'];
        $real = realpath($basePath);

        if ($real !== false && rtrim($real, '/').'/' !== $roots[0]) {
            $roots[] = rtrim($real, '/').'/';
        }

        $this->roots = $roots;
    }

    public function chain(Throwable $throwable): array
    {
        $chain = [];
        $seen = [];
        $this->files = [];

        for ($e = $throwable; $e !== null && count($chain) < self::MAX_CHAIN; $e = $e->getPrevious()) {
            if (isset($seen[spl_object_id($e)])) {
                break;
            }

            $seen[spl_object_id($e)] = true;
            $chain[] = [
                'type' => $e::class,
                'message' => Values::truncate(self::message($e), Values::MAX_MESSAGE_BYTES * Values::CAPTURE),
                'code' => (string) $e->getCode(),
                'frames' => $this->frames($e),
            ];
        }

        return $chain;
    }

    public static function message(Throwable $e): string
    {
        if ($e instanceof QueryException && $e->getPrevious() !== null) {
            return $e->getPrevious()->getMessage().' (Connection: '.$e->getConnectionName().', SQL: '.$e->getSql().')';
        }

        return $e->getMessage();
    }

    public function frames(Throwable $e): array
    {
        if ($e instanceof FatalError) {
            return [$this->frame($e->getFile(), $e->getLine(), null)];
        }

        $trace = $e->getTrace();
        $frames = [$this->frame($e->getFile(), $e->getLine(), $trace[0] ?? null)];

        foreach ($trace as $i => $call) {
            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }

            $frames[] = $this->frame($call['file'] ?? null, $call['line'] ?? null, $trace[$i + 1] ?? null);
        }

        return $frames;
    }

    public function frame(?string $file, ?int $line, ?array $callee): array
    {
        $relative = $file === null ? null : $this->relative($file);
        $inApp = $relative !== null && $relative !== $file && ! str_starts_with($relative, 'vendor/');

        $frame = array_filter([
            'file' => $relative,
            'line' => $line,
            'module' => $callee['class'] ?? null,
            'function' => $callee === null ? '{main}' : $this->symbol($callee['function']),
        ], fn (mixed $v) => $v !== null);
        $frame['in_app'] = $inApp;

        if ($inApp && $file !== null && $line !== null) {
            $snippet = $this->snippet($file, $line);

            if ($snippet !== null) {
                $frame['snippet'] = $snippet;
            }
        }

        return $frame;
    }

    public function relative(string $file): string
    {
        foreach ($this->roots as $root) {
            if (str_starts_with($file, $root)) {
                return substr($file, strlen($root));
            }
        }

        return $file;
    }

    public function symbol(string $function): string
    {
        return str_starts_with($function, '{closure:') ? str_replace($this->roots, '', $function) : $function;
    }

    public function snippet(string $file, int $line): ?array
    {
        $lines = $this->lines($file);

        if ($lines === null || $line < 1 || $line > count($lines)) {
            return null;
        }

        $first = max(1, $line - self::SNIPPET_RADIUS);
        $last = min(count($lines), $line + self::SNIPPET_RADIUS);

        return [
            'first_line' => $first,
            'lines' => array_map(
                fn (string $l) => Values::truncate(rtrim($l, "\r\n"), self::MAX_LINE_BYTES),
                array_slice($lines, $first - 1, $last - $first + 1),
            ),
        ];
    }

    private function lines(string $file): ?array
    {
        if (! array_key_exists($file, $this->files)) {
            $size = @filesize($file);
            $lines = $size !== false && $size <= self::MAX_FILE_BYTES ? @file($file) : false;
            $this->files[$file] = $lines === false ? null : $lines;
        }

        return $this->files[$file];
    }
}
