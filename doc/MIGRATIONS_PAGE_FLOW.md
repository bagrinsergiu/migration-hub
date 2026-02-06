# Флоу страницы миграций (Migrations)

Ожидаемый поток данных по странице миграций: список → детали → регистрация вебхука → опрос статуса.

---

## 1. Список миграций (`/migrations`)

- **API:** `GET /api/migrations`
- **Контроллер:** `MigrationController::list`
- **Данные:** `MigrationService::getMigrationsList()` — объединение `migrations_mapping` и `migration_result_list`; статус из `determineStatus()` (mapping + result).
- **Фронт:** `MigrationsList.tsx` — `api.getMigrations()`, фильтры по статусу / uuid / brz_project_id.

---

## 2. Детали миграции (`/migrations/:id`)

**Важно:** `id` в URL — это **brz_project_id** (как в списке миграций).

- **API:** `GET /api/migrations/:id`
- **Контроллер:** `MigrationController::getDetails`
- **Данные:** `MigrationService::getMigrationDetails($id)`:
  - маппинг из `migrations_mapping` по `brz_project_id`;
  - результат из `migration_result_list` по `mb_project_uuid`;
  - при статусе `in_progress` и отсутствии процесса — `checkAndUpdateStaleStatus()` (обновление по логам / lock-файлу);
  - итоговый статус — `determineStatus(result, mapping)`.
- **Фронт:** `MigrationDetails.tsx` — при открытии: `loadDetails()` (GET details), `loadProcessInfo()`, `loadWebhookInfo()`.

---

## 3. Регистрация вебхука

Вебхук **не** регистрируется отдельным запросом. Он передаётся при **запуске** миграции:

- **Запуск миграции:** `POST /api/migrations/run` или `POST /api/migrations/:id/restart` (или перезапуск из волны).
- **Сервис:** `MigrationExecutionService::executeMigration()` / `executeBatch()` формирует URL вебхука и передаёт его серверу миграции:
  - `webhook_url` = `{DASHBOARD_BASE_URL}/api/webhooks/migration-result`
  - `webhook_mb_project_uuid`, `webhook_brz_project_id`
- Сервер миграции по завершении сам вызывает этот URL (POST).

---

## 4. Приём результата (вебхук)

- **API:** `POST /api/webhooks/migration-result`
- **Контроллер:** `WebhookController::migrationResult`
- **Действия:**
  - обновление `migrations_mapping` через `upsertMigrationMapping()` (статус, completed_at, error, progress и т.д.);
  - сохранение результата в `migration_result_list` через `saveMigrationResult()`.
- После этого актуальный статус миграции хранится в БД и отдаётся через `GET /api/migrations/:id`.

---

## 5. Опрос статуса текущей миграции

Источник истины для UI — **БД**, обновляемая вебхуком (и при необходимости `checkAndUpdateStaleStatus`).

- **На странице деталей** при активной миграции (`in_progress` или процесс запущен):
  - каждые **3 секунды**: `refreshDetails()` → `GET /api/migrations/:id` (те же детали + статус из БД) и `loadProcessInfo()` → `GET /api/migrations/:id/process` (lock-файл, процесс).
- **Опционально:** в блоке «Вебхук» при загрузке один раз запрашивается `GET /api/migrations/:id/webhook-info`, который внутри может вызвать опрос сервера миграции (`ApiProxyService::getMigrationStatusFromServer`) и вернуть `server_status` для отладки. Для основного отображения статуса это не обязательно — достаточно опроса БД через `GET /api/migrations/:id`.

---

## Сводка эндпоинтов, используемых страницей миграций

| Эндпоинт | Назначение |
|----------|------------|
| `GET /api/migrations` | Список миграций (статус из БД). |
| `GET /api/migrations/:id` | Детали и текущий статус миграции (основной опрос). |
| `GET /api/migrations/:id/process` | Статус процесса: приоритет — опрос API сервера миграции (`/migration-status`); при недоступности — fallback на lock-файл и локальный процесс. При ответе сервера «completed»/«error» дашборд синхронизирует БД. |
| `GET /api/migrations/:id/webhook-info` | URL вебхука, факт прихода, при желании — статус с сервера. |
| `POST /api/webhooks/migration-result` | Приём результата от сервера миграции (обновление БД). |

Запуск/перезапуск: `POST /api/migrations/run`, `POST /api/migrations/:id/restart`, а также перезапуск из волны. В этих запросах серверу миграции передаётся `webhook_url` — отдельная «регистрация» вебхука не выполняется.

**Опционально (отладка):** `GET /api/migrations/:id/status-from-server` — прямой опрос статуса с сервера миграции. На странице не используется для основного флоу; `webhook-info` при необходимости сам запрашивает сервер и возвращает `server_status`.
