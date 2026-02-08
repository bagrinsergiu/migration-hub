# Migration Hub — обзор проекта

## Назначение
**MB Migration Dashboard (migration-hub)** — отдельный дашборд для системы миграции MB. Управление миграциями, волнами (waves), ревью, интеграция с Google Sheets. Доступ: http://localhost:8088.

## Технологический стек
- **Backend:** PHP 8.3, Composer; Symfony HttpFoundation, Dotenv, Monolog; Google API Client; vlucas/phpdotenv.
- **Frontend:** React 18, TypeScript, Vite; ESLint (@typescript-eslint); axios, react-router-dom, date-fns, clsx.
- **БД:** MySQL 5.7+ / 8.0.
- **Деплой:** Docker Compose (порт 8088), опционально GitHub Actions (скрипт `scripts/deploy.sh`).

## Namespaces и автозагрузка (Composer)
- `Dashboard\` → `src/` (контроллеры, сервисы, Core, middleware).
- `MBMigration\` → `lib/MBMigration/` (адаптеры: Analysis, Builder, Core, Layer/Brizy, Layer/DataSource).

## Конфигурация
- **Окружение:** `.env` в корне (MG_DB_*, MIGRATION_API_URL, APP_ENV, APP_DEBUG, GOOGLE_*). Подробнее: `doc/ENV_VARIABLES.md`.
- **Дашборд:** `var/config/dashboard_settings.json`.

## Документация
- `doc/` — ENV_VARIABLES, CI_CD_SETUP, MIGRATION_*, GOOGLE_SHEETS_SETUP, QUICK_DEPLOY, TESTING_WEBHOOKS и др.
- `doc/ONBOARDING.md` — краткий онбординг; `tasks/google-sheets-integration/` — таски и статусы по интеграции Google Sheets.
