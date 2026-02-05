# Настройка Google OAuth Credentials - Краткая инструкция

## Откуда взять GOOGLE_CLIENT_ID и GOOGLE_CLIENT_SECRET

### Пошаговая инструкция:

#### 1. Перейдите в Google Cloud Console
- Откройте: https://console.cloud.google.com/
- Войдите в свой Google аккаунт

#### 2. Создайте или выберите проект
- Нажмите на выпадающий список проектов вверху
- Создайте новый проект или выберите существующий

#### 3. Включите Google Sheets API
- В меню слева: **APIs & Services** → **Library**
- Найдите "Google Sheets API"
- Нажмите **Enable** (Включить)

#### 4. Настройте OAuth consent screen (Экран согласия OAuth)
- В меню: **APIs & Services** → **OAuth consent screen**
- Выберите тип: **External** (для тестирования) или **Internal** (для корпоративных аккаунтов)
- Заполните обязательные поля:
  - **App name**: например, "Migration Dashboard"
  - **User support email**: ваш email
  - **Developer contact information**: ваш email
- Нажмите **Save and Continue**
- В разделе **Scopes** (Области доступа):
  - Нажмите **Add or Remove Scopes**
  - Добавьте:
    - `https://www.googleapis.com/auth/spreadsheets.readonly`
    - `https://www.googleapis.com/auth/spreadsheets`
  - Нажмите **Update** → **Save and Continue**
- В разделе **Test users** (Тестовые пользователи):
  - Нажмите **Add Users**
  - Добавьте email адреса пользователей, которые будут использовать приложение
  - Нажмите **Save and Continue**
- Просмотрите сводку и нажмите **Back to Dashboard**

#### 5. Создайте OAuth 2.0 Client ID
- В меню: **APIs & Services** → **Credentials**
- Нажмите **Create Credentials** → **OAuth client ID**
- Выберите тип приложения: **Web application**
- Заполните:
  - **Name**: например, "Migration Dashboard"
  - **Authorized redirect URIs**: добавьте URL:
    ```
    http://localhost:8088/api/google-sheets/oauth/callback
    ```
    ⚠️ **Важно**: Если ваше приложение работает на другом домене/порту, укажите соответствующий URL
- Нажмите **Create**
- **Скопируйте Client ID и Client Secret** - они понадобятся для настройки

#### 6. Настройте переменные окружения

Создайте или отредактируйте файл `.env` в корне проекта:

```env
# Google Sheets API Configuration
GOOGLE_CLIENT_ID=ваш_client_id_здесь.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=ваш_client_secret_здесь
GOOGLE_REDIRECT_URI=http://localhost:8088/api/google-sheets/oauth/callback
GOOGLE_SYNC_INTERVAL=300
```

**Где взять значения:**
- `GOOGLE_CLIENT_ID` - это **Client ID**, который вы скопировали на шаге 5
- `GOOGLE_CLIENT_SECRET` - это **Client Secret**, который вы скопировали на шаге 5
- `GOOGLE_REDIRECT_URI` - должен **точно совпадать** с URL, указанным в Google Cloud Console (шаг 5)
- `GOOGLE_SYNC_INTERVAL` - интервал синхронизации в секундах (300 = 5 минут)

## Проверка настройки

После настройки:

1. **Перезапустите приложение** (если оно запущено)
2. Проверьте, что переменные загружаются корректно
3. Попробуйте выполнить OAuth авторизацию через интерфейс приложения

## Решение ошибки 403: access_denied

Если вы получаете ошибку **403: access_denied**, это означает, что ваш email не добавлен в список тестовых пользователей.

### Как добавить себя в список разрешенных пользователей:

1. **Откройте Google Cloud Console**
   - Перейдите: https://console.cloud.google.com/
   - Выберите ваш проект

2. **Перейдите в OAuth consent screen**
   - В меню слева: **APIs & Services** → **OAuth consent screen**

3. **Добавьте тестовых пользователей**
   - Прокрутите страницу до раздела **Test users** (Тестовые пользователи)
   - Нажмите кнопку **+ ADD USERS** (или **Добавить пользователей**)
   - Введите **ваш email адрес** (тот, которым вы авторизуетесь в Google)
   - Нажмите **ADD** (Добавить)
   - Нажмите **SAVE** (Сохранить)

4. **Повторно попробуйте авторизацию**
   - После добавления email, попробуйте снова выполнить OAuth авторизацию
   - Ошибка 403 должна исчезнуть

⚠️ **Важно:**
- Email должен быть **точно таким же**, каким вы авторизуетесь в Google
- Если вы используете несколько Google аккаунтов, добавьте все нужные email адреса
- Изменения могут вступить в силу не сразу (подождите 1-2 минуты)

### Альтернативное решение (для production):

Если приложение уже опубликовано и прошло верификацию Google, тестовые пользователи не требуются. Но для разработки и тестирования всегда нужен список тестовых пользователей.

## Важные замечания

⚠️ **Безопасность:**
- **НЕ коммитьте** файл `.env` в репозиторий (он должен быть в `.gitignore`)
- Храните `GOOGLE_CLIENT_SECRET` в секрете
- Используйте разные credentials для development и production

⚠️ **Redirect URI:**
- URL в `GOOGLE_REDIRECT_URI` должен **точно совпадать** с URL в Google Cloud Console
- Если приложение работает на другом порту/домене, обновите оба места

⚠️ **Тестовые пользователи:**
- Для приложений в режиме "Testing" (Тестирование) обязательно нужен список тестовых пользователей
- Максимум 100 тестовых пользователей для приложения в режиме тестирования

## Полезные ссылки

- [Google Cloud Console](https://console.cloud.google.com/)
- [Google Sheets API Documentation](https://developers.google.com/sheets/api)
- [OAuth 2.0 для веб-приложений](https://developers.google.com/identity/protocols/oauth2/web-server)
