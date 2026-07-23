#!/bin/bash
#
# OpenSparrow - Production Installation Script
#
# Same overall flow as install.sh, but hardened for production:
#   - requires a pinned image tag (never "latest")
#   - forces SECURE_COOKIES=true (assumes TLS terminates in front of this stack)
#   - generates and requires IP_HASH_SALT (no safe default per CLAUDE.md)
#   - does NOT expose the PostgreSQL port to 0.0.0.0 by default (bind to
#     127.0.0.1 so only the host itself can reach it, e.g. via SSH tunnel)
#   - always takes a pre-wipe backup (no --skip-backup escape hatch)
#
# Usage:
#   IMAGE_TAG=sha-e76bf60 ./install-prod.sh
#   IMAGE_TAG=sha-e76bf60 APP_PORT=443 ./install-prod.sh
#
# You are expected to run a TLS-terminating reverse proxy (nginx/Caddy/Traefik
# with Let's Encrypt) in front of APP_PORT, or put APP_PORT behind a load
# balancer that handles HTTPS. This script does not manage certificates.
#
set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}OpenSparrow - Production Installation${NC}"
echo -e "${BLUE}========================================${NC}\n"

# ==========================================
# 0. REQUIRE A PINNED IMAGE TAG
# ==========================================
if [ -z "$IMAGE_TAG" ]; then
    echo -e "${RED}IMAGE_TAG is not set.${NC}"
    echo "Production installs must pin an explicit image tag, not 'latest'."
    echo "Find available tags at: https://hub.docker.com/r/wrobeltom/opensparrow/tags"
    echo ""
    echo "Usage:"
    echo "  IMAGE_TAG=sha-e76bf60 ./install-prod.sh"
    exit 1
fi

if [ "$IMAGE_TAG" = "latest" ]; then
    echo -e "${RED}IMAGE_TAG=latest is not allowed for production installs.${NC}"
    echo "Pin an explicit tag/SHA so upgrades are deliberate, not accidental."
    exit 1
fi

IMAGE="wrobeltom/opensparrow:${IMAGE_TAG}"

# ==========================================
# 1. CHECK DOCKER
# ==========================================
echo -e "${YELLOW}[1/9] Checking Docker...${NC}"

if ! command -v docker &> /dev/null; then
    echo -e "${RED}Docker is not installed.${NC}"
    echo "Install it first, e.g.: curl -fsSL https://get.docker.com | sh"
    exit 1
fi

COMPOSE_VERSION=$(docker-compose --version 2>/dev/null | grep -oP '\d+\.\d+' | head -1)
if [ ! -z "$COMPOSE_VERSION" ]; then
    MAJOR_VERSION=$(echo "$COMPOSE_VERSION" | cut -d. -f1)
    if [ "$MAJOR_VERSION" -lt 2 ]; then
        echo -e "${YELLOW}docker-compose v$MAJOR_VERSION.x found, upgrading (known 'ContainerConfig' bug)...${NC}"
        sudo apt-get remove docker-compose -y 2>/dev/null || true
        sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
        sudo chmod +x /usr/local/bin/docker-compose
    fi
fi

if docker compose version &> /dev/null; then
    COMPOSE_CMD="docker compose"
else
    COMPOSE_CMD="docker-compose"
fi
echo -e "${GREEN}Using: $COMPOSE_CMD${NC}"
echo -e "${GREEN}Image: $IMAGE${NC}\n"

# ==========================================
# 2. CONFIGURATION
# ==========================================
echo -e "${YELLOW}[2/9] Preparing configuration...${NC}"

APP_PORT="${APP_PORT:-8080}"
# Bound to 127.0.0.1 by default: reach it only via SSH tunnel or from the
# host itself. Set DB_BIND_ADDR=0.0.0.0 explicitly if you really need the
# database reachable from outside this host (not recommended).
DB_BIND_ADDR="${DB_BIND_ADDR:-127.0.0.1}"
DB_EXTERNAL_PORT="${DB_EXTERNAL_PORT:-5432}"
DB_USER="opensparrow"
DB_NAME="opensparrow"
DB_PASSWORD=$(openssl rand -base64 32)
IP_HASH_SALT=$(openssl rand -base64 32)

