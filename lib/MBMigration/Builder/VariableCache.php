<?php

namespace MBMigration\Builder;

class VariableCache
{
    /** @var VariableCache|null */
    private static $instance = null;
    /** @var array */
    private $cache = [];

    private function __construct()
    {
    }

    /**
     * Получить экземпляр синглтона
     * 
     * @return VariableCache
     */
    public static function getInstance(): VariableCache
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Получить значение из кеша
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->cache[$key] ?? $default;
    }

    /**
     * Установить значение в кеш
     * 
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, $value): void
    {
        $this->cache[$key] = $value;
    }

    /**
     * Проверить наличие ключа в кеше
     * 
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->cache[$key]);
    }

    /**
     * Удалить значение из кеша
     * 
     * @param string $key
     * @return void
     */
    public function remove(string $key): void
    {
        unset($this->cache[$key]);
    }

    /**
     * Очистить весь кеш
     * 
     * @return void
     */
    public function clear(): void
    {
        $this->cache = [];
    }

    /**
     * Получить класс по имени (для обратной совместимости)
     * 
     * @param string $className
     * @return mixed
     */
    public function getClass(string $className)
    {
        // Базовая реализация - возвращаем null, так как это требует более сложной логики
        // В реальной реализации здесь должна быть логика создания классов
        return null;
    }
}
