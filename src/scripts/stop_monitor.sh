#!/bin/bash
# Скрипт для остановки монитора миграций

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"
PID_FILE="$PROJECT_ROOT/var/tmp/migration_monitor.pid"

if [ ! -f "$PID_FILE" ]; then
    echo "Монитор миграций не запущен (PID файл не найден)"
    exit 1
fi

PID=$(cat "$PID_FILE")

if ! ps -p "$PID" > /dev/null 2>&1; then
    echo "Процесс с PID $PID не найден, удаляем PID файл"
    rm -f "$PID_FILE"
    exit 1
fi

echo "Остановка монитора миграций (PID: $PID)..."
kill "$PID"

# Ждем завершения процесса
for i in {1..10}; do
    if ! ps -p "$PID" > /dev/null 2>&1; then
        echo "Монитор миграций остановлен"
        rm -f "$PID_FILE"
        exit 0
    fi
    sleep 1
done

# Если процесс не завершился, принудительно убиваем
if ps -p "$PID" > /dev/null 2>&1; then
    echo "Принудительное завершение процесса..."
    kill -9 "$PID"
    rm -f "$PID_FILE"
    echo "Монитор миграций принудительно остановлен"
else
    rm -f "$PID_FILE"
    echo "Монитор миграций остановлен"
fi
