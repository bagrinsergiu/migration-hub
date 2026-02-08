# Настройка Google Sheets API

## Получение OAuth 2.0 credentials

### 1. Создание проекта в Google Cloud Console

1. Перейдите в [Google Cloud Console](https://console.cloud.google.com/)
2. Создайте новый проект или выберите существующий
3. Запомните Project ID

### 2. Включение Google Sheets API

1. В Google Cloud Console перейдите в **APIs & Services** > **Library**
2. Найдите **Google Sheets API**
3. Нажмите **Enable**

### 3. Создание OAuth 2.0 credentials

1. Перейдите в **APIs & Services** > **Credentials**
2. Нажмите **Create Credentials** > **OAuth client ID**
3. Если появится запрос, настройте **OAuth consent screen**:
   - Выберите **External** (для тестирования) или **Internal** (для корпоративных аккаунтов)
   - Заполните обязательные поля:
     - App name
     - User support email
     - Developer contact information
   - Сохраните и продолжите
4. В разделе **Scopes** добавьте:
   - `https://www.googleapis.com/auth/spreadsheets.readonly` (для чтения)
   - `https://www.googleapis.com/auth/spreadsheets` (для чтения и записи)
5. В разделе **Test users** добавьте email адреса пользователей, которые будут использовать приложение
6. Сохраните и вернитесь к созданию credentials

### 4. Настройка OAuth Client

1. Выберите тип приложения: **Web application**
2. Укажите **Name** (например: "Migration Dashboard")
3. В разделе **Authorized redirect URIs** добавьте:
   ```
   http://localhost:8088/api/google-sheets/oauth/callback
   ```
   (Замените на ваш реальный URL, если приложение работает на другом домене/порту)
4. Нажмите **Create**
5. Скопируйте **Client ID** и **Client Secret**

### 5. Настройка переменных окружения

Добавьте в файл `.env`:

```env
# Google Sheets API Configuration
GOOGLE_CLIENT_ID=your_client_id_here.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your_client_secret_here
GOOGLE_REDIRECT_URI=http://localhost:8088/api/google-sheets/oauth/callback
GOOGLE_SYNC_INTERVAL=300
```

**Важно:**
- Замените `your_client_id_here` и `your_client_secret_here` на реальные значения
- Убедитесь, что `GOOGLE_REDIRECT_URI` совпадает с URI, указанным в Google Cloud Console
- `GOOGLE_SYNC_INTERVAL` - интервал синхронизации в секундах (по умолчанию 300 = 5 минут)

## Проверка настройки

После настройки переменных окружения перезапустите приложение и проверьте:

1. Переменные загружаются корректно
2. OAuth авторизация работает (кнопка "OAuth авторизация" в интерфейсе)
3. После авторизации можно подключать Google таблицы

## Безопасность

- **НЕ коммитьте** файл `.env` в репозиторий
- Храните `GOOGLE_CLIENT_SECRET` в секрете
- Используйте разные credentials для development и production
- Регулярно обновляйте OAuth tokens
