<?php

namespace AmiPraha\AILogger\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class LoggingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public bool $fail = false)
    {
    }

    public function handle(): void
    {
        if ($this->fail) {
            throw new RuntimeException('Job failed');
        }

        Log::warning('Logged from a job');
    }
}
