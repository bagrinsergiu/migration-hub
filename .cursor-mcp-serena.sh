#!/bin/bash
# MCP Serena wrapper: запуск с явным путём проекта, чтобы проект определялся
# независимо от текущей директории Cursor.
# Конфигурация проекта: .serena/project.yml

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$SCRIPT_DIR"

exec uvx --from git+https://github.com/oraios/serena serena start-mcp-server --project "$PROJECT_ROOT" "$@"
