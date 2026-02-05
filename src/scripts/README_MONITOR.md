# Монитор процессов миграции

## Описание

Монитор процессов миграции - это фоновый воркер, который постоянно отслеживает статусы процессов миграции по их PID и автоматически обновляет статусы в базе данных, если процесс был прерван или завершился некорректно.

## Возможности

- ✅ Автоматическое отслеживание всех активных миграций по PID
- ✅ Проверка статуса процессов каждые 10 секунд (настраивается)
- ✅ Автоматическое обновление статуса на `error`, если процесс не найден
- ✅ Автоматическая очистка lock-файлов для завершенных процессов
- ✅ Поддержка корректного завершения через сигналы (SIGTERM, SIGINT)

## Установка и запуск

### Ручной запуск

```bash
# Запуск монитора
cd /home/sg/projects/MB-migration
./dashboard/api/scripts/start_monitor.sh

# Остановка монитора
./dashboard/api/scripts/stop_monitor.sh

# Или напрямую через PHP
php dashboard/api/scripts/migration_monitor.php
```

### Запуск через systemd (рекомендуется для production)

Создайте файл `/etc/systemd/system/migration-monitor.service`:

```ini
[Unit]
Description=MB Migration Process Monitor
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/home/sg/projects/MB-migration
ExecStart=/usr/bin/php /home/sg/projects/MB-migration/dashboard/api/scripts/migration_monitor.php
Restart=always
RestartSec=10
StandardOutput=append:/home/sg/projects/MB-migration/var/log/migration_monitor.log
StandardError=append:/home/sg/projects/MB-migration/var/log/migration_monitor.error.log

[Install]
WantedBy=multi-user.target
```

Затем:

```bash
sudo systemctl daemon-reload
sudo systemctl enable migration-monitor
sudo systemctl start migration-monitor
sudo systemctl status migration-monitor
```

### Запуск через Supervisor

Создайте файл `/etc/supervisor/conf.d/migration-monitor.conf`:

```ini
[program:migration-monitor]
command=/usr/bin/php /home/sg/projects/MB-migration/dashboard/api/scripts/migration_monitor.php
directory=/home/sg/projects/MB-migration
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/home/sg/projects/MB-migration/var/log/migration_monitor.log
```

Затем:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start migration-monitor
```

## Настройка

Интервал проверки можно настроить через переменную окружения:

```bash
export MIGRATION_MONITOR_INTERVAL=10  # секунды (по умолчанию 10)
```

Или в `.env` файле:

```
MIGRATION_MONITOR_INTERVAL=10
```

## Как это работает

1. **При запуске миграции:**
   - Процесс запускается в фоне через `nohup`
   - PID процесса сохраняется в lock-файл в формате JSON
   - Lock-файл содержит: PID, время запуска, UUID проекта, ID проекта

2. **Монитор проверяет:**
   - Все lock-файлы в директории кэша
   - Для каждого lock-файла проверяет, запущен ли процесс по PID
   - Если процесс не найден и lock-файл старый (>10 минут), обновляет статус на `error`
   - Удаляет lock-файлы для завершенных процессов

3. **В дашборде:**
   - При запросе информации о процессе используется PID из lock-файла
   - Проверяется реальное состояние процесса по PID
   - Статус автоматически обновляется, если процесс не найден

## Логи

Логи монитора сохраняются в:
- `/var/log/migration_monitor.log` - основной лог
- `/var/log/migration_monitor.error.log` - ошибки (если используется systemd)

## Проверка работы

```bash
# Проверить, запущен ли монитор
ps aux | grep migration_monitor

# Посмотреть логи
tail -f /home/sg/projects/MB-migration/var/log/migration_monitor.log

# Проверить PID файл
cat /home/sg/projects/MB-migration/var/tmp/migration_monitor.pid
```

## Устранение проблем

### Монитор не запускается

1. Проверьте права доступа к скрипту: `chmod +x dashboard/api/scripts/migration_monitor.php`
2. Проверьте PHP CLI: `php -v`
3. Проверьте логи: `tail -f var/log/migration_monitor.log`

### Монитор не находит процессы

1. Убедитесь, что lock-файлы создаются с PID
2. Проверьте права доступа к директории кэша
3. Проверьте, что процессы действительно запускаются в фоне

### Статусы не обновляются

1. Проверьте подключение к базе данных
2. Проверьте права доступа к БД
3. Проверьте логи монитора на ошибки
