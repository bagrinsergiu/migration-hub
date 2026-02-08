# Стиль кода и соглашения

## PHP (Backend)
- **Стандарт:** PSR-4 автозагрузка; следовать существующим паттернам в проекте.
- **Структура:** Контроллеры в `src/controllers/`, сервисы в `src/services/`, middleware в `src/middleware/`.
- **Именование:** CamelCase для классов; camelCase для методов и переменных; при необходимости PHPDoc для параметров и возвращаемых значений.
- **Namespace:** Все классы приложения в `Dashboard\` с соответствующим подпространством (Controllers, Services, Core и т.д.).

## TypeScript / React (Frontend)
- **Расположение:** Компоненты в `frontend/src/components/`, API в `frontend/src/api/`, утилиты в `frontend/src/utils/`.
- **Стиль:** Следовать ESLint-конфигу (`frontend/.eslintrc.cjs`): recommended + @typescript-eslint; `@typescript-eslint/no-explicit-any` — warn.
- **Сборка:** TypeScript компиляция + Vite (`tsc && vite build`).

## Общее
- Не создавать лишние файлы; новые модули размещать в соответствующих каталогах и подключать в роутинг/автозагрузку.
- Документация по фичам и окружению — в `doc/`; таски и статусы — в `tasks/`.
