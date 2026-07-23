#!/bin/bash
#
# OpenSparrow - Fresh Installation / Reset Script
#
# Wipes any prior OpenSparrow Docker stack on this host, pulls the latest
# wrobeltom/opensparrow image, generates nginx.conf + docker-compose.yml + .env
# with random DB credentials, and brings the stack up.
#
# Usage:
#   ./install.sh                              # defaults: app on 8080, db on 5432
#   APP_PORT=80 DB_EXTERNAL_PORT=5433 ./install.sh
#   ./install.sh --skip-backup                # skip the pre-wipe pg_dump
#
set -e

# ---- Colors ---------------------------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

SKIP_BACKUP=false
for arg in "$@"; do
    case "$arg" in
        --skip-backup) SKIP_BACKUP=true ;;
    esac
done

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}OpenSparrow - Fresh Installation Script${NC}"
echo -e "${BLUE}========================================${NC}\n"

# ==========================================
# 0. CHECK DOCKER / UPGRADE COMPOSE V1 -> V2
# ==========================================
echo -e "${YELLOW}[0/9] Checking Docker...${NC}"

if ! command -v docker &> /dev/null; then
    echo -e "${RED}Docker is not installed.${NC}"
    echo "Install it first, e.g.:"
    echo "  curl -fsSL https://get.docker.com | sh"
    echo "See https://docs.docker.com/engine/install/ for other platforms."
    exit 1
fi

# Check docker-compose (v1) version, offer upgrade to v2 binary if too old
COMPOSE_VERSION=$(docker-compose --version 2>/dev/null | grep -oP '\d+\.\d+' | head -1)
if [ ! -z "$COMPOSE_VERSION" ]; then
    MAJOR_VERSION=$(echo "$COMPOSE_VERSION" | cut -d. -f1)
    if [ "$MAJOR_VERSION" -lt 2 ]; then
        echo -e "${YELLOW}docker-compose v$MAJOR_VERSION.x found, it is too old (known 'ContainerConfig' bug). Upgrading...${NC}"
        sudo apt-get remove docker-compose -y 2>/dev/null || true
        sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
        sudo chmod +x /usr/local/bin/docker-compose
    fi
fi

# Prefer the docker compose v2 plugin if available
if docker compose version &> /dev/null; then
    COMPOSE_CMD="docker compose"
    echo -e "${GREEN}Using Docker Compose v2 (plugin)${NC}\n"
else
    COMPOSE_CMD="docker-compose"
    echo -e "${GREEN}Using docker-compose${NC}\n"
fi

# ==========================================
# 1. CONFIGURATION (ports, credentials)
# ==========================================
echo -e "${YELLOW}[1/9] Preparing configuration...${NC}"

APP_PORT="${APP_PORT:-8080}"
DB_EXTERNAL_PORT="${DB_EXTERNAL_PORT:-5432}"
DB_USER="opensparrow"
DB_NAME="opensparrow"
DB_PASSWORD=$(openssl rand -base64 32)

echo -e "  App port:        $APP_PORT"
echo -e "  DB external port: $DB_EXTERNAL_PORT"
echo -e "  DB name/user:    $DB_NAME / $DB_USER\n"

# ==========================================
# 2. BACKUP EXISTING DATABASE (if any)
# ==========================================
echo -e "${YELLOW}[2/9] Backing up existing database (if present)...${NC}"

BACKUP_DIR="./backups"
if [ "$SKIP_BACKUP" = true ]; then
    echo -e "${YELLOW}Skipped (--skip-backup)${NC}\n"
elif docker ps --format '{{.Names}}' | grep -q '^opensparrow-db$'; then
    mkdir -p "$BACKUP_DIR"
    TIMESTAMP=$(date +%Y%m%d_%H%M%S)
    BACKUP_FILE="$BACKUP_DIR/opensparrow_${TIMESTAMP}.sql.gz"

    # Reuse whatever credentials the running container already has
    OLD_DB_USER=$(docker exec opensparrow-db printenv POSTGRES_USER 2>/dev/null || echo "opensparrow")
    OLD_DB_NAME=$(docker exec opensparrow-db printenv POSTGRES_DB 2>/dev/null || echo "opensparrow")

    if docker exec opensparrow-db pg_dump -U "$OLD_DB_USER" "$OLD_DB_NAME" 2>/dev/null | gzip > "$BACKUP_FILE"; then
        echo -e "${GREEN}Backup saved: $BACKUP_FILE${NC}\n"
    else
        echo -e "${YELLOW}Backup attempt failed (container may not be healthy yet) - continuing anyway${NC}\n"
        rm -f "$BACKUP_FILE"
    fi
else
    echo -e "${GREEN}No existing opensparrow-db container found, nothing to back up${NC}\n"
fi

# ==========================================
# 3. CLEAN UP PRIOR INSTALLATION
# ==========================================
echo -e "${YELLOW}[3/9] Cleaning up any previous OpenSparrow stack...${NC}"