echo -e "  Image:            $IMAGE"
echo -e "  App port:         $APP_PORT"
echo -e "  DB bind address:  $DB_BIND_ADDR:$DB_EXTERNAL_PORT"
echo -e "  DB name/user:     $DB_NAME / $DB_USER"
echo -e "  SECURE_COOKIES:   true (requires TLS in front of this stack)\n"

read -p "Continue with this configuration? [y/N] " CONFIRM
if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
    echo "Aborted."
    exit 0
fi

# ==========================================
# 3. BACKUP EXISTING DATABASE (always, no skip flag)
# ==========================================
echo -e "\n${YELLOW}[3/9] Backing up existing database (if present)...${NC}"

BACKUP_DIR="./backups"
if docker ps --format '{{.Names}}' | grep -q '^opensparrow-db$'; then
    mkdir -p "$BACKUP_DIR"
    TIMESTAMP=$(date +%Y%m%d_%H%M%S)
    BACKUP_FILE="$BACKUP_DIR/opensparrow_prod_${TIMESTAMP}.sql.gz"

    OLD_DB_USER=$(docker exec opensparrow-db printenv POSTGRES_USER 2>/dev/null || echo "opensparrow")
    OLD_DB_NAME=$(docker exec opensparrow-db printenv POSTGRES_DB 2>/dev/null || echo "opensparrow")

    if docker exec opensparrow-db pg_dump -U "$OLD_DB_USER" "$OLD_DB_NAME" 2>/dev/null | gzip > "$BACKUP_FILE"; then
        echo -e "${GREEN}Backup saved: $BACKUP_FILE${NC}"
        echo -e "${YELLOW}Copy this off-host before proceeding if this data matters.${NC}\n"
    else
        echo -e "${RED}Backup attempt failed.${NC}"
        read -p "Continue anyway WITHOUT a backup? [y/N] " FORCE
        if [[ ! "$FORCE" =~ ^[Yy]$ ]]; then
            echo "Aborted."
            exit 1
        fi
        rm -f "$BACKUP_FILE"
    fi
else
    echo -e "${GREEN}No existing opensparrow-db container found, nothing to back up${NC}\n"
fi

# ==========================================
# 4. CLEAN UP PRIOR INSTALLATION
# ==========================================
echo -e "${YELLOW}[4/9] Cleaning up any previous OpenSparrow stack...${NC}"

$COMPOSE_CMD down -v 2>/dev/null || true

for c in opensparrow-app opensparrow-db opensparrow-nginx opensparrow-cron opensparrow-init; do
    docker rm -f "$c" 2>/dev/null || true
done

for v in opensparrow_pgdata "$(basename "$PWD")_appcode" "$(basename "$PWD")_pgdata"; do
    docker volume rm "$v" 2>/dev/null || true
done

docker image prune -af --filter "dangling=true" 2>/dev/null || true

echo -e "${GREEN}Cleanup done${NC}\n"

# ==========================================
# 5. NGINX CONFIG
# ==========================================
echo -e "${YELLOW}[5/9] Writing nginx.conf...${NC}"

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
# 6. DOCKER COMPOSE CONFIG
# ==========================================
echo -e "${YELLOW}[6/9] Writing docker-compose.yml...${NC}"

cat > docker-compose.yml << COMPOSE_EOF
services:
  init:
    image: ${IMAGE}
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
      POSTGRES_DB: \${DB_NAME}
      POSTGRES_USER: \${DB_USER}
      POSTGRES_PASSWORD: \${DB_PASSWORD}
      POSTGRES_INITDB_ARGS: "--encoding=UTF8 --locale=en_US.UTF-8"
    volumes:
      - pgdata:/var/lib/postgresql/data
    ports:
      - "\${DB_BIND_ADDR}:\${DB_EXTERNAL_PORT}:5432"
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U \${DB_USER}"]
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 20s
    networks:
      - opensparrow

  app:
    image: ${IMAGE}
    container_name: opensparrow-app
    restart: unless-stopped
    environment:
      DB_HOST: db
      DB_PORT: 5432
      DB_NAME: \${DB_NAME}
      DB_USER: \${DB_USER}
      DB_PASSWORD: \${DB_PASSWORD}
      APP_ENV: production
      SECURE_COOKIES: 'true'
      IP_HASH_SALT: \${IP_HASH_SALT}
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
      - "\${APP_PORT}:80"
    volumes:
      - appcode:/var/www/html:ro
      - ./nginx.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      app:
        condition: service_started
    networks:
      - opensparrow

  cron:
    image: ${IMAGE}
    container_name: opensparrow-cron
    restart: unless-stopped
    environment:
      DB_HOST: db
      DB_PORT: 5432
      DB_NAME: \${DB_NAME}
      DB_USER: \${DB_USER}
      DB_PASSWORD: \${DB_PASSWORD}
      APP_ENV: production
      SECURE_COOKIES: 'true'
      IP_HASH_SALT: \${IP_HASH_SALT}
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

