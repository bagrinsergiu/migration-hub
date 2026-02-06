# Рекомендуемые команды (Linux)

## Запуск приложения
- **Docker:** `docker-compose up -d` — поднять контейнер; дашборд: http://localhost:8088
- **Установка зависимостей (Docker):** `docker-compose run --rm composer install`
- **Локальный PHP-сервер:** `php -S localhost:8088 -t public`
- **Сборка фронтенда:** `cd frontend && npm install && npm run build`
- **Режим разработки фронта:** `cd frontend && npm run dev` (Vite)

## Линтинг и проверки
- **Frontend (ESLint):** `cd frontend && npm run lint` — проверка .ts/.tsx (eslint с @typescript-eslint, max-warnings 0)
- **PHP:** В проекте не настроены phpcs/phpstan в composer; при необходимости запускать вручную (например `vendor/bin/phpstan analyse src` при установке)

## Сборка и превью
- **Frontend build:** `cd frontend && npm run build` (tsc && vite build)
- **Frontend preview:** `cd frontend && npm run preview`

## Системные утилиты
- `git`, `ls`, `cd`, `grep`, `find`, `curl` — стандартные Linux-команды
- Проверка API: `curl -s http://localhost:8088/api/health`

## Деплой
- **Ручной деплой:** `./scripts/deploy.sh user@server`
- CI/CD: при пуше в `main` — см. `doc/CI_CD_SETUP.md` и `.github/workflows/deploy.yml`

## Скрипты в проекте
- Синхронизация Google Sheets: `php src/scripts/google_sheets_sync.php` (запускать из корня или с указанием пути)
- Создание админа: `php src/scripts/create_admin_user.php`
- Другие CLI: см. `src/scripts/` (README_MONITOR.md, README_TESTS.md при необходимости)
