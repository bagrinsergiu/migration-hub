# Руководство по миграции Dashboard в отдельный проект

## Обзор

Dashboard был выделен из основного проекта MB-migration в отдельный standalone проект для:
- Использования PHP 8.3 (вместо PHP 7.4)
- Независимого развития и развертывания
- Упрощения зависимостей
- Изоляции от основного проекта миграции

## Изменения в структуре

### Старая структура:
```
MB-migration/
├── dashboard/
│   ├── api/
│   │   ├── controllers/
│   │   ├── services/
│   │   └── index.php
│   └── frontend/
└── lib/MBMigration/
```

### Новая структура:
```
dashboard-standalone/
├── src/                    # PHP код (было dashboard/api)
│   ├── controllers/
│   ├── services/
│   └── index.php
├── lib/MBMigration/        # Минимальные адаптеры
├── frontend/               # React приложение
├── public/                 # Публичная точка входа
└── var/                    # Логи, кэш, временные файлы
```

## Изменения в путях

### В коде PHP:

**Старое:**
```php
dirname(__DIR__, 3)  // из dashboard/api/services
```

**Новое:**
```php
dirname(__DIR__, 2)  // из src/services
```

### В URL:

**Старое:**
- API: `/dashboard/api/*`
- Frontend: `/dashboard/*`

**Новое:**
- API: `/api/*`
- Frontend: `/*`

## Необходимые изменения в коде

### 1. Обновление путей к корню проекта

Найдите и замените во всех файлах:
```bash
# В src/ директории
find src/ -name "*.php" -exec sed -i 's/dirname(__DIR__, 3)/dirname(__DIR__, 2)/g' {} \;
```

### 2. Обновление путей в index.php

Файл `src/index.php` уже обновлен для новой структуры.

### 3. Обновление путей в сервисах

Все сервисы используют `dirname(__DIR__, 3)` - нужно заменить на `dirname(__DIR__, 2)`.

## Зависимости

### Скопированные классы из MBMigration:

1. **MySQL** (`lib/MBMigration/Layer/DataSource/driver/MySQL.php`)
   - Полная копия, работает без изменений

2. **Logger** (`lib/MBMigration/Core/Logger.php`)
   - Полная копия, работает без изменений

3. **Config** (`lib/MBMigration/Core/Config.php`)
   - Минимальный адаптер с методом `initializeFromEnv()`
   - Инициализируется автоматически при загрузке

4. **BrizyAPI** (`lib/MBMigration/Layer/Brizy/BrizyAPI.php`)
   - Полная копия (может требовать дополнительные зависимости)

5. **QualityReport** (`lib/MBMigration/Analysis/QualityReport.php`)
   - Полная копия, работает без изменений

## Установка

1. Скопируйте проект:
```bash
cp -r dashboard-standalone /path/to/new/location
cd /path/to/new/location/dashboard-standalone
```

2. Установите зависимости:
```bash
composer install
```

3. Настройте .env:
```bash
cp .env.example .env
# Отредактируйте .env с вашими настройками
```

4. Соберите фронтенд:
```bash
cd frontend
npm install
npm run build
cd ..
```

5. Настройте веб-сервер:
   - Укажите DocumentRoot на `public/`
   - Или используйте встроенный сервер PHP:
   ```bash
   php -S localhost:8088 -t public
   ```

## Проверка работоспособности

1. Проверьте API:
```bash
curl http://localhost:8088/api/health
```

2. Откройте в браузере:
```
http://localhost:8088
```

## Известные проблемы

1. **Пути в сервисах**: Некоторые сервисы все еще используют старые пути `dirname(__DIR__, 3)`. 
   Нужно заменить на `dirname(__DIR__, 2)`.

2. **BrizyAPI зависимости**: Класс BrizyAPI может требовать дополнительные классы из основного проекта.
   Если возникнут ошибки, нужно будет скопировать недостающие классы или создать адаптеры.

3. **Symfony Runtime**: Старый код использовал `vendor/autoload_runtime.php`, 
   новый использует стандартный `vendor/autoload.php`.

## Следующие шаги

1. ✅ Создана структура проекта
2. ✅ Создан composer.json с PHP 8.3
3. ✅ Скопированы необходимые классы MBMigration
4. ✅ Обновлены основные пути в index.php
5. ⏳ Обновить пути в сервисах (dirname(__DIR__, 3) -> dirname(__DIR__, 2))
6. ⏳ Проверить и добавить недостающие зависимости для BrizyAPI
7. ⏳ Протестировать все endpoints
8. ⏳ Обновить документацию API

## Поддержка

При возникновении проблем проверьте:
- Логи в `var/log/`
- Настройки в `.env`
- Версию PHP (должна быть 8.3+)
- Установленные зависимости через `composer show`
