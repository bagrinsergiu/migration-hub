# Инструкция по настройке MCP серверов для migration-hub

## Настроенные серверы

### 1. База миграций (MySQL) - `dbhub-migration`
- **Хост**: `mb-migration.cupzc9ey0cip.us-east-1.rds.amazonaws.com`
- **Порт**: `3306`
- **База данных**: `MG_prepare_mapping`
- **Пользователь**: `admin`
- **Доступ**: ✅ **Полный доступ (чтение и запись, без ограничений)**
- **Статус**: ✅ **Полностью настроена и готова к использованию**
- **Примечание**: Эта база используется для записи всех необходимых данных, ограничений нет

### 2. Клиентская база - `dbhub-client-readonly`
- **Хост**: `ec2-54-226-97-109.compute-1.amazonaws.com`
- **Порт**: `5432` (PostgreSQL) или `3306` (MySQL) - определяется автоматически
- **Тип базы**: Поддерживается PostgreSQL и MySQL (автоопределение по порту)
- **Доступ**: ✅ Только чтение (read-only)
- **Статус**: ⚠️ Требуется указать параметры подключения

### 3. Playwright - `playwright`
- **Команда**: `npx @playwright/mcp`
- **Назначение**: Автоматизация браузера через Playwright
- **Статус**: ✅ Настроен

### 4. Serena - `serena`
- **Команда (рекомендуется)**: скрипт `.cursor-mcp-serena.sh` — запуск с явным путём проекта.
- **Альтернатива**: `uvx --from git+https://github.com/oraios/serena serena start-mcp-server --project-from-cwd`
- **Назначение**: Семантическое понимание кода, навигация по символам (find_symbol, get_symbols_overview), точечное редактирование (replace_symbol_body), память проекта (onboarding, write_memory).
- **Статус**: ✅ Настроен
- **Примечание**: В проекте включены языки PHP и TypeScript; конфигурация в `.serena/project.yml`. Правило для агента: `.cursor/rules/serena.mdc`.

### 5. GitMCP - `gitmcp-migration-hub`
- **URL**: `https://gitmcp.io/bagrinsergiu/migration-hub`
- **Назначение**: Предоставляет AI-ассистенту контекст GitHub репозитория через Model Context Protocol. Читает `llms.txt`, `llms-full.txt`, `readme.md` и другие файлы репозитория для более точных ответов.
- **Статус**: ✅ Настроен
- **Примечание**: GitMCP создает Remote MCP сервер для любого публичного GitHub репозитория. Просто замените `github.com` на `gitmcp.io` в URL репозитория.

