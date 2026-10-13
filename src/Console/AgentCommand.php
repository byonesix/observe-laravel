<?php

namespace Observe\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository;
use Observe\Laravel\Agent\Agent;
use Observe\Laravel\Agent\CodeVersion;
use Observe\Laravel\Envelope;
use Observe\Laravel\Settings;
use Observe\Laravel\Transport\Ingest;
use RuntimeException;
use Throwable;

final class AgentCommand extends Command
{
    public const RESTART_CHECK_SECONDS = 5.0;

    protected $signature = Settings::AGENT_COMMAND;

    protected $description = 'Send the Observe data that workers spool on this server';

    public function handle(Settings $settings, Envelope $envelope, Repository $cache): int
    {
        if ($settings->agent === null || ! $settings->sends()) {
            $this->error('Set OBSERVE_AGENT=true and OBSERVE_KEY to run the Observe agent.');

            return self::FAILURE;
        }

        $stop = false;

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);

            foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
                pcntl_signal($signal, function () use (&$stop) {
                    $stop = true;
                });
            }
        }

        $base = $this->laravel->basePath();
        $started = self::restartMark($cache);
        $version = CodeVersion::of($base);
        $seen = $version;
        $checkAt = microtime(true) + self::RESTART_CHECK_SECONDS;

        try {
            Agent::for($settings->agent, Ingest::fromSettings($settings), $envelope)->run(function () use (&$stop, &$checkAt, &$seen, $started, $version, $base, $cache): bool {
                if ($stop || microtime(true) < $checkAt) {
                    return $stop;
                }

                $checkAt = microtime(true) + self::RESTART_CHECK_SECONDS;
                $mark = self::restartMark($cache);
                $now = CodeVersion::of($base);
                // A deploy is done once the code stops changing, so a composer install in progress does not restart the agent onto half-written files.
                $deployed = $now !== $version && $now === $seen;
                $seen = $now;

                return $deployed || ($mark !== null && $mark !== $started);
            });
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private static function restartMark(Repository $cache): mixed
    {
        try {
            return $cache->get('illuminate:queue:restart');
        } catch (Throwable) {
            return null;
        }
    }
}
