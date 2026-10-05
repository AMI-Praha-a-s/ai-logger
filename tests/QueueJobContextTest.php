<?php

namespace AmiPraha\AILogger\Tests;

use AmiPraha\AILogger\QueueJobContext;
use AmiPraha\AILogger\Tests\Fixtures\LoggingJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class QueueJobContextTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
        $app['config']->set('queue.failed.driver', 'null');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function test_a_log_written_by_a_job_carries_the_job_and_its_own_execution_time(): void
    {
        // A worker that has been running for an hour
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 3600;

        LoggingJob::dispatch();
        $this->runNextJob();

        $payload = $this->lastSentPayload();
        $job = $payload['debug_data']['queue_job'];

        $this->assertSame('Logged from a job', $payload['message']);
        $this->assertSame(LoggingJob::class, $job['name']);
        $this->assertSame('default', $job['queue']);
        $this->assertSame('database', $job['connection']);
        $this->assertSame(1, $job['attempt']);
        $this->assertNotEmpty($job['uuid']);
        $this->assertLessThan(60_000, $payload['performance_data']['execution_time']['value']);
        $this->assertNull(QueueJobContext::job());

        Log::warning('Logged by the worker between jobs');

        $payload = $this->lastSentPayload();

        $this->assertNull($payload['debug_data']['queue_job']);
        $this->assertGreaterThan(3_600_000, $payload['performance_data']['execution_time']['value']);
    }

    public function test_the_exception_of_a_failed_job_is_reported_with_the_job(): void
    {
        LoggingJob::dispatch(fail: true);
        $this->runNextJob();

        $payload = $this->lastSentPayload();

        $this->assertSame('Job failed', $payload['message']);
        $this->assertSame('RuntimeException', $payload['context']['exception']['type']);
        $this->assertSame(LoggingJob::class, $payload['debug_data']['queue_job']['name']);
    }

    public function test_the_job_is_forgotten_when_the_worker_loops_or_a_request_ends(): void
    {
        foreach ([new Looping('sync', 'default'), new RequestHandled(Request::create('/'), new Response())] as $event) {
            QueueJobContext::start('sync', $this->syncJob());
            $this->assertNotNull(QueueJobContext::job());

            event($event);

            $this->assertNull(QueueJobContext::job(), get_class($event) . ' did not clear the job.');
            $this->assertNull(QueueJobContext::startedAt());
        }
    }

    protected function runNextJob(): void
    {
        $this->app->make('queue.worker')->runNextJob('database', 'default', new WorkerOptions(maxTries: 1));
    }

    protected function syncJob(): SyncJob
    {
        return new SyncJob($this->app, json_encode(['uuid' => 'abc', 'displayName' => LoggingJob::class, 'job' => 'x']), 'sync', 'default');
    }
}