echo -e "${GREEN}docker-compose.yml written (pinned to $IMAGE)${NC}\n"

# ==========================================
# 7. ENVIRONMENT FILE
# ==========================================
echo -e "${YELLOW}[7/9] Writing .env...${NC}"

cat > .env << EOF
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASSWORD=$DB_PASSWORD
DB_BIND_ADDR=$DB_BIND_ADDR
DB_EXTERNAL_PORT=$DB_EXTERNAL_PORT
APP_PORT=$APP_PORT
IP_HASH_SALT=$IP_HASH_SALT
EOF

chmod 600 .env

echo -e "${GREEN}.env written (mode 600)${NC}\n"

# ==========================================
# 8. START THE STACK
# ==========================================
echo -e "${YELLOW}[8/9] Starting Docker Compose...${NC}"

docker pull "$IMAGE"
$COMPOSE_CMD up -d

echo -e "${GREEN}Containers started${NC}\n"

# ==========================================
# 9. WAIT FOR POSTGRES + APP
# ==========================================
echo -e "${YELLOW}[9/9] Waiting for PostgreSQL and the application...${NC}"

MAX_ATTEMPTS=60
ATTEMPT=0
while [ $ATTEMPT -lt $MAX_ATTEMPTS ]; do
    if docker exec opensparrow-db pg_isready -U "$DB_USER" &> /dev/null; then
        echo -e "${GREEN}PostgreSQL is ready${NC}"
        break
    fi
    ATTEMPT=$((ATTEMPT + 1))
    sleep 1
done

if [ $ATTEMPT -eq $MAX_ATTEMPTS ]; then
    echo -e "${RED}Timeout: PostgreSQL did not become ready${NC}"
    $COMPOSE_CMD logs db
    exit 1
fi

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
echo -e "${GREEN}OpenSparrow production install finished${NC}"
echo -e "${GREEN}========================================${NC}\n"

$COMPOSE_CMD ps

echo -e "\n${RED}IMPORTANT - this stack is HTTP only on port $APP_PORT.${NC}"
echo -e "${RED}Put a TLS-terminating reverse proxy (nginx/Caddy/Traefik + Let's Encrypt)${NC}"
echo -e "${RED}in front of it before exposing this to the public internet.${NC}"
echo -e "${RED}SECURE_COOKIES=true will otherwise block session cookies over plain HTTP.${NC}\n"

echo -e "${BLUE}Database:${NC}"
echo -e "  Inside Docker (host=db):     db:5432"
echo -e "  From this host only:         $DB_BIND_ADDR:$DB_EXTERNAL_PORT"
echo -e "  Database: $DB_NAME"
echo -e "  User:     $DB_USER"
echo -e "  Password: see .env\n"

echo -e "${BLUE}Backups:${NC}"
echo -e "  $BACKUP_DIR/ - copy these off-host regularly, this script only takes"
echo -e "  a dump immediately before wiping an existing install, it is not a"
echo -e "  substitute for a scheduled backup/retention policy.\n"

echo -e "${BLUE}Config files:${NC}"
echo -e "  .env (contains DB password + IP_HASH_SALT - keep it safe, back it up"
echo -e "  separately; losing IP_HASH_SALT invalidates existing rate-limit state)\n"

echo -e "${BLUE}Useful commands:${NC}"
echo -e "  $COMPOSE_CMD logs -f app       # PHP logs"
echo -e "  $COMPOSE_CMD logs -f nginx     # nginx logs"
echo -e "  $COMPOSE_CMD logs -f db        # PostgreSQL logs"
echo -e "  $COMPOSE_CMD ps                # Container status"
echo -e "  $COMPOSE_CMD down              # Stop the stack\n"

echo -e "${YELLOW}Next step:${NC}"
echo -e "  1. Configure your TLS reverse proxy to point at this host's $APP_PORT"
echo -e "  2. Open https://your-domain/setup.php through that proxy"
echo -e "  3. Complete the setup wizard and log in\n"
