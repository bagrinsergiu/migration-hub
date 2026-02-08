#!/bin/bash
# MCP Serena wrapper: запуск с явным путём проекта, чтобы проект определялся
# независимо от текущей директории Cursor.
# Конфигурация проекта: .serena/project.yml

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$SCRIPT_DIR"

# Cursor запускает MCP с ограниченным PATH — добавляем ~/.local/bin и snap
export PATH="$HOME/.local/bin:/snap/bin:$PATH"

# Найти uvx или uv (uvx = uv tool run)
UVX=""
if [ -x "$HOME/.local/bin/uvx" ]; then
    UVX="$HOME/.local/bin/uvx"
elif [ -x "$HOME/.local/bin/uv" ]; then
    UVX="$HOME/.local/bin/uv tool run"
elif command -v uvx >/dev/null 2>&1; then
    UVX="uvx"
elif command -v uv >/dev/null 2>&1; then
    UVX="uv tool run"
fi

if [ -z "$UVX" ]; then
    echo "Serena: uv/uvx не найден. Запустите: ./scripts/setup-dev-env.sh" >&2
    exit 1
fi

exec $UVX --from git+https://github.com/oraios/serena serena start-mcp-server --project "$PROJECT_ROOT" "$@"
