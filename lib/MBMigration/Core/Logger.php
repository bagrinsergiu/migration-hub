<?php

namespace MBMigration\Core;

use Exception;
use Monolog\Handler\StreamHandler;
use Psr\Log\LoggerInterface;

class Logger extends \Monolog\Logger
{
    static private LoggerInterface $instance;
    /**
     * @var mixed|null
     */
    private static $path;
    /**
     * @var mixed
     */
    private static $logLevel;

    static public function initialize($name, $logLevel = null, $path = null): LoggerInterface
    {
        if(!empty(self::$logLevel)){
            $logLevel = self::$logLevel;
        } else {
            self::$logLevel = $logLevel;
        }

        if(!empty(self::$path)) {
            $path = self::$path;
        } else {
            self::$path = $path;
        }

        self::$instance = new self($name);
        self::$instance->pushHandler(new StreamHandler($path, $logLevel));

        return self::$instance;
    }

    static public function instance(): LoggerInterface
    {
        if (!isset(self::$instance)) {
            throw new Exception('Please initialize logger first.');
        }

        return self::$instance;
    }

    /**
     * Проверить, инициализирован ли Logger
     * Использует Reflection для безопасной проверки типизированного свойства
     * 
     * @return bool
     */
    static public function isInitialized(): bool
    {
        try {
            $reflection = new \ReflectionClass(self::class);
            $property = $reflection->getProperty('instance');
            $property->setAccessible(true);
            return $property->isInitialized();
        } catch (\ReflectionException $e) {
            return false;
        } catch (\Error $e) {
            // Typed property не инициализирован
            return false;
        }
    }
}
