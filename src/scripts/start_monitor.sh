#!/bin/bash
# Скрипт для запуска монитора миграций

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"
MONITOR_SCRIPT="$SCRIPT_DIR/migration_monitor.php"
PID_FILE="$PROJECT_ROOT/var/tmp/migration_monitor.pid"
LOG_FILE="$PROJECT_ROOT/var/log/migration_monitor.log"

# Проверяем, не запущен ли уже монитор
if [ -f "$PID_FILE" ]; then
    OLD_PID=$(cat "$PID_FILE")
    if ps -p "$OLD_PID" > /dev/null 2>&1; then
        echo "Монитор миграций уже запущен (PID: $OLD_PID)"
        exit 1
    else
        echo "Удаляем старый PID файл"
        rm -f "$PID_FILE"
    fi
fi

# Создаем необходимые директории
mkdir -p "$(dirname "$PID_FILE")"
mkdir -p "$(dirname "$LOG_FILE")"

# Запускаем монитор в фоне
cd "$PROJECT_ROOT"
nohup php "$MONITOR_SCRIPT" >> "$LOG_FILE" 2>&1 &
NEW_PID=$!

# Сохраняем PID
echo $NEW_PID > "$PID_FILE"

echo "Монитор миграций запущен (PID: $NEW_PID)"
echo "Логи: $LOG_FILE"
echo "Для остановки: kill $NEW_PID или ./stop_monitor.sh"
