<?php
/**
 * Exceptions and Logger for RancherFleet module.
 */

namespace RancherFleet;

// ---------------------------------------------------------------------------
// Exceptions — carry HTTP code + raw API response body
// ---------------------------------------------------------------------------

class RancherApiException extends \RuntimeException
{
    private $httpCode;
    private $rawBody;

    public function __construct($message, $httpCode = 0, $rawBody = '', $previous = null)
    {
        $this->httpCode = $httpCode;
        $this->rawBody  = $rawBody;
        parent::__construct($message, 0, $previous);
    }

    public function getHttpCode()
    {
        return $this->httpCode;
    }

    public function getRawBody()
    {
        return $this->rawBody;
    }
}

class GitHubApiException extends \RuntimeException
{
    private $httpCode;
    private $rawBody;

    public function __construct($message, $httpCode = 0, $rawBody = '', $previous = null)
    {
        $this->httpCode = $httpCode;
        $this->rawBody  = $rawBody;
        parent::__construct($message, 0, $previous);
    }

    public function getHttpCode()
    {
        return $this->httpCode;
    }

    public function getRawBody()
    {
        return $this->rawBody;
    }
}

// ---------------------------------------------------------------------------
// Logger
// ---------------------------------------------------------------------------

class Logger
{
    private static function logPath()
    {
        if (defined('ROOTDIR')) {
            return ROOTDIR . '/modules/servers/rancherfleet/rancherfleet.log';
        }
        return sys_get_temp_dir() . '/rancherfleet.log';
    }

    public static function info($message)
    {
        self::write('INFO', $message);
    }

    public static function error($message)
    {
        self::write('ERROR', $message);
    }

    private static function write($level, $message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $line      = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;

        @file_put_contents(self::logPath(), $line, FILE_APPEND | LOCK_EX);

        if (function_exists('logActivity')) {
            logActivity('RancherFleet [' . $level . ']: ' . $message);
        }
    }
}