$COMPOSE_CMD down -v 2>/dev/null || true

for c in opensparrow-app opensparrow-db opensparrow-nginx opensparrow-cron opensparrow-init \
         opensparrow_app_1 opensparrow_db_1 opensparrow_nginx_1 opensparrow_cron_1; do
    docker rm -f "$c" 2>/dev/null || true
done

for v in opensparrow_pgdata opensparrow_pg_data opensparrow_app_storage "$(basename "$PWD")_appcode" "$(basename "$PWD")_pgdata"; do
    docker volume rm "$v" 2>/dev/null || true
done

docker rmi wrobeltom/opensparrow:latest 2>/dev/null || true
docker image prune -af --filter "dangling=true" 2>/dev/null || true

echo -e "${GREEN}Cleanup done${NC}\n"

# ==========================================
# 4. NGINX CONFIG
# ==========================================
echo -e "${YELLOW}[4/9] Writing nginx.conf...${NC}"

cat > nginx.conf << 'NGINX_EOF'
server {
    listen 80;
    root /var/www/html/public;
    index index.php;

    charset utf-8;
    charset_types text/html text/css application/javascript text/javascript application/json;

    error_page 404 /404.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    client_max_body_size 500M;

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_intercept_errors on;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 600;
        fastcgi_send_timeout 600;
    }

    location ~ /\.(?!well-known).* {
        deny all;
        access_log off;
        log_not_found off;
    }

    location = /favicon.ico {
        log_not_found off;
        access_log off;
    }

    location = /robots.txt {
        access_log off;
        log_not_found off;
    }

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-XSS-Protection "1; mode=block";
    add_header X-Content-Type-Options "nosniff";

    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml;
}
NGINX_EOF

echo -e "${GREEN}nginx.conf written${NC}\n"

# ==========================================
# 5. DOCKER COMPOSE CONFIG
# ==========================================
echo -e "${YELLOW}[5/9] Writing docker-compose.yml...${NC}"

cat > docker-compose.yml << 'COMPOSE_EOF'
services:
  init:
    image: wrobeltom/opensparrow:latest
    container_name: opensparrow-init
    volumes:
      - appcode:/target
    entrypoint: ["sh", "-c"]
    command: ["cp -a /var/www/html/. /target/"]
    restart: "no"

  db:
    image: postgres:16-alpine
    container_name: opensparrow-db
    restart: unless-stopped
    environment:
      POSTGRES_DB: ${DB_NAME}
      POSTGRES_USER: ${DB_USER}
      POSTGRES_PASSWORD: ${DB_PASSWORD}
      POSTGRES_INITDB_ARGS: "--encoding=UTF8 --locale=en_US.UTF-8"
    volumes:
      - pgdata:/var/lib/postgresql/data
    ports:
      - "${DB_EXTERNAL_PORT}:5432"
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${DB_USER}"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 20s
    networks:
      - opensparrow

  app:
    image: wrobeltom/opensparrow:latest
    container_name: opensparrow-app
    restart: unless-stopped
    environment:
      DB_HOST: db
      DB_PORT: 5432
      DB_NAME: ${DB_NAME}
      DB_USER: ${DB_USER}
      DB_PASSWORD: ${DB_PASSWORD}
      APP_ENV: production
      SECURE_COOKIES: 'false'
      SESSION_MAX_LIFETIME: '28800'
    volumes:
      - appcode:/var/www/html
    depends_on:
      db:
        condition: service_healthy
      init:
        condition: service_completed_successfully
    healthcheck:
      test: ["CMD-SHELL", "php-fpm -t || exit 1"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 20s
    networks:
      - opensparrow

  nginx:
    image: nginx:alpine
    container_name: opensparrow-nginx
    restart: unless-stopped
    ports:
      - "${APP_PORT}:80"
    volumes:
      - appcode:/var/www/html:ro
      - ./nginx.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      app:
        condition: service_started
    networks:
      - opensparrow

  cron:
    image: wrobeltom/opensparrow:latest
    container_name: opensparrow-cron
    restart: unless-stopped
    environment:
      DB_HOST: db
      DB_PORT: 5432
      DB_NAME: ${DB_NAME}
      DB_USER: ${DB_USER}
      DB_PASSWORD: ${DB_PASSWORD}
      APP_ENV: production
      SECURE_COOKIES: 'false'
      SESSION_MAX_LIFETIME: '28800'
    volumes:
      - appcode:/var/www/html
    command: >
      sh -c "echo '* * * * * php /var/www/html/cron/cron_notifications.php >> /var/log/cron.log 2>&1' | crontab - && crond -f"
    depends_on:
      db:
        condition: service_healthy
      init:
        condition: service_completed_successfully
    networks:
      - opensparrow

volumes:
  pgdata:
  appcode:

networks:
  opensparrow:
    driver: bridge
COMPOSE_EOF

echo -e "${GREEN}docker-compose.yml written${NC}\n"

# ==========================================
# 6. ENVIRONMENT FILE
# ==========================================
echo -e "${YELLOW}[6/9] Writing .env...${NC}"

cat > .env << EOF
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASSWORD=$DB_PASSWORD
DB_EXTERNAL_PORT=$DB_EXTERNAL_PORT
APP_PORT=$APP_PORT
EOF

chmod 600 .env

echo -e "${GREEN}.env written${NC}\n"

# ==========================================
# 7. START THE STACK
# ==========================================
echo -e "${YELLOW}[7/9] Starting Docker Compose...${NC}"
echo "(the 'init' service copies application code from the image into the 'appcode' volume)"

$COMPOSE_CMD up -d --build

echo -e "${GREEN}Containers started${NC}\n"

# ==========================================
# 8. WAIT FOR POSTGRES
# ==========================================
echo -e "${YELLOW}[8/9] Waiting for PostgreSQL...${NC}"

MAX_ATTEMPTS=60
ATTEMPT=0
while [ $ATTEMPT -lt $MAX_ATTEMPTS ]; do
    if docker exec opensparrow-db pg_isready -U "$DB_USER" &> /dev/null; then
        echo -e "${GREEN}PostgreSQL is ready${NC}"
        break
    fi
    ATTEMPT=$((ATTEMPT + 1))
    if [ $((ATTEMPT % 5)) -eq 0 ]; then
        echo "Waiting... ($ATTEMPT/$MAX_ATTEMPTS)"
    fi
    sleep 1
done

if [ $ATTEMPT -eq $MAX_ATTEMPTS ]; then
    echo -e "${RED}Timeout: PostgreSQL did not become ready${NC}"
    $COMPOSE_CMD logs db
    exit 1
fi

# ==========================================
# 9. WAIT FOR THE APP TO RESPOND OVER HTTP
# ==========================================
echo -e "${YELLOW}[9/9] Waiting for the application to respond on port $APP_PORT...${NC}"

HTTP_MAX_ATTEMPTS=40
HTTP_ATTEMPT=0
APP_UP=false
while [ $HTTP_ATTEMPT -lt $HTTP_MAX_ATTEMPTS ]; do
    HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:${APP_PORT}/setup.php" 2>/dev/null || echo "000")
    if [ "$HTTP_CODE" != "000" ]; then
        echo -e "${GREEN}Application responded with HTTP $HTTP_CODE${NC}"
        APP_UP=true
        break
    fi
    HTTP_ATTEMPT=$((HTTP_ATTEMPT + 1))
    if [ $((HTTP_ATTEMPT % 5)) -eq 0 ]; then
        echo "Waiting... ($HTTP_ATTEMPT/$HTTP_MAX_ATTEMPTS)"
    fi
    sleep 1
done

if [ "$APP_UP" = false ]; then
    echo -e "${YELLOW}Warning: the application did not respond in time - check logs below${NC}"
    $COMPOSE_CMD logs --tail=50 app nginx
fi

# ==========================================
# SUMMARY
# ==========================================
echo -e "\n${GREEN}========================================${NC}"
echo -e "${GREEN}OpenSparrow installation finished${NC}"
echo -e "${GREEN}========================================${NC}\n"

$COMPOSE_CMD ps

echo -e "\n${BLUE}Application access:${NC}"
echo -e "${GREEN}  http://SERVER_IP:$APP_PORT${NC}"
echo -e "${GREEN}  http://SERVER_IP:$APP_PORT/setup.php${NC}\n"

echo -e "${BLUE}Database:${NC}"
echo -e "  Inside Docker (host=db):  db:5432"
echo -e "  From outside (e.g. DBeaver): SERVER_IP:$DB_EXTERNAL_PORT"
echo -e "  Database: $DB_NAME"
echo -e "  User:     $DB_USER"
echo -e "  Password: see .env\n"

if [ "$SKIP_BACKUP" = false ] && [ -d "$BACKUP_DIR" ]; then
    echo -e "${BLUE}Backups:${NC}"
    echo -e "  $BACKUP_DIR/ (pre-wipe database dumps, if any were created)\n"
fi

echo -e "${BLUE}Config files:${NC}"
echo -e "  .env (contains the DB password - keep it safe)\n"

echo -e "${BLUE}Useful commands:${NC}"
echo -e "  $COMPOSE_CMD logs -f app       # PHP logs"
echo -e "  $COMPOSE_CMD logs -f nginx     # nginx logs"
echo -e "  $COMPOSE_CMD logs -f db        # PostgreSQL logs"
echo -e "  $COMPOSE_CMD ps                # Container status"
echo -e "  $COMPOSE_CMD restart           # Restart everything"
echo -e "  $COMPOSE_CMD down              # Stop the stack\n"

echo -e "${YELLOW}Next step:${NC}"
echo -e "  1. Open http://YOUR_SERVER_IP:$APP_PORT/setup.php"
echo -e "  2. Complete the setup wizard"
echo -e "  3. Log in to the application\n"