### 6. Ripgrep - `ripgrep`
- **Команда**: `npx -y mcp-ripgrep@latest`
- **Назначение**: Высокопроизводительный поиск текста в файлах проекта через ripgrep (rg). Предоставляет инструменты для поиска паттернов, подсчета совпадений, фильтрации по типам файлов и т.д.
- **Статус**: ✅ Настроен
- **Требования**: Необходимо установить `ripgrep` (rg) в системе
- **Установка ripgrep**:
  - Ubuntu/Debian: `sudo apt-get install ripgrep`
  - macOS: `brew install ripgrep`
  - Или скачать с [GitHub releases](https://github.com/BurntSushi/ripgrep/releases)
- **Доступные инструменты**:
  - `search` - Базовый поиск паттернов
  - `advanced-search` - Поиск с фильтрами (тип файла, скрытые файлы и т.д.)
  - `count-matches` - Подсчет вхождений паттерна
  - `list-files` - Список файлов без поиска
  - `list-file-types` - Показать поддерживаемые типы файлов

## Настройка клиентской базы данных

Для работы с клиентской базой данных необходимо указать параметры подключения в файле настроек Cursor:

**Файл**: `~/.config/Cursor/User/settings.json` или `~/.cursor/mcp.json`

Найдите секцию `dbhub-client-readonly` и замените значения в `env`:

**Для PostgreSQL:**
```json
"dbhub-client-readonly": {
    "command": "/media/worker/STORE/P_DEV/migration-hub/.cursor-mcp-dbhub-client.sh",
    "env": {
        "MB_DB_USER": "ваш_пользователь",
        "MB_DB_PASSWORD": "ваш_пароль",
        "MB_DB_NAME": "имя_вашей_базы_данных",
        "MB_DB_PORT": "5432",
        "MB_DB_TYPE": "postgres"
    }
}
```

**Для MySQL:**
```json
"dbhub-client-readonly": {
    "command": "/media/worker/STORE/P_DEV/migration-hub/.cursor-mcp-dbhub-client.sh",
    "env": {
        "MB_DB_USER": "ваш_пользователь",
        "MB_DB_PASSWORD": "ваш_пароль",
        "MB_DB_NAME": "имя_вашей_базы_данных",
        "MB_DB_PORT": "3306",
        "MB_DB_TYPE": "mysql"
    }
}
```

**Примечание:** Если не указать `MB_DB_TYPE`, тип базы данных будет определен автоматически по порту:
- Порт `3306` → MySQL
- Порт `5432` → PostgreSQL

### Альтернативный способ

Вы также можете отредактировать скрипт напрямую или использовать переменные окружения из `.env` файла:

**Файл**: `/media/worker/STORE/P_DEV/migration-hub/.cursor-mcp-dbhub-client.sh`

Скрипт автоматически загружает переменные из `.env` и `.env.prod.local` файлов в корне проекта.

**Важно:** Скрипт автоматически определяет тип базы данных по порту, но вы можете явно указать `MB_DB_TYPE` для большей надежности.

## Конфигурация MCP

Все MCP серверы настроены в файле: `~/.cursor/mcp.json`

```json
{
  "mcpServers": {
    "playwright": {
      "command": "npx",
      "args": ["-y", "@playwright/mcp"]
    },
    "dbhub-migration": {
      "command": "/media/worker/STORE/P_DEV/migration-hub/.cursor-mcp-dbhub-migration.sh"
    },
    "dbhub-client-readonly": {
      "command": "/media/worker/STORE/P_DEV/migration-hub/.cursor-mcp-dbhub-client.sh"
    },
    "serena": {
      "command": "/media/worker/STORE/P_DEV/migration-hub/.cursor-mcp-serena.sh"
    },
    "gitmcp-migration-hub": {
      "url": "https://gitmcp.io/bagrinsergiu/migration-hub"
    },
    "ripgrep": {
      "command": "npx",
      "args": ["-y", "mcp-ripgrep@latest"]
    }
  }
}
```

**Рекомендуется** использовать скрипт для Serena: он передаёт в сервер явный путь к проекту (`--project`), поэтому проект определяется корректно независимо от текущей директории Cursor. Альтернатива — запуск через `uvx` с `--project-from-cwd` (тогда Cursor должен быть открыт в корне репозитория).

## Настройка работы Serena

В репозитории уже настроена работа Serena для этого проекта.

### Конфигурация проекта (`.serena/project.yml`)
- **Языки**: PHP и TypeScript (backend и frontend).
- **Кодировка**: UTF-8.
- **initial_prompt**: краткое описание проекта для LLM при активации (подробный онбординг — в `doc/ONBOARDING.md`).
- Остальные параметры — по умолчанию (инструменты не отключены, не read-only).

### Запуск через скрипт (рекомендуется)
Скрипт `.cursor-mcp-serena.sh` в корне проекта:
- Вычисляет корень проекта по своему расположению и передаёт его в Serena как `--project ...`.
- Гарантирует, что активен именно этот репозиторий, даже если Cursor запущен из другой папки.

В `~/.cursor/mcp.json` для `serena` укажите:
```json
"serena": {
  "command": "/media/worker/STORE/P_DEV/migration-hub/.cursor-mcp-serena.sh"
}
```

### Правило для агента (`.cursor/rules/serena.mdc`)
Включено правило с `alwaysApply: true`, которое предписывает агенту:
- при наличии инструментов Serena вызывать `activate_project` для этого проекта;
- использовать `find_symbol`, `get_symbols_overview`, `find_referencing_symbols` для навигации;
- использовать `replace_symbol_body`, `insert_after_symbol` и т.п. для точечного редактирования;
- при необходимости использовать онбординг и память (`check_onboarding_performed`, `onboarding`, `write_memory`/`read_memory`).

Если Serena в сессии недоступен, агент использует обычные средства (grep, read_file, search_replace).

### Онбординг и память
- Полное описание проекта для людей и агентов: `doc/ONBOARDING.md`.
- При первом использовании Serena можно выполнить онбординг через инструмент `onboarding` и при необходимости сохранить контекст в память через `write_memory`.

## После настройки

1. Сохраните изменения в `mcp.json` или `settings.json`
2. Перезапустите Cursor полностью (закройте все окна и запустите заново)
3. MCP серверы должны автоматически подключиться

## Устранение проблем

### Serena не запускается

Если serena не запускается, проверьте:

1. **Установлен ли uvx**: 
   ```bash
   uvx --version
   ```
   Если не установлен, установите через pip:
   ```bash
   pip install uv
   ```

2. **Проверьте логи Cursor**:
   - Логи доступны в: `~/.config/Cursor/logs/*/exthost/anysphere.cursor-mcp/`
   - Ищите ошибки, связанные с serena

3. **Попробуйте запустить вручную** (скрипт с явным путём):
   ```bash
   /media/worker/STORE/P_DEV/migration-hub/.cursor-mcp-serena.sh
   ```
   Или с определением по текущей директории:
   ```bash
   cd /media/worker/STORE/P_DEV/migration-hub
   uvx --from git+https://github.com/oraios/serena serena start-mcp-server --project-from-cwd
   ```

4. **Конфигурация проекта** уже есть в `.serena/project.yml` (языки PHP и TypeScript, initial_prompt). При необходимости отредактируйте этот файл.

5. **При использовании `--project-from-cwd`** проверьте, что проект в правильной директории:
   - `--project-from-cwd` автоматически определяет проект из текущей рабочей директории
   - Убедитесь, что Cursor открыт в корне проекта `/media/worker/STORE/P_DEV/migration-hub`

## Проверка подключения

После перезапуска Cursor вы сможете использовать MCP серверы для работы с базами данных через AI-ассистента.

## Логи

Логи доступны в: `~/.config/Cursor/logs/*/exthost/anysphere.cursor-mcp/`

## Безопасность

⚠️ **Важно**: Файлы конфигурации могут содержать учетные данные. Убедитесь, что:
- Файлы имеют правильные права доступа (только для чтения владельцем)
- Не коммитьте эти файлы в git репозиторий
- Используйте переменные окружения для продакшн окружений

## Файлы

- Скрипт миграции БД: `/media/worker/STORE/P_DEV/migration-hub/.cursor-mcp-dbhub-migration.sh`
- Скрипт клиента БД: `/media/worker/STORE/P_DEV/migration-hub/.cursor-mcp-dbhub-client.sh`
- Скрипт Serena: `/media/worker/STORE/P_DEV/migration-hub/.cursor-mcp-serena.sh`
- Конфигурация Serena: `/media/worker/STORE/P_DEV/migration-hub/.serena/project.yml`
- Правило Cursor для Serena: `/media/worker/STORE/P_DEV/migration-hub/.cursor/rules/serena.mdc`
- Конфигурация MCP: `~/.cursor/mcp.json`
- Переменные окружения: `.env` или `.env.prod.local` в корне проекта
