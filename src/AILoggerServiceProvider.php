<?php

namespace AmiPraha\AILogger;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\ServiceProvider;
use Monolog\Logger;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

class AILoggerServiceProvider extends ServiceProvider
{
    public function register()
    {
        // 
    }

    public function boot()
    {
        // Register custom logging channel via Log::extend()
        Log::extend('ai-logger', function ($app, array $config) {
            // Convert the string level to Monolog's Level enum
            $levelEnum = Logger::toMonologLevel($config['level'] ?? 'debug');

            // Convert the enum to its underlying integer
            $monologLevel = $levelEnum->value;

            return new Logger('ai-logger', [
                new AILogger(
                    $config['webhookUrl'] ?? null,
                    $config['sourceCode'] ?? null,
                    $config['sourceName'] ?? null,
                    $config['sourceUrl'] ?? null,
                    $config['sourceGitProjectOwner'] ?? null,
                    $config['sourceGitProjectName'] ?? null,
                    $config['sourceJiraProjectCode'] ?? null,
                    $config['sourceJiraParentProjectKey'] ?? null,
                    $monologLevel,
                    $config['bubble'] ?? true
                ),
            ]);
        });

        // Remember the queued job being run, so logs written during it carry the job
        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            QueueJobContext::start($event->connectionName, $event->job);
        });

        // Not on JobFailed: the worker reports the job's exception only after that event.
        // Looping is dispatched with until(), so this listener must not return anything.
        Event::listen([JobProcessed::class, Looping::class, RequestHandled::class], function () {
            QueueJobContext::clear();
        });
    }
}
