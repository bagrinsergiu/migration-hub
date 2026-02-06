#!/bin/bash
# Скрипт для запуска Serena MCP сервера с явным указанием пути к проекту

# Определяем корень проекта по расположению скрипта
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$SCRIPT_DIR"

# Запускаем Serena с явным указанием проекта
exec uvx --from git+https://github.com/oraios/serena serena start-mcp-server --project "$PROJECT_ROOT"