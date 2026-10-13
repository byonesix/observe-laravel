<?php

namespace Observe\Laravel\Console;

use Illuminate\Console\Command;
use Observe\Laravel\Agent\Agent;
use Observe\Laravel\Agent\PackageVersion;
use Observe\Laravel\Agent\Report;
use Observe\Laravel\Envelope;
use Observe\Laravel\Settings;
use Observe\Laravel\Transport\Ingest;
use RuntimeException;

final class AgentCommand extends Command
{
    public const VERSION_CHECK_SECONDS = 60.0;

    protected $signature = Settings::AGENT_COMMAND;

    protected $description = 'Send the Observe data that workers spool on this server';

    public function handle(Settings $settings, Envelope $envelope): int
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

        $report = new Report(fn (string $line) => $this->line(date('Y-m-d H:i:s').' '.$line));
        $package = dirname(__DIR__, 2);
        $version = PackageVersion::installed($package);
        $checkAt = microtime(true) + self::VERSION_CHECK_SECONDS;
        $report->say("Observe agent {$version} started. Sending {$settings->agent->path} to {$settings->endpoint}.");

        try {
            Agent::for($settings->agent, Ingest::fromSettings($settings), $envelope, $report)->run(function () use (&$stop, &$checkAt, $package, $version, $report): bool {
                if ($stop || microtime(true) < $checkAt) {
                    return $stop;
                }

                $checkAt = microtime(true) + self::VERSION_CHECK_SECONDS;
                $installed = PackageVersion::installed($package);

                if ($installed === null || $installed === $version) {
                    return false;
                }

                $report->say("observe-laravel {$installed} was deployed. Restarting.");

                return true;
            });
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
