# TASK-003: Добавление переменных окружения для Google API

## Статус: ⏳ В ожидании

**Дата создания:** 2025-01-30  
**Приоритет:** Высокий  
**Зависимости:** TASK-001

---

## Описание

Добавить переменные окружения для настройки OAuth 2.0 подключения к Google Sheets API.

## Что нужно сделать

### 1. Добавить переменные в .env файл

**Файл:** `.env` или `.env.example`

Добавить следующие переменные:
```env
# Google Sheets API Configuration
GOOGLE_CLIENT_ID=your_client_id_here
GOOGLE_CLIENT_SECRET=your_client_secret_here
GOOGLE_REDIRECT_URI=http://localhost:8080/api/google-sheets/oauth-callback
GOOGLE_SYNC_INTERVAL=300
```

### 2. Обновить .env.example (если существует)

Добавить те же переменные с placeholder значениями для документации.

### 3. Создать документацию по настройке

**Файл:** `tasks/google-sheets-integration/planning/google-oauth-setup.md` (опционально)

Описать процесс получения OAuth credentials:
1. Создание проекта в Google Cloud Console
2. Включение Google Sheets API
3. Создание OAuth 2.0 credentials
4. Настройка redirect URI

---

## Ожидаемые результаты

### Проверка переменных окружения

- [ ] Переменные добавлены в `.env` файл
- [ ] Переменные доступны через `$_ENV['GOOGLE_CLIENT_ID']`
- [ ] Переменные доступны через `$_ENV['GOOGLE_CLIENT_SECRET']`
- [ ] Переменные доступны через `$_ENV['GOOGLE_REDIRECT_URI']`
- [ ] Переменные доступны через `$_ENV['GOOGLE_SYNC_INTERVAL']`

### Проверка значений

- [ ] `GOOGLE_CLIENT_ID` содержит валидный Client ID (не пустой)
- [ ] `GOOGLE_CLIENT_SECRET` содержит валидный Client Secret (не пустой)
- [ ] `GOOGLE_REDIRECT_URI` содержит корректный URL
- [ ] `GOOGLE_SYNC_INTERVAL` содержит числовое значение (секунды)

### Проверка загрузки

- [ ] Переменные загружаются при инициализации приложения
- [ ] Можно получить значения через `getenv('GOOGLE_CLIENT_ID')`
- [ ] Нет ошибок при отсутствии переменных (должна быть обработка ошибок)

---

## Связанные файлы

- `.env` - файл переменных окружения
- `.env.example` - пример файла переменных окружения

---

## Примечания

- Не коммитить реальные credentials в репозиторий
- Использовать `.env.example` для документации
- Убедиться, что `.env` в `.gitignore`
