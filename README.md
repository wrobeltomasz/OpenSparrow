# OpenSparrow

**Schema-driven PHP platform for generating CRUD apps, dashboards, and calendars on PostgreSQL.**  
Configuration drives tables, forms, widgets, and workflows — no code required.

[![Deploy to Render](https://render.com/images/deploy-to-render-button.svg)](https://render.com/deploy?repo=https://github.com/wrobeltomasz/open-sparrow)
[![Deploy on Railway](https://railway.app/button.svg)](https://railway.app/new/template?template=https://github.com/wrobeltomasz/open-sparrow)

---

![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-14%2B-4169E1?logo=postgresql&logoColor=white)
![License](https://img.shields.io/badge/License-LGPL%20v3-blue)
![Version](https://img.shields.io/badge/version-2.7.0-green)

## Features

- Grid views with filters, sorting, pagination, keyboard shortcuts, and mass edit
- Form builder with field types, validation, FK selects, file uploads, and comments
- Dashboard with configurable widgets (charts, counters, tables, calendars)
- Role-based access (admin / editor / viewer)
- Automations with create/update triggers and conditional actions
- CSV import, data cleanup, record ownership, many-to-many relations
- Local RAG with Ollama (document Q&A, statistics)
- Multilingual UI (i18n)
- No npm, no composer, no build step — pure PHP + vanilla JS

## Stack

| Layer    | Technology                     |
|----------|--------------------------------|
| Backend  | PHP 8.1+                       |
| Database | PostgreSQL 14+                 |
| Frontend | Vanilla JS (ES6+)              |
| Server   | Nginx + PHP-FPM                |
| Deploy   | Docker Compose or Apache/Nginx |

## Quick Start (Docker)

```bash
git clone https://github.com/wrobeltomasz/open-sparrow.git
cd open-sparrow
docker compose up -d --build
```

Open [http://localhost:8080](http://localhost:8080), then go to **Admin → Initialize System Tables**.

## One-Click Deploy

### Render

[![Deploy to Render](https://render.com/images/deploy-to-render-button.svg)](https://render.com/deploy?repo=https://github.com/wrobeltomasz/open-sparrow)

Deploys: managed PostgreSQL + web service (nginx + PHP-FPM) + cron worker.  
Free tier available. Persistent file storage requires a paid plan (add disk at `/var/www/html/storage`).

### Railway

[![Deploy on Railway](https://railway.app/button.svg)](https://railway.app/new/template?template=https://github.com/wrobeltomasz/open-sparrow)

1. Create a new Railway project
2. Add **PostgreSQL** service (Dashboard → New → Database → PostgreSQL)  
   Railway auto-injects `PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`
3. Deploy this repo
4. Set additional env vars (see table below)

## Environment Variables

| Variable               | Default      | Required | Notes                              |
|------------------------|--------------|----------|------------------------------------|
| `PGHOST`               | `localhost`  | Yes      | Auto-set by Railway/Render         |
| `PGPORT`               | `5432`       | Yes      | Auto-set by Railway/Render         |
| `PGDATABASE`           | —            | Yes      | Auto-set by Railway/Render         |
| `PGUSER`               | —            | Yes      | Auto-set by Railway/Render         |
| `PGPASSWORD`           | —            | Yes      | Auto-set by Railway/Render         |
| `PGSCHEMA`             | `app`        | No       | PostgreSQL schema name             |
| `APP_ENV`              | `production` | No       |                                    |
| `SECURE_COOKIES`       | `true`       | No       | Set `false` on plain HTTP          |
| `IP_HASH_SALT`         | —            | **Yes**  | Random 32-char secret (production) |
| `SESSION_MAX_LIFETIME` | `28800`      | No       | Seconds (8 h)                      |

## After Deploy

1. Open the app URL
2. Go to **Admin → Initialize System Tables**
3. Configure your data model in **Admin → Schema**
4. Add menu items in **Admin → Menu**

## License

[LGPL v3](LICENSE)
