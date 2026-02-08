# Трассировка запуска миграции

Чтобы понять, на каком этапе запрос на старт миграции не выполняется, в коде добавлено логирование с префиксом `[MIG]`. Логи пишутся в stderr (PHP `error_log`), т.е. в лог веб-сервера / PHP-FPM / Docker.

## Как смотреть логи

- **Docker:** `docker logs mb_dashboard 2>&1 | grep '\[MIG\]'`
- **Локально (PHP-FPM):** смотреть лог ошибок PHP (например `/var/log/php-fpm/error.log` или значение `error_log` в php.ini)
- **Один запрос:** после нажатия «Запустить»/«Перезапустить» отфильтровать по времени:  
  `grep '[MIG]' /path/to/error.log | tail -50`

## Цепочка при POST /api/migrations/run (форма «Запустить миграцию»)

1. `[MIG] MigrationController::run — входящий запрос POST /api/migrations/run`
2. `[MIG] MigrationController::run — тело запроса (mb_secret скрыт): {...}`
3. `[MIG] MigrationController::run — валидация пройдена, вызываем MigrationService::runMigration`
4. `[MIG] MigrationService::runMigration — вход: mb_project_uuid=..., brz_project_id=...`
5. `[MIG] MigrationService::runMigration — вызываем ApiProxyService::runMigration`
6. `[MIG] ApiProxyService::runMigration — START, baseUrl=...`
7. `[MIG] ApiProxyService::runMigration — проверка health: .../health` (если не отключен)
8. `[MIG] ApiProxyService::runMigration — этап: health check неуспешен` **или** `health check OK`
9. `[ApiProxyService::runMigration] Отправка HTTP запроса на сервер миграции: ...` (уже было)
10. `[MIG] ApiProxyService::runMigration — curl выполнен: HTTP XXX, curl_error=...`
11. Далее один из вариантов:
    - `[MIG] ApiProxyService::runMigration — этап: запрос выполнен успешно, HTTP 2xx`
    - `[MIG] ApiProxyService::runMigration — этап: запрос к серверу миграции завершился с ошибкой curl: ...`
    - `[MIG] ApiProxyService::runMigration — этап: сервер миграции вернул ошибку HTTP XXX`
12. `[MIG] MigrationService::runMigration — ответ прокси: success=...`
13. `[MIG] MigrationController::run — результат runMigration: success=..., http_code=...`
14. `[MIG] MigrationController::run — ответ клиенту: HTTP XXX`

## Цепочка при POST /api/migrations/:id/restart (перезапуск с страницы миграции)

Тот же путь через `MigrationService::runMigration` → `ApiProxyService::runMigration`. Начало:

1. `[MIG] MigrationController::restart — входящий запрос POST /api/migrations/{id}/restart`
2. Далее при вызове `runMigration` — те же шаги 4–14 выше.

## Цепочка при перезапуске из волны (POST /api/waves/:id/migrations/:mb_uuid/restart)

1. `[MIG] WaveService::restartMigrationInWave — START waveId=..., mbUuid=...`
2. `[MIG] WaveService::restartMigrationInWave — вызываем MigrationExecutionService::executeMigration`
3. `[MIG] MigrationExecutionService::executeMigration — START, url(secret masked): ...`
4. `[MIG] MigrationExecutionService::executeMigration — curl выполнен: HTTP ..., curl_error=...`
5. При ошибке: `[MIG] MigrationExecutionService::executeMigration — этап: ошибка при запросе к серверу миграции: ...`
6. `[MIG] WaveService::restartMigrationInWave — executeMigration вернул: success=..., http_code=...`

**Важно:** при перезапуске из волны используется **свой** URL сервера миграции — из `MigrationExecutionService` (переменная `MIGRATION_API_URL`). Если в Docker не задан `MIGRATION_API_URL`, там может подставляться `http://127.0.0.1:80` внутри контейнера — убедитесь, что в `.env` задан правильный адрес (например `http://172.20.0.1:8080`).

## На что смотреть

- **Ошибка до «вызываем ApiProxyService»** — проблема в контроллере/сервисе (валидация, настройки, БД).
- **«health check неуспешен»** — сервер миграции по `MIGRATION_API_URL` не отвечает на `/health` (не запущен, неверный хост/порт, сеть).
- **«curl выполнен: HTTP 0» или «curl_error=...»** — запрос до сервера не доходит: таймаут, connection refused, no route to host и т.п.
- **«сервер миграции вернул ошибку HTTP 4xx/5xx»** — запрос дошёл, но сервер вернул ошибку; в логе может быть фрагмент тела ответа.
