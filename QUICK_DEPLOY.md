# Быстрый старт CI/CD

## 🚀 Настройка за 5 минут

### 1. Подготовка сервера (один раз)

```bash
# На сервере
sudo apt update && sudo apt upgrade -y
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker $USER

# Создаем директории
sudo mkdir -p /opt/mb-dashboard
sudo chown $USER:$USER /opt/mb-dashboard
mkdir -p /opt/mb-dashboard/var/{log,cache,tmp}

# Создаем .env файл
nano /opt/mb-dashboard/.env
```

### 2. Генерация SSH ключа

```bash
# На вашем компьютере
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/github_deploy
ssh-copy-id -i ~/.ssh/github_deploy.pub user@your-server

# Показываем приватный ключ (скопируйте его)
cat ~/.ssh/github_deploy
```

### 3. Настройка GitHub Secrets

Перейдите: `Settings` → `Secrets and variables` → `Actions` → `New repository secret`

Добавьте:
- **DEPLOY_HOST** → `192.168.1.100` (или ваш IP/домен)
- **DEPLOY_USER** → `deploy` (или ваш пользователь)
- **DEPLOY_SSH_KEY** → содержимое `~/.ssh/github_deploy`

### 4. Готово! 🎉

Теперь при каждом пуше в `main` произойдет автоматический деплой.

## 📝 Проверка

1. Сделайте коммит и пуш в `main`
2. Перейдите в `Actions` в GitHub
3. Следите за процессом деплоя
4. Проверьте приложение: `http://your-server:8088`

## 🔧 Ручной деплой

```bash
./scripts/deploy.sh user@server
```

## 📚 Подробная документация

См. [doc/CI_CD_SETUP.md](doc/CI_CD_SETUP.md)
