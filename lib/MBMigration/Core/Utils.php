<?php

namespace MBMigration\Core;

use MBMigration\Builder\VariableCache;
use Exception;

class Utils
{
    /** @var VariableCache */
    public static $cache;

    /**
     * Проверка значения и выброс исключения, если значение пустое
     * 
     * @param mixed $value
     * @param string $message
     * @return mixed
     * @throws Exception
     */
    protected function check($value, string $message = 'Value is required')
    {
        if (empty($value)) {
            throw new Exception($message);
        }
        return $value;
    }

    /**
     * Замена подстроки в строке
     * 
     * @param string $subject
     * @param string $search
     * @param string $replace
     * @return string
     */
    public static function strReplace(string $subject, string $search, string $replace): string
    {
        return str_replace($search, $replace, $subject);
    }

    /**
     * Генерация хеша имени
     * 
     * @param string $name
     * @return string
     */
    protected function getNameHash(string $name): string
    {
        return md5($name);
    }

    /**
     * Генерация случайного ID из символов
     * 
     * @param int $length
     * @return string
     */
    public static function generateCharID(int $length = 12): string
    {
        $characters = 'abcdefghijklmnopqrstuvwxyz';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    /**
     * Инициализация кеша
     */
    public static function initializeCache(): void
    {
        if (self::$cache === null) {
            self::$cache = VariableCache::getInstance();
        }
    }

    /**
     * Получить кеш (с автоматической инициализацией)
     * 
     * @return VariableCache
     */
    public static function getCache(): VariableCache
    {
        if (self::$cache === null) {
            self::$cache = VariableCache::getInstance();
        }
        return self::$cache;
    }
}
