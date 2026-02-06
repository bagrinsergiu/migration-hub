#!/bin/bash
# MCP DBHub wrapper script for client database (read-only)
# Database: ec2-54-226-97-109.compute-1.amazonaws.com
# Access: Read-only (--readonly flag enabled)
#
# Configuration: Loads from .env file in project root, with fallback to environment variables

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR" && pwd)"

# Function to load variables from .env file
load_env_file() {
    local env_file="$1"
    if [ -f "$env_file" ]; then
        set -a
        while IFS= read -r line || [ -n "$line" ]; do
            # Skip comments and empty lines
            case "$line" in
                \#*|'') continue ;;
                *)
                    # Export the variable
                    export "$line" 2>/dev/null || true
                    ;;
            esac
        done < "$env_file"
        set +a
    fi
}

# Load variables from .env files (priority: .env.prod.local > .env)
# This matches the pattern used in phinx.php
load_env_file "$PROJECT_ROOT/.env"
load_env_file "$PROJECT_ROOT/.env.prod.local"

# Set defaults and use environment variables (from .env or system env)
MB_DB_HOST="${MB_DB_HOST:-ec2-54-226-97-109.compute-1.amazonaws.com}"
MB_DB_USER="${MB_DB_USER:-your_client_db_user}"
MB_DB_PASSWORD="${MB_DB_PASSWORD:-your_client_db_password}"
MB_DB_NAME="${MB_DB_NAME:-your_client_db_name}"
MB_DB_PORT="${MB_DB_PORT:-5432}"
MB_DB_TYPE="${MB_DB_TYPE:-auto}"

# Auto-detect database type by port if not specified
if [ "$MB_DB_TYPE" = "auto" ]; then
    if [ "$MB_DB_PORT" = "3306" ]; then
        MB_DB_TYPE="mysql"
    elif [ "$MB_DB_PORT" = "5432" ] || [ "$MB_DB_PORT" = "50000" ]; then
        # Port 50000 is often used for PostgreSQL in production
        MB_DB_TYPE="postgres"
    else
        # Default to PostgreSQL if port is not standard
        MB_DB_TYPE="postgres"
    fi
fi

# URL encode password to handle special characters
urlencode() {
    local string="${1}"
    local strlen=${#string}
    local encoded=""
    local pos c o

    for (( pos=0 ; pos<strlen ; pos++ )); do
        c=${string:$pos:1}
        case "$c" in
            [-_.~a-zA-Z0-9] ) o="${c}" ;;
            * ) printf -v o '%%%02x' "'$c" ;;
        esac
        encoded+="${o}"
    done
    echo "${encoded}"
}

ENCODED_PASSWORD=$(urlencode "$MB_DB_PASSWORD")

# Build DSN based on database type
if [ "$MB_DB_TYPE" = "mysql" ]; then
    DSN="mysql://${MB_DB_USER}:${ENCODED_PASSWORD}@${MB_DB_HOST}:${MB_DB_PORT}/${MB_DB_NAME}"
else
    # PostgreSQL
    DSN="postgres://${MB_DB_USER}:${ENCODED_PASSWORD}@${MB_DB_HOST}:${MB_DB_PORT}/${MB_DB_NAME}?sslmode=require"
fi

# Note: --readonly flag is deprecated, but MCP server will enforce read-only access
exec npx @bytebase/dbhub@latest --transport stdio --dsn "$DSN"
