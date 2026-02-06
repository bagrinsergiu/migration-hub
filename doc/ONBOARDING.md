# Migration Hub — онбординг проекта

## Назначение
**MB Migration Dashboard** — отдельный дашборд для системы миграции MB. Backend на PHP 8.3, фронтенд на React (TypeScript). Доступ: http://localhost:8088.

## Стек
- **Backend:** PHP 8.3, Composer; Symfony HttpFoundation, Dotenv, Monolog; Google API Client.
- **Frontend:** React, TypeScript (Vite), CSS в `frontend/src`.
- **БД:** MySQL 5.7+ / 8.0.
- **Деплой:** Docker Compose, опционально GitHub Actions.

## Namespaces и автозагрузка
- `Dashboard\` → `src/` (контроллеры, сервисы, Core, middleware).
- `MBMigration\` → `lib/MBMigration/` (адаптеры: Analysis, Builder, Core, Layer/Brizy, Layer/DataSource).

## Структура ключевых каталогов
- **src/** — PHP: `controllers/`, `services/`, `middleware/`, `Core/`, `scripts/` (CLI и SQL).
- **lib/MBMigration/** — общая библиотека: Config, Logger, Utils, BrizyAPI, MySQL driver, QualityReport, VariableCache.
- **frontend/src/** — React: `api/`, `components/`, `contexts/`, `hooks/`, `utils/`.
- **public/** — точка входа веб-сервера (`index.php` подключает `src/index.php`).
- **var/** — конфиг дашборда, логи, кэш.
- **doc/** — документация (ENV, CI/CD, миграции, вебхуки, Google Sheets и т.д.).

## Точка входа API
- Запросы к API идут в `public/index.php` → подключается `src/index.php`.
- Роутинг и диспетчеризация — в `src/index.php` (маршруты и вызовы контроллеров).

## Основные сервисы и контроллеры
- **Migration:** MigrationController, MigrationService, MigrationExecutionService — миграции и их запуск.
- **Waves:** WaveController, WaveService, WaveLogger, WaveReviewService — волны миграций и ревью.
- **Google Sheets:** GoogleSheetsController, GoogleSheetsService, GoogleSheetsSyncService — интеграция с таблицами, синхронизация.
- **Auth:** AuthController, AuthMiddleware, AuthService, UserService, UserController.
- **Прочее:** DatabaseService, BrizyApiService, ApiProxyService, QualityAnalysisService, ScreenshotService, WebhookController, SettingsController, LogController.

## Конфигурация и окружение
- `.env` в корне (MG_DB_*, MIGRATION_API_URL, APP_ENV, APP_DEBUG и др.). Подробнее: `doc/ENV_VARIABLES.md`.
- `var/config/dashboard_settings.json` — настройки дашборда.

## Запуск
- **Docker:** `docker-compose up -d`, затем `composer install`, сборка фронта: `cd frontend && npm install && npm run build`.
- **Локально:** `php -S localhost:8088 -t public`; фронт отдельно (npm run dev в frontend при наличии).

## Текущий контекст (интеграция Google Sheets)
- Ветка/задачи: `tasks/google-sheets-integration/` — таски TASK-001 … TASK-013, планирование, статусы, тесты.
- Новые/изменённые файлы: GoogleSheetsController, GoogleSheetsService, GoogleSheetsSyncService, скрипты `google_sheets_sync.php`, `test_google_sheets_parsing.php`, `test_sync_debug.php`, миграции БД для Google Sheets.

## Соглашения
- Контроллеры в `src/controllers/`, сервисы в `src/services/`.
- Фронт: компоненты в `frontend/src/components/`, API-клиент в `frontend/src/api/client.ts`.
- Стиль кода: следовать существующим паттернам в проекте (PHP PSR-4, React/TS в frontend).
