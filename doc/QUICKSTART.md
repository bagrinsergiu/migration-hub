# Быстрый старт Dashboard с Docker

## Минимальные шаги для запуска

1. **Настройте .env файл:**
```bash
cp .env.example .env
# Отредактируйте .env с вашими настройками БД
```

2. **Соберите и запустите:**
```bash
docker-compose build
docker-compose up -d
```

3. **Установите зависимости:**
```bash
docker-compose run --rm composer install
```

4. **Соберите фронтенд (локально):**
```bash
cd frontend
npm install
npm run build
cd ..
```

5. **Откройте в браузере:**
```
http://localhost:8080
```

## Проверка работоспособности

```bash
# Проверка API
curl http://localhost:8088/api/health

# Просмотр логов
docker-compose logs -f dashboard
```

## Остановка

```bash
docker-compose down
```

## Пересборка после изменений

```bash
docker-compose build --no-cache dashboard
docker-compose up -d
```

Готово! 🚀
