<?php

namespace AmiPraha\AILogger;

use Illuminate\Contracts\Queue\Job;
use Throwable;

/**
 * Holds the queued job the current process is running, so that logs written
 * while it runs carry the job and measure the time from the job's start
 * instead of from the start of a long-running worker.
 */
class QueueJobContext
{
    protected static ?array $job = null;
    protected static ?float $startedAt = null;

    public static function start(string $connectionName, Job $job): void
    {
        try {
            static::$job = [
                'name' => $job->resolveName(),
                'queue' => $job->getQueue(),
                'connection' => $connectionName,
                'attempt' => $job->attempts(),
                'max_tries' => $job->maxTries(),
                'uuid' => $job->uuid(),
            ];
            static::$startedAt = microtime(true);
        } catch (Throwable $e) {
            static::clear();
        }
    }

    public static function clear(): void
    {
        static::$job = null;
        static::$startedAt = null;
    }

    public static function job(): ?array
    {
        return static::$job;
    }

    public static function startedAt(): ?float
    {
        return static::$startedAt;
    }
}
