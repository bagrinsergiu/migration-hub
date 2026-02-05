# Dashboard Frontend

React приложение для управления миграциями MB → Brizy.

## Установка

```bash
cd dashboard/frontend
npm install
```

## Разработка

Запуск dev сервера с hot reload:

```bash
npm run dev
```

Приложение будет доступно по адресу `http://localhost:3000`

## Сборка

Сборка для production:

```bash
npm run build
```

Собранные файлы будут в папке `dist/`

## Структура

```
src/
├── api/           # API клиент
├── components/    # React компоненты
├── utils/         # Утилиты
├── App.tsx        # Главный компонент
└── main.tsx       # Точка входа
```

## Компоненты

- **Layout** - Основной layout с навигацией
- **MigrationsList** - Список миграций с фильтрами
- **MigrationDetails** - Детальная информация о миграции
- **RunMigration** - Форма запуска новой миграции
- **Logs** - Просмотр логов миграций

## API

Все запросы идут через `/dashboard/api`. Прокси настроен в `vite.config.ts` для dev режима.

## Технологии

- React 18
- TypeScript
- Vite
- React Router
- Axios
- date-fns
