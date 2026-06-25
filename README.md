# Lupcom Timer

Project and task time tracking app built with PHP, Twig, MySQL, and SCSS.

## Requirements

- PHP 8.2+
- Composer
- Node.js (for SCSS compilation)
- MySQL 5.7+

## Project structure

```
config/           # App and database configuration
database/
  migrations/     # Versioned database migrations
public/           # Web root (index.php, compiled CSS, JS)
resources/
  scss/           # SCSS source files
  views/          # Twig HTML templates
src/
  Controllers/    # HTTP controllers
  Core/           # Application bootstrap, router, view
  Database/       # Migration runner
  Http/           # Request/response objects
  Models/         # Data models
  Repositories/   # Database access layer
  Services/       # Business logic (timer)
  Support/        # Helpers
bin/console       # CLI (migrations)
```

## Setup (DDEV)

```bash
ddev composer install
ddev npm install
ddev npm run build:css
ddev exec php bin/console migrate
```

Open https://timer-lupcom.ddev.site

## Development

```bash
# Watch SCSS changes
ddev npm run watch:css

# Run migrations
ddev exec php bin/console migrate

# Roll back last migration
ddev exec php bin/console migrate:rollback
```

## Features

- Create and manage **projects** and **tasks**
- **Start/stop timer** per project or task
- Dashboard with today's total and recent sessions
- Per-project and per-task tracked time summaries

## API endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/timer/status` | Current running timer |
| POST | `/api/timer/start` | Start timer (`project_id`, optional `task_id`) |
| POST | `/api/timer/stop` | Stop active timer |
