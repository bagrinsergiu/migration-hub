# API для работы со скриншотами

## Обзор

Дашборд предоставляет API для управления скриншотами анализа страниц. Скриншоты хранятся в отдельном хранилище дашборда (`var/screenshots/`) и могут быть загружены из миграции через webhook.

## Эндпоинты

### 1. Загрузка скриншота из миграции (Webhook)

**POST** `/api/webhooks/screenshots`

Загружает скриншот из миграции в хранилище дашборда.

#### Тело запроса (JSON):

```json
{
  "mb_uuid": "uuid-проекта",
  "page_slug": "slug-страницы",
  "type": "source|migrated",
  "file_content": "base64-encoded-image-data или data:image/png;base64,...",
  "filename": "имя-файла.png" // опционально
}
```

#### Параметры:

- `mb_uuid` (обязательно) - UUID проекта MB
- `page_slug` (обязательно) - Slug страницы
- `type` (обязательно) - Тип скриншота: `source` или `migrated`
- `file_content` (обязательно) - Содержимое файла в формате base64 или data URI
- `filename` (опционально) - Имя файла. Если не указано, будет сгенерировано автоматически

#### Пример запроса:

```bash
curl -X POST http://localhost:8088/api/webhooks/screenshots \
  -H "Content-Type: application/json" \
  -d '{
    "mb_uuid": "123e4567-e89b-12d3-a456-426614174000",
    "page_slug": "home-page",
    "type": "source",
    "file_content": "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA..."
  }'
```

#### Ответ:

```json
{
  "success": true,
  "data": {
    "filename": "home-page_source_1234567890.png",
    "path": "/path/to/var/screenshots/uuid/home-page_source_1234567890.png",
    "url": "/api/screenshots/uuid/home-page_source_1234567890.png",
    "size": 123456,
    "type": "source"
  }
}
```

### 2. Получение метаданных скриншота для Page Analysis

**GET** `/api/review/wave/:token/migration/:mbUuid/analysis/:pageSlug/screenshots/:type`

Получает метаданные скриншота для отображения в модальном окне Page Analysis.

#### Параметры URL:

- `token` - Токен доступа для ревью
- `mbUuid` - UUID проекта MB
- `pageSlug` - Slug страницы (URL-encoded)
- `type` - Тип скриншота: `source` или `migrated`

#### Пример запроса:

```bash
curl http://localhost:8088/api/review/wave/abc123/migration/uuid-123/analysis/home-page/screenshots/source
```

#### Ответ:

```json
{
  "success": true,
  "data": {
    "filename": "home-page_source_1234567890.png",
    "path": "/path/to/var/screenshots/uuid/home-page_source_1234567890.png",
    "url": "/api/screenshots/uuid/home-page_source_1234567890.png",
    "size": 123456,
    "type": "source",
    "created_at": "2024-01-01 12:00:00"
  }
}
```

### 3. Получение файла скриншота

**GET** `/api/screenshots/:mbUuid/:filename`

Возвращает файл скриншота напрямую.

#### Параметры URL:

- `mbUuid` - UUID проекта MB
- `filename` - Имя файла скриншота

#### Пример запроса:

```bash
curl http://localhost:8088/api/screenshots/uuid-123/home-page_source_1234567890.png
```

#### Ответ:

Возвращает изображение с соответствующими HTTP заголовками:
- `Content-Type: image/png` (или другой MIME тип)
- `Content-Length: размер файла`
- `Cache-Control: public, max-age=3600`

## Интеграция с миграцией

Для загрузки скриншотов из миграции в дашборд, миграция должна отправлять POST запрос на webhook эндпоинт после создания скриншота.

### Пример интеграции в PHP:

```php
// После создания скриншота в миграции
$screenshotPath = '/path/to/screenshot.png';
$screenshotContent = file_get_contents($screenshotPath);
$base64Content = base64_encode($screenshotContent);

$webhookUrl = 'http://localhost:8088/api/webhooks/screenshots';
$data = [
    'mb_uuid' => $mbUuid,
    'page_slug' => $pageSlug,
    'type' => 'source', // или 'migrated'
    'file_content' => 'data:image/png;base64,' . $base64Content,
    'filename' => basename($screenshotPath)
];

$ch = curl_init($webhookUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$response = curl_exec($ch);
curl_close($ch);
```

## Хранение

Скриншоты хранятся в директории `var/screenshots/` в структуре:
```
var/screenshots/
  └── {mb_uuid}/
      └── {filename}
```

Метаданные скриншотов сохраняются в таблице `dashboard_screenshots` в базе данных.

## Автоматическое обновление

При получении анализа страницы через эндпоинт `/api/review/wave/:token/migration/:mbUuid/analysis/:pageSlug`, система автоматически проверяет наличие скриншотов в новом хранилище и обновляет пути в ответе, если скриншоты найдены.
