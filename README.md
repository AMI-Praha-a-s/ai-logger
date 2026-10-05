# AI Logger

## Repository
**ami-praha/ai-logger**  
**Description:** A Laravel package providing a logging channel that sends log messages to an external webhook.

## Overview
This package adds a custom Laravel logging channel called `ai-logger`. When used, it sends log messages (and context data) as JSON payloads to a configured webhook URL.

## Installation
Require the package via Composer:

```sh
composer require ami-praha/ai-logger:"0.0.*"
```

No further configuration file publishing is necessary.

## Configuration
### Environment Variable
Set the webhook URL in your application's `.env` file:

```ini
AI_LOGGER_WEBHOOK_URL="http://ai-logger.test/api/receive"
AI_LOGGER_SOURCE_CODE="EXAMPLEAPP"
AI_LOGGER_SOURCE_NAME="Example App"
AI_LOGGER_SOURCE_URL="http://example-app.test"
AI_LOGGER_SOURCE_GIT_PROJECT_OWNER="EXP"
AI_LOGGER_SOURCE_GIT_PROJECT_NAME="example_app"
AI_LOGGER_SOURCE_JIRA_PROJECT_CODE="EXPAPP"
AI_LOGGER_SOURCE_JIRA_PARENT_PROJECT_KEY="EXPAPP-123"
```

### Configuring Logging Channel
In Laravel's `config/logging.php`, add a new channel `ai-logger`. Also if you are using a `stack` channel and want to automatically send any logs to `ai-logger` (recommended), add it to `stack.channels` array:

```php
// config/logging.php
return [
    'default' => env('LOG_CHANNEL', 'stack'),

    'channels' => [
        // other channels...

        'stack' => [
            'driver' => 'stack',
            'channels' => ['single', 'slack', 'ai-logger'],
        ],

        'ai-logger' => [
            'driver' => 'ai-logger',
            'webhookUrl' => env('AI_LOGGER_WEBHOOK_URL'),
            'sourceCode' => env('AI_LOGGER_SOURCE_CODE'),
            'sourceName' => env('AI_LOGGER_SOURCE_NAME'),
            'sourceUrl' => env('AI_LOGGER_SOURCE_URL'),
            'sourceGitProjectOwner' => env('AI_LOGGER_SOURCE_GIT_PROJECT_OWNER'),
            'sourceGitProjectName' => env('AI_LOGGER_SOURCE_GIT_PROJECT_NAME'),
            'sourceJiraProjectCode' => env('AI_LOGGER_SOURCE_JIRA_PROJECT_CODE'),
            'sourceJiraParentProjectKey' => env('AI_LOGGER_SOURCE_JIRA_PARENT_PROJECT_KEY'),
            'codeSnippets' => env('AI_LOGGER_CODE_SNIPPETS', true), // optional, see "Exceptions" below
            'level' => 'warning',
        ],
    ],
];
```

## Usage
To log using AI Logger, specify the `ai-logger` channel in your code:

```php
use Illuminate\Support\Facades\Log;

Log::channel('ai-logger')->info('Hello from AI Logger', ['extra_data' => 'test']);

// Other log levels work similarly
Log::channel('ai-logger')->error('Something went wrong.', [
    'exception' => 'Some exception info',
]);
```

This will send the log message, context data and log level as a JSON payload to the configured webhook URL. The time the log was written is sent as `debug_data.logged_at` (ISO 8601 with microseconds), and data of Laravel's `Context` (or of any Monolog processor of the channel) as `debug_data.extra`.

### Exceptions
An exception logged as `context.exception` (as Laravel's exception handler does) is sent with its type, message, code, file, line and stack trace, and also with:

- `previous` – the exception that caused it, nested the same way (each with `type`, `message`, `code`, `file`, `line` and its own `previous`), up to 5 levels deep.
- `snippets` – the code around the line the exception and its causes were thrown on and around the lines of the first stack frames, for up to 4 locations in the application's own files (never `vendor/` or `node_modules/`). Each one is `file`, `line` and `code`, the numbered lines with the line itself marked by `>`. Set `codeSnippets` to `false` not to send any code.

The stack trace is cut to its first 100 frames; `trace_omitted_frames` then says how many were left out.

Anything else logged as `context.exception` is sent as it is.

### Console and queue context
Logs written from artisan commands and queued jobs describe where they came from instead of a fake request:

- `debug_data.command` – the console command line, e.g. `artisan reports:send --month=9`. Values of secret-looking options (`--password=…`, `--api-token …`, `--*secret*`, `--*key*`, …) are replaced with `***`.
- `debug_data.queue_job` – the queued job being run: `name` (job class), `queue`, `connection`, `attempt`, `max_tries` and `uuid`. It is also sent for jobs run synchronously during an HTTP request.
- In console, `debug_data.url`, `debug_data.method` and the `ip` / `user_agent` of `user_tracking_data` are left out, since Laravel only fakes them from `APP_URL`.
- `performance_data.execution_time` of a log written during a queued job is measured from the start of the job, not of the worker.

## Verifying Functionality
1. **Set up a Test Webhook**: Use tools like [Webhook.site](https://webhook.site) or RequestBin to create a temporary webhook endpoint.
2. **Trigger a Log**: Execute a log statement:
   
   ```php
   Log::channel('ai-logger')->info('Testing AI Logger');
   ```

3. **Inspect Received Payload**: Confirm the JSON payload arrives at your test webhook.

## Requirements
- **PHP:** `^8.2`
- **Laravel:** `^10.0 | ^11.0 | ^12.0 | ^13.0`

## Contributing
Contributions are welcome!

1. Fork and create a feature branch.
2. Submit your changes through a Pull Request.
3. Provide a clear description of your updates.

For bugs or feature requests, please create an issue on GitHub.

## License
Licensed under the **MIT License**.

---
Thank you for using **AI Logger**! If you have questions, open an issue on GitHub or contact the maintainer.