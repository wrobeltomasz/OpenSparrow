# Production Setup

## Quick Start

```bash
# 1. Copy production config
cp docker-compose-production.yml docker-compose.yml

# 2. Create .env from template
cp .env.example .env
nano .env  # Set POSTGRES_PASSWORD to a strong value

# 3. Lock down .env
echo ".env" >> .gitignore

# 4. Deploy
docker compose pull
docker compose up -d

# 5. Initialize database (first run only)
# Open http://localhost/ — you are redirected to the setup wizard,
# which creates the admin account with a randomly generated password
# (shown once in the wizard — copy it, then change it after first login)

# 6. Verify
docker compose ps
curl http://localhost/login.php
```

---

## Development

```bash
# Merges docker-compose.yml + docker-compose.override.yml automatically
docker compose up -d
```

---

## Environment Variables

| Variable | Default | Required |
|----------|---------|----------|
| `POSTGRES_PASSWORD` | — | ✓ Strong value required |
| `POSTGRES_USER` | postgres | |
| `POSTGRES_DB` | opensparrow | |
| `PGSCHEMA` | app | |
| `DOCKER_IMAGE` | wrobeltom/opensparrow:latest | |
| `APP_ENV` | production | |
| `SECURE_COOKIES` | true | Set `false` only on plain HTTP. |
| `SESSION_MAX_LIFETIME` | 28800 | Hard session expiry (seconds). |
| `SESSION_SAMESITE` | Lax | Do not set to `Strict` — breaks login redirect. |
| `IP_HASH_SALT` | *(auto)* | Auto-generated to `includes/.secret_salt` on first request. Set explicitly across all nodes in multi-server deployments so rate-limit hashes match. |
| `LOGIN_MAX_ATTEMPTS_PER_IP` | 20 | Failed login threshold per IP before lockout. |
| `LOGIN_MAX_ATTEMPTS_PER_USERNAME` | 5 | Failed login threshold per user before lockout. |
| `LOGIN_LOCKOUT_MINUTES` | 15 | Lockout window. |
| `HTTP_PORT` | 80 | |

Generate strong password / salt:
```bash
openssl rand -base64 32       # POSTGRES_PASSWORD
openssl rand -hex 32          # IP_HASH_SALT (for multi-node)
```

### Reverse proxy (CloudFlare, Nginx, load balancer)

OpenSparrow auto-detects HTTPS through `X-Forwarded-Proto`, `CF-Visitor`, and
`X-Forwarded-SSL` headers, and resolves the real client IP via
`CF-Connecting-IP` / `X-Real-IP`. **No configuration required** behind a
properly configured reverse proxy.

---

## Monitoring

```bash
docker compose ps               # Health status
docker compose logs -f          # All services
docker compose logs -f app      # Specific service
docker compose logs -f db
docker compose logs -f nginx
```

---

## Backup & Restore

```bash
# Backup database
docker compose exec db pg_dump \
  -U ${POSTGRES_USER:-postgres} \
  ${POSTGRES_DB:-opensparrow} > backup_$(date +%Y%m%d).sql

# Backup files
tar -czf storage_$(date +%Y%m%d).tar.gz storage/

# Restore database
docker compose exec -T db psql \
  -U ${POSTGRES_USER:-postgres} \
  ${POSTGRES_DB:-opensparrow} < backup.sql

# Restore files
tar -xzf storage_backup.tar.gz
```

---

## Upgrade

```bash
# 1. Update image version in .env (release tags follow X.Y format, no "v" prefix)
DOCKER_IMAGE=wrobeltom/opensparrow:2.8

# 2. Pull & restart
docker compose pull
docker compose up -d

# 3. Apply migrations if any
# Admin → System Health → Initialize System Tables
```

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| `POSTGRES_PASSWORD must be set` | Check `.env` exists with value set |
| Port already in use | Change `HTTP_PORT` in `.env` |
| DB not ready | `docker compose logs db` |
| App unhealthy | `docker compose logs app` |

---

## Checklist

- [ ] `.env` created with strong `POSTGRES_PASSWORD`
- [ ] `.env` added to `.gitignore`
- [ ] `docker compose pull` succeeded
- [ ] `docker compose ps` — all services healthy
- [ ] `/admin → System Health → Initialize System Tables` ran
- [ ] Login works at `http://localhost/login.php`
- [ ] Backup strategy in place
