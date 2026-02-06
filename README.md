# MB Migration Dashboard - Standalone

A standalone Dashboard project for the MB migration system, running on PHP 8.3.

## Requirements

### For Docker:
- Docker 20.10+
- Docker Compose 2.0+

### For local installation:
- PHP 8.3 or higher
- Composer
- MySQL 5.7+ or 8.0+
- Node.js 18+ (for frontend)

## Installation

### With Docker (Recommended)

1. Copy the project:
```bash
cd dashboard-standalone
```

2. Configure environment variables:
```bash
# Create a .env file with your settings
# Minimum required variables:
# MG_DB_HOST, MG_DB_NAME, MG_DB_USER, MG_DB_PASS
# MIGRATION_API_URL (default: http://localhost:8080)
```

For more details on environment variables, see [ENV_VARIABLES.md](doc/ENV_VARIABLES.md)

3. Start Docker:
```bash
docker-compose up -d
docker-compose run --rm composer install
```

4. Build the frontend (locally):
```bash
cd frontend
npm install
npm run build
cd ..
```

### Without Docker

1. Copy the project:
```bash
cd dashboard-standalone
```

2. Install dependencies:
```bash
composer install
```

3. Configure environment variables:
```bash
cp .env.example .env
# Edit the .env file with your database settings
```

4. Build the frontend:
```bash
cd frontend
npm install
npm run build
cd ..
```

## Configuration

Create a `.env` file in the project root:

```env
# Database Configuration
MG_DB_HOST=your-db-host
MG_DB_NAME=your-db-name
MG_DB_USER=your-db-user
MG_DB_PASS=your-db-password
MG_DB_PORT=3306

# Migration Server URL (default: http://localhost:8080)
MIGRATION_API_URL=http://localhost:8080

# Application
APP_ENV=production
APP_DEBUG=false
```

For more details on environment variables, see [ENV_VARIABLES.md](doc/ENV_VARIABLES.md)

## Project Structure

```
dashboard-standalone/
├── src/                    # PHP source code
│   ├── Controllers/        # API Controllers
│   ├── Services/           # Business logic
│   └── Middleware/         # Middleware
├── lib/                    # Dependencies from MBMigration (adapters)
│   └── MBMigration/
├── frontend/               # React application
├── public/                 # Public files
├── var/                    # Temporary files, logs, cache
└── composer.json
```

## Running

### Docker (Recommended)

```bash
# Build and start
docker-compose up -d

# Install dependencies
docker-compose run --rm composer install

# Build frontend (locally)
cd frontend && npm install && npm run build && cd ..

# View logs
docker-compose logs -f dashboard
```

Dashboard will be available at: http://localhost:8088

For more details, see [DOCKER.md](DOCKER.md)

### Development (without Docker)

```bash
php -S localhost:8088 -t public
```

### Production (without Docker)

Configure a web server (Nginx/Apache) to work with `public/index.php`

## API Endpoints

Base URL: `http://localhost:8088/api`

- `GET /health` - Health check
- `GET /migrations` - List migrations
- `GET /migrations/:id` - Migration details
- `POST /migrations/run` - Run migration
- And others...

For more details, see [API.md](API.md)

## Migration from the main project

This project was extracted from the main MB-migration project for:
- Independent development
- Using modern PHP 8.3
- Simplified deployment
- Dependency isolation

## CI/CD and automatic deployment

The project is configured for automatic deployment when pushing to the `main` branch via GitHub Actions.

### Quick setup

1. **Configure GitHub Secrets:**
   - `DEPLOY_HOST` - Server IP or domain
   - `DEPLOY_USER` - SSH user
   - `DEPLOY_SSH_KEY` - Private SSH key

2. **Prepare the server:**
   - Install Docker
   - Create directory `/opt/mb-dashboard`
   - Create `.env` file on the server

3. **Done!** Each push to `main` will trigger automatic deployment.

Detailed instructions: [CI_CD_SETUP.md](doc/CI_CD_SETUP.md)

### Manual deployment

```bash
./scripts/deploy.sh user@server
```

## License

Proprietary
