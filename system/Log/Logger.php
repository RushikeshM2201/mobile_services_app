<?php

declare(strict_types=1);

namespace CodeIgniter\Log;

use CodeIgniter\Exceptions\RuntimeException;
use CodeIgniter\Log\Exceptions\LogException;
use CodeIgniter\Log\Handlers\HandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The CodeIgniter Logger
 */
class Logger implements LoggerInterface
{
    protected $logLevels = [
        'emergency' => 1,
        'alert'     => 2,
        'critical'  => 3,
        'error'     => 4,
        'warning'   => 5,
        'notice'    => 6,
        'info'      => 7,
        'debug'     => 8,
    ];

    protected $loggableLevels = [];
    protected $filePermissions = 0644;
    protected $dateFormat = 'Y-m-d H:i:s';
    protected $fileExt;
    protected $handlers = [];
    protected $handlerConfig = [];
    public $logCache;
    protected $cacheLogs = false;

    public function __construct($config, bool $debug = CI_DEBUG)
    {
        $this->loggableLevels = is_array($config->threshold)
            ? $config->threshold
            : range(1, (int) $config->threshold);

        if ($this->loggableLevels !== []) {
            $temp = [];
            foreach ($this->loggableLevels as $level) {
                $temp[] = array_search((int) $level, $this->logLevels, true);
            }
            $this->loggableLevels = $temp;
            unset($temp);
        }

        $this->dateFormat = $config->dateFormat ?? $this->dateFormat;

        if (! is_array($config->handlers) || $config->handlers === []) {
            throw LogException::forNoHandlers('LoggerConfig');
        }

        $this->handlerConfig = $config->handlers;
        $this->cacheLogs     = $debug;

        if ($this->cacheLogs) {
            $this->logCache = [];
        }
    }

    // ---------------- LoggerInterface required methods ---------------- //

    public function emergency($message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log($level, $message, array $context = []): void
    {
        if (is_numeric($level)) {
            $level = array_search((int) $level, $this->logLevels, true);
        }

        if (! array_key_exists($level, $this->logLevels)) {
            throw LogException::forInvalidLogLevel($level);
        }

        if (! in_array($level, $this->loggableLevels, true)) {
            return;
        }

        $message = $this->interpolate($message, $context);

        if ($this->cacheLogs) {
            $this->logCache[] = [
                'level' => $level,
                'msg'   => $message,
            ];
        }

        foreach ($this->handlerConfig as $className => $config) {
            if (! array_key_exists($className, $this->handlers)) {
                $this->handlers[$className] = new $className($config);
            }

            $handler = $this->handlers[$className];

            if (! $handler->canHandle($level)) {
                continue;
            }

            if (! $handler->setDateFormat($this->dateFormat)->handle($level, $message)) {
                break;
            }
        }
    }

    // ---------------- Helper methods ---------------- //

    protected function interpolate($message, array $context = [])
    {
        if (! is_string($message)) {
            return print_r($message, true);
        }

        $replace = [];
        foreach ($context as $key => $val) {
            if ($key === 'exception' && $val instanceof Throwable) {
                $val = $val->getMessage() . ' ' . clean_path($val->getFile()) . ':' . $val->getLine();
            }
            $replace['{' . $key . '}'] = $val;
        }

        $replace['{post_vars}'] = '$_POST: ' . print_r($_POST, true);
        $replace['{get_vars}']  = '$_GET: ' . print_r($_GET, true);
        $replace['{env}']       = ENVIRONMENT;

        if (str_contains($message, '{file}') || str_contains($message, '{line}')) {
            [$file, $line] = $this->determineFile();
            $replace['{file}'] = $file;
            $replace['{line}'] = $line;
        }

        if (str_contains($message, 'env:')) {
            preg_match('/env:[^}]+/', $message, $matches);
            foreach ($matches as $str) {
                $key                 = str_replace('env:', '', $str);
                $replace["{{$str}}"] = $_ENV[$key] ?? 'n/a';
            }
        }

        if (isset($_SESSION)) {
            $replace['{session_vars}'] = '$_SESSION: ' . print_r($_SESSION, true);
        }

        return strtr($message, $replace);
    }

    public function determineFile(): array
    {
        $logFunctions = [
            'log_message',
            'log',
            'error',
            'debug',
            'info',
            'warning',
            'critical',
            'emergency',
            'alert',
            'notice',
        ];

        $trace        = \debug_backtrace(0);
        $stackFrames  = \array_reverse($trace);

        foreach ($stackFrames as $frame) {
            if (\in_array($frame['function'], $logFunctions, true)) {
                $file = isset($frame['file']) ? clean_path($frame['file']) : 'unknown';
                $line = $frame['line'] ?? 'unknown';
                return [$file, $line];
            }
        }

        return ['unknown', 'unknown'];
    }
}
