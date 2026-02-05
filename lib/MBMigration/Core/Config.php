<?php

namespace MBMigration\Core;

/**
 * Config - Минимальный адаптер для dashboard
 * 
 * Этот класс предоставляет только необходимые статические свойства
 * для работы dashboard без полной конфигурации основного проекта
 */
class Config
{
    public static $debugMode = false;
    public static $endPointApi = [];
    public static $endPointVersion = '/2.0';
    public static $urlAPI = '';
    public static $urlProjectAPI = '';
    public static $urlGetApiToken = '';
    public static $urlGraphqlAPI = '';
    public static $pathTmp = '';
    public static $pathLogFile = 'php://stdout';
    public static $mainToken = '';
    public static $graphqlToken = '';
    public static $DBConnection = null;
    public static array $configPostgreSQL = [];
    public static array $configMySQL = [];
    public static array $mgConfigMySQL = [];
    public static string $nameMigration = 'Migration';
    public static $cloud_host = '';
    public static array $metaData = [];
    public static bool $devMode = false;
    public static $urlJsonKits = false;
    public static string $previewBaseHost = '';
    public static $MBMediaStaging = false;
    public static string $path = '';
    public static array $designInDevelop = [];
    public static string $cachePath = '';
    public static $MB_MONKCMS_API = false;
    public static bool $mgrMode = false;

    /**
     * Инициализация Config из переменных окружения
     * 
     * @return void
     */
    public static function initializeFromEnv(): void
    {
        // Базовые настройки из .env
        self::$mainToken = $_ENV['BRIZY_TOKEN'] ?? getenv('BRIZY_TOKEN') ?? '';
        // Поддерживаем оба варианта переменных окружения для обратной совместимости
        self::$cloud_host = $_ENV['BRIZY_HOST'] ?? getenv('BRIZY_HOST') 
            ?? $_ENV['BRIZY_CLOUD_HOST'] ?? getenv('BRIZY_CLOUD_HOST') 
            ?? 'https://admin.brizy.io';
        
        // URL настройки
        self::$urlAPI = self::$cloud_host . '/api';
        self::$urlProjectAPI = self::$urlAPI . '/projects/{project}';
        self::$urlGetApiToken = self::$cloud_host . '/api/projects/{project}/token';
        self::$urlGraphqlAPI = self::$cloud_host . '/graphql/{ProjectId}';
        
        // Пути
        self::$path = dirname(__DIR__, 4); // Корень проекта
        self::$pathTmp = self::$path . '/var/tmp/';
        self::$cachePath = self::$path . '/var/cache/';
        
        // Режимы
        $appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'production';
        self::$debugMode = ($appEnv === 'development' || ($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG')) === 'true');
        self::$devMode = self::$debugMode;
        
        // Endpoints
        self::$endPointApi = [
            'clearcompileds' => '/clearcompileds',
            'globalBlocks' => '/global_blocks',
            'team_members' => '/team_members',
            'menus/create' => '/menus/create',
            'workspaces' => '/workspaces',
            'projects' => '/projects',
            'customicons' => '/customicons',
            'domain' => '/domain',
            'users' => '/users',
            'pages' => '/pages',
            'media' => '/media',
            'fonts' => '/fonts',
        ];
    }

    /**
     * Получить значение настройки разработки
     * 
     * @param string $key
     * @return mixed
     */
    public static function getDevOptions(string $key)
    {
        return false; // По умолчанию все опции разработки отключены
    }
}
