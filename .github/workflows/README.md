# GitHub Actions Workflows

## Deploy to Production

Автоматический деплой при пуше в ветку `main`.

### Быстрая настройка

1. Перейдите в `Settings` → `Secrets and variables` → `Actions`
2. Добавьте секреты:
   - `DEPLOY_HOST` - IP или домен сервера
   - `DEPLOY_USER` - пользователь SSH
   - `DEPLOY_SSH_KEY` - приватный SSH ключ

3. Готово! При пуше в `main` произойдет автоматический деплой.

Подробнее: [CI_CD_SETUP.md](../../doc/CI_CD_SETUP.md)
