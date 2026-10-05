<?php

namespace AmiPraha\AILogger;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Throwable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\App;

class AILogger extends AbstractProcessingHandler
{
    /**
     * Code snippets are sent for this many locations of an exception, around this many lines before and after.
     */
    protected const MAX_CODE_SNIPPETS = 4;
    protected const CODE_SNIPPET_RADIUS = 8;
    protected const MAX_CODE_SNIPPET_LINE_LENGTH = 300;
    protected const MAX_CODE_SNIPPET_FILE_SIZE = 1024 * 1024;

    protected const MAX_PREVIOUS_EXCEPTIONS = 5;

    /**
     * Only the first frames of a stack trace are sent, the ai-logger app identifies an exception by its top frames.
     */
    protected const MAX_TRACE_FRAMES = 100;

    protected ?string $webhookUrl;
    protected ?string $sourceCode;
    protected ?string $sourceName;
    protected ?string $sourceUrl;
    protected ?string $sourceGitProjectOwner;
    protected ?string $sourceGitProjectName;
    protected ?string $sourceJiraProjectCode;
    protected ?string $sourceJiraParentProjectKey;
    protected bool $codeSnippets;

    public function __construct(
        ?string $webhookUrl = null,
        ?string $sourceCode = null,
        ?string $sourceName = null,
        ?string $sourceUrl = null,
        ?string $sourceGitProjectOwner = null,
        ?string $sourceGitProjectName = null,
        ?string $sourceJiraProjectCode = null,
        ?string $sourceJiraParentProjectKey = null,
        int $level = 100, // debug
        bool $bubble = true,
        bool $codeSnippets = true
    ) {
        parent::__construct($level, $bubble);

        $this->webhookUrl = $webhookUrl;
        $this->sourceCode = $sourceCode;
        $this->sourceName = $sourceName;
        $this->sourceUrl = $sourceUrl;
        $this->sourceGitProjectOwner = $sourceGitProjectOwner;
        $this->sourceGitProjectName = $sourceGitProjectName;
        $this->sourceJiraProjectCode = $sourceJiraProjectCode;
        $this->sourceJiraParentProjectKey = $sourceJiraParentProjectKey;
        $this->codeSnippets = $codeSnippets;
    }

    /**
     * Writes the log to the external webhook.
     *
     * @param LogRecord $record
     * @return void
     */
    protected function write(LogRecord $record): void
    {
        try {
            if (empty($this->webhookUrl) || empty($this->sourceCode) || empty($this->sourceName) || empty($this->sourceUrl)) {
                $this->logInternalError('webhookUrl, sourceCode, sourceName, or sourceUrl is missing, please add all of them to your config. See ai-logger documentation for more info.');

                return;
            }

            $payload = [
                'level'     => $record->level->getName(),
                'message'   => $record->message,
                'context'   => $record->context,
                'debug_data' => [
                    'url' => $this->getRequestUrl(),
                    'method' => $this->getRequestMethod(),
                    'route_name' => $this->getRouteName(),
                    'route_action' => $this->getRouteAction(),
                    'is_console' => $this->isRunningInConsole(),
                    'environment' => $this->getEnvironment(),
                    'command' => $this->getConsoleCommand(),
                    'queue_job' => QueueJobContext::job(),
                    'logged_at' => $record->datetime->format('Y-m-d\TH:i:s.uP'),
                ],
                'performance_data' => [
                    'memory_usage' => [
                        'value' => round(memory_get_usage(true) / 1024 / 1024, 2),
                        'unit' => 'MB'
                    ],
                    'peak_memory' => [
                        'value' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                        'unit' => 'MB'
                    ],
                    'execution_time' => [
                        'value' => $this->getExecutionTime(),
                        'unit' => 'ms'
                    ],
                ],
                'user_tracking_data' => collect([
                    'logger_user_id'    => $this->getUserId(),
                    'logger_user_name'  => $this->getLoggerUserName(),
                    'logger_user_email' => $this->getUserEmail(),
                    'ip'                => $this->getClientIp(),
                    'user_agent'        => $this->getUserAgent(),
                    'referer'           => $this->getReferer(),
                ])->filter()->all(),
                'source' => [
                    'code' => $this->sourceCode,
                    'name' => $this->sourceName,
                    'url'  => $this->sourceUrl,
                    'git_project_owner' => $this->sourceGitProjectOwner,
                    'git_project_name' => $this->sourceGitProjectName,
                    'jira_project_code' => $this->sourceJiraProjectCode,
                    'jira_parent_project_key' => $this->sourceJiraParentProjectKey,
                ],
            ];

            // Data of Laravel's Context and of Monolog processors, last as the ai-logger app cuts debug_data from the end
            if (!empty($record->extra)) {
                $payload['debug_data']['extra'] = $record->extra;
            }

            // Anything else logged as "exception" is sent as it is
            if (($record->context['exception'] ?? null) instanceof Throwable) {
                $payload['context']['exception'] = $this->formatExceptionToArray($record->context['exception']);
            }

            // Invalid UTF-8 (e.g. in a code snippet) or a value json_encode() cannot handle must not lose the whole log
            $jsonPayload = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($jsonPayload === false) {
                $this->logInternalError('JSON encoding error - ' . json_last_error_msg());

                return;
            }

            $this->send($jsonPayload);
        } catch (Throwable $e) {
            $this->logInternalError('Fatal error: ' . $e->getMessage() . ' on line ' . $e->getLine() . ' in file' . $e->getFile());
        }
    }

