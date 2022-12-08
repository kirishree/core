<?php

/**
 * Created by code.
 * User: ureyni
 * Date: 14.07.2022
 * Time: 22:18
 */

namespace OPNsense\Sensei;

class SenseiLogger
{
    const EMERGENCY    = 0;
    const CRITICAL    = 1;
    const ALERT    = 2;
    const ERROR    = 3;
    const WARNING =    4;
    const NOTICE =    5;
    const INFO  = 6;
    const DEBUG    = 7;
    const CUSTOM = 8;
    const LOG_LEVELS = array('EMERGENCY', 'CRITICAL', 'ALERT', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG', 'CUSTOM');
    public $logFileName;
    private $timeFormat = 'c';
    public function log($level = 6, $message = '')
    {
        $line = sprintf('[%s][%s] %s' . PHP_EOL, date($this->timeFormat), self::LOG_LEVELS[$level], $message);
        file_put_contents($this->logFileName, $line, FILE_APPEND);
    }
}
