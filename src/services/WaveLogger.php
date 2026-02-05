<?php

namespace Dashboard\Services;

/**
 * WaveLogger
 * 
 * Единый логгер для всех операций со страницей Wave
 * Все логи пишутся в один файл: var/log/wave_dashboard.log
 */
class WaveLogger
{
    /**
     * @var string Путь к файлу логов
     */
    private static $logFile = null;

    /**
     * Получить путь к файлу логов
     * 
     * @return string
     */
    private static function getLogFile(): string
    {
        if (self::$logFile === null) {
            $projectRoot = dirname(__DIR__, 3);
            $logDir = $projectRoot . '/var/log';
            
            // Создаем директорию, если не существует
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }
            
            self::$logFile = $logDir . '/wave_dashboard.log';
        }
        
        return self::$logFile;
    }

    /**
     * Записать лог
     * 
     * @param string $level Уровень лога (INFO, DEBUG, ERROR, WARNING)
     * @param string $message Сообщение
     * @param array $context Дополнительный контекст
     * @return void
     */
    private static function write(string $level, string $message, array $context = []): void
    {
        $logFile = self::getLogFile();
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logMessage = "[{$timestamp}] [{$level}] {$message}{$contextStr}\n";
        
        // Используем LOCK_EX для атомарной записи и избежания блокировок
        @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }

    /**
     * Логировать информационное сообщение
     * 
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    /**
     * Логировать отладочное сообщение
     * 
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function debug(string $message, array $context = []): void
    {
        self::write('DEBUG', $message, $context);
    }

    /**
     * Логировать ошибку
     * 
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    /**
     * Логировать предупреждение
     * 
     * @param string $message
     * @param array $context
     * @return void
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    /**
     * Логировать начало операции
     * 
     * @param string $operation Название операции
     * @param array $params Параметры операции
     * @return void
     */
    public static function startOperation(string $operation, array $params = []): void
    {
        self::info("=== START: {$operation} ===", $params);
    }

    /**
     * Логировать завершение операции
     * 
     * @param string $operation Название операции
     * @param array $result Результат операции
     * @return void
     */
    public static function endOperation(string $operation, array $result = []): void
    {
        self::info("=== END: {$operation} ===", $result);
    }
}