    /**
     * Posts the JSON payload to the webhook.
     *
     * @param string $jsonPayload
     * @return void
     */
    protected function send(string $jsonPayload): void
    {
        $ch = curl_init($this->webhookUrl);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $this->logInternalError('cURL error - ' . curl_error($ch) . '. Probably invalid webhook URL.');
        } else {
            $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $decodedResponse = json_decode($response, true);

            if ($httpStatusCode < 200 || $httpStatusCode >= 300) {
                $this->logInternalError('HTTP error', [
                    'status_code' => $httpStatusCode,
                    'message' => $decodedResponse['message'] ?? 'Unknown error',
                    'errors'  => $decodedResponse['errors'] ?? null,
                    'raw_response' => $response,
                ]);
            } else {
                if (!isset($decodedResponse['status']) || $decodedResponse['status'] !== 'success') {
                    $this->logInternalError('Unexpected API response format or failed status', [
                        'response' => $decodedResponse,
                    ]);
                }
            }
        }

        curl_close($ch);
    }

    /**
     * Writes internal errors to a dedicated log file, bypassing Laravel's logging.
     *
     * @param string $message
     * @param array  $context
     * @return void
     */
    protected function logInternalError(string $message, array $context = []): void
    {
        try {
            $timestamp = date('Y-m-d H:i:s');
            $line = "[$timestamp] [AILogger INTERNAL ERROR] $message";

            if (!empty($context)) {
                $contextJson = json_encode($context);
                $line .= " | context: " . ($contextJson === false ? print_r($context, true) : $contextJson);
            }

            $line .= "\n";

            file_put_contents(App::storagePath('logs/ai_logger_critical.log'), $line, FILE_APPEND);
        } catch (Throwable $e) {
            // If we can't even log the error, there's not much we can do
            error_log("AILogger critical error: " . $e->getMessage());
        }
    }

    protected function formatExceptionToArray(Throwable $e): array
    {
        $frames = $e->getTrace();

        $exception = [
            'type' => $this->withoutAnonymousClassSuffix(get_class($e)),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => collect(array_slice($frames, 0, static::MAX_TRACE_FRAMES))->map(function ($trace) {
                $frame = Arr::only($trace, ['file', 'line', 'function', 'class', 'type']);

                if (isset($frame['class'])) {
                    $frame['class'] = $this->withoutAnonymousClassSuffix($frame['class']);
                }

                return $frame;
            })->all(),
        ];

        // Cut from the end, so the frames the ai-logger app groups exceptions by stay the same
        if (count($frames) > static::MAX_TRACE_FRAMES) {
            $exception['trace_omitted_frames'] = count($frames) - static::MAX_TRACE_FRAMES;
        }

        if ($this->codeSnippets) {
            $snippets = $this->getCodeSnippets($e);

            if (!empty($snippets)) {
                $exception['snippets'] = $snippets;
            }
        }

        if ($e->getPrevious() !== null) {
            $exception['previous'] = $this->formatPreviousException($e->getPrevious());
        }

        return $exception;
    }

    /**
     * The exception that caused another one, nested the way Monolog's normalizer does it.
     *
     * @param Throwable $e
     * @param int $depth
     * @return array
     */
    protected function formatPreviousException(Throwable $e, int $depth = 1): array
    {
        $exception = [
            'type' => $this->withoutAnonymousClassSuffix(get_class($e)),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];

        if ($e->getPrevious() !== null && $depth < static::MAX_PREVIOUS_EXCEPTIONS) {
            $exception['previous'] = $this->formatPreviousException($e->getPrevious(), $depth + 1);
        }

        return $exception;
    }

    /**
     * Anonymous classes are named "class@anonymous" followed by a NUL byte and their file, which PostgreSQL rejects.
     *
     * @param string $class
     * @return string
     */
    protected function withoutAnonymousClassSuffix(string $class): string
    {
        $position = strpos($class, "\0");

        return $position === false ? $class : substr($class, 0, $position);
    }

    /**
     * The code around the line the exception was thrown on, the lines its causes were thrown on
     * and the lines of the first application frames.
     *
     * @param Throwable $e
     * @return array
     */
    protected function getCodeSnippets(Throwable $e): array
    {
        try {
            $locations = [];

            for ($cause = $e, $depth = 0; $cause !== null && $depth <= static::MAX_PREVIOUS_EXCEPTIONS; $cause = $cause->getPrevious(), $depth++) {
                $locations[] = ['file' => $cause->getFile(), 'line' => $cause->getLine()];
            }

            foreach ($e->getTrace() as $frame) {
                if (isset($frame['file'], $frame['line'])) {
                    $locations[] = ['file' => $frame['file'], 'line' => $frame['line']];
                }
            }

            $snippets = [];
            $seen = [];

            foreach ($locations as $location) {
                if (count($snippets) >= static::MAX_CODE_SNIPPETS) {
                    break;
                }

                $key = $location['file'] . ':' . $location['line'];

                if (isset($seen[$key]) || !$this->isApplicationFile($location['file'])) {
                    continue;
                }

                $seen[$key] = true;
                $code = $this->readCodeAround($location['file'], (int) $location['line']);

                if ($code !== null) {
                    $snippets[] = ['file' => $location['file'], 'line' => (int) $location['line'], 'code' => $code];
                }
            }

            return $snippets;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Whether the file is the application's own code, by the rule the ai-logger app tells library frames apart with.
     *
     * @param string $file
     * @return bool
     */
    protected function isApplicationFile(string $file): bool
    {
        $path = str_replace('\\', '/', $file);

        if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
            return false;
        }

        return is_file($file) && is_readable($file) && filesize($file) <= static::MAX_CODE_SNIPPET_FILE_SIZE;
    }

    /**
     * The lines around $line, numbered, the line itself marked with ">". One string, as the ai-logger app
     * trims every string it receives, which would strip the indentation of separately sent lines.
     *
     * @param string $file
     * @param int $line
     * @return string|null
     */
    protected function readCodeAround(string $file, int $line): ?string
    {
        $source = file($file, FILE_IGNORE_NEW_LINES);

        if ($source === false || $line < 1 || $line > count($source)) {
            return null;
        }

        $first = max(1, $line - static::CODE_SNIPPET_RADIUS);
        $last = min(count($source), $line + static::CODE_SNIPPET_RADIUS);
        $width = strlen((string) $last);
        $lines = [];

        for ($number = $first; $number <= $last; $number++) {
            $code = rtrim(str_replace("\0", '', $source[$number - 1]));

            if (strlen($code) > static::MAX_CODE_SNIPPET_LINE_LENGTH) {
                $code = substr($code, 0, static::MAX_CODE_SNIPPET_LINE_LENGTH) . '…';
            }

            $lines[] = rtrim(str_pad((string) $number, $width) . ($number === $line ? ' > ' : ' | ') . $code);
        }

        return implode("\n", $lines);
    }

    protected function getRequestUrl(): ?string
    {
        try {
            // In console the request is a fake one made from APP_URL (GET, 127.0.0.1, "Symfony" user agent)
            if ($this->isRunningInConsole()) {
                return null;
            }

            return Request::fullUrl();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getRequestMethod(): ?string
    {
        try {
            if ($this->isRunningInConsole()) {
                return null;
            }

            return Request::method();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getRouteName(): ?string
    {
        try {
            return Request::route()?->getName();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getRouteAction(): ?string
    {
        try {
            return Request::route()?->getActionName();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function isRunningInConsole(): bool
    {
        try {
            return App::runningInConsole();
        } catch (Throwable $e) {
            return false;
        }
    }

    protected function getConsoleCommand(): ?string
    {
        try {
            $argv = $_SERVER['argv'] ?? [];

            if (!$this->isRunningInConsole() || empty($argv)) {
                return null;
            }

            return implode(' ', [basename((string) $argv[0]), ...$this->redactCommandArguments(array_slice($argv, 1))]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Replaces values of secret-looking options, given as "--password=secret" or "--password secret".
     *
     * @param array $arguments
     * @return array
     */
    protected function redactCommandArguments(array $arguments): array
    {
        $redacted = [];
        $redactNext = false;

        foreach ($arguments as $argument) {
            $argument = (string) $argument;

            if ($redactNext && !str_starts_with($argument, '-')) {
                $redacted[] = '***';
                $redactNext = false;

                continue;
            }

            $redactNext = false;

            if (preg_match('/^(--?[\w.-]*(?:pass|secret|token|key|auth|credential)[\w.-]*)(=.*)?$/i', $argument, $matches)) {
                if (isset($matches[2])) {
                    $argument = $matches[1] . '=***';
                } else {
                    $redactNext = true;
                }
            }

            $redacted[] = $argument;
        }

        return $redacted;
    }

    protected function getEnvironment(): ?string
    {
        try {
            return App::environment();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getExecutionTime(): float
    {
        try {
            // In a queue worker LARAVEL_START is the worker's start, so measure from the job's start
            $startTime = QueueJobContext::startedAt()
                ?? (defined('\LARAVEL_START') ? \LARAVEL_START : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)));
            return (microtime(true) - $startTime) * 1000;
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    protected function getUserId(): int|string|null
    {
        try {
            return Auth::id();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getUserEmail(): ?string
    {
        try {
            return Auth::user()?->email;
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getClientIp(): ?string
    {
        try {
            if ($this->isRunningInConsole()) {
                return null;
            }

            return Request::ip();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getUserAgent(): ?string
    {
        try {
            if ($this->isRunningInConsole()) {
                return null;
            }

            return Request::userAgent();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getReferer(): ?string
    {
        try {
            return Request::header('referer')
                ?? Request::header('referrer') 
                ?? $_SERVER['HTTP_REFERER'] 
                ?? $_SERVER['HTTP_REFERRER'] 
                ?? null;
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getLoggerUserName(): ?string
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return null;
            }

            $name = $user->name ?? null;

            if (empty($name)) {
                $firstName = $user->first_name ?? $user->firstname ?? null;
                $lastName = $user->last_name ?? $user->lastname ?? null;

                $name = implode(' ', array_filter([$firstName, $lastName]));
            }

            return !empty($name) ? $name : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
