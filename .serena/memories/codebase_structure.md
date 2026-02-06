# Структура кодовой базы

## Точки входа
- **Веб:** `public/index.php` → подключает `src/index.php`. Роутинг и диспетчеризация — в `src/index.php`.
- **CLI-скрипты:** `src/scripts/` (например `google_sheets_sync.php`, `create_admin_user.php`, миграции БД).

## Ключевые каталоги
| Путь | Назначение |
|------|------------|
| `src/` | PHP: `controllers/`, `services/`, `middleware/`, `Core/`, `scripts/` (CLI, SQL) |
| `lib/MBMigration/` | Общая библиотека: Config, Logger, Utils, BrizyAPI, MySQL driver, QualityReport, VariableCache |
| `frontend/src/` | React: `api/`, `components/`, `contexts/`, `hooks/`, `utils/` |
| `public/` | Точка входа веб-сервера |
| `var/` | Конфиг дашборда, логи, кэш |
| `doc/` | Документация |

## Основные сервисы и контроллеры
- **Миграции:** MigrationController, MigrationService, MigrationExecutionService.
- **Волны:** WaveController, WaveService, WaveLogger, WaveReviewService.
- **Google Sheets:** GoogleSheetsController, GoogleSheetsService, GoogleSheetsSyncService.
- **Auth:** AuthController, AuthMiddleware, AuthService, UserService, UserController.
- **Прочее:** DatabaseService, BrizyApiService, ApiProxyService, QualityAnalysisService, ScreenshotService, WebhookController, SettingsController, LogController.

## Frontend
- API-клиент: `frontend/src/api/client.ts`.
- Компоненты: `frontend/src/components/` (в т.ч. подпапка GoogleSheets/).
- Контексты: LanguageContext, ThemeContext, UserContext.
