# Optimized PHP 8.4 FPM image for OpenSparrow using Alpine Linux
# This image is approximately 150MB, compared to 1GB+ for standard Debian-based images.
FROM php:8.4-fpm-alpine

# Upgrade existing packages to prevent musl library conflicts during build
# Install system-level dependencies for PostgreSQL
# We use a virtual build-dependency group (.build-deps) to keep the final image slim.
# libpq is a runtime dependency (must stay), while postgresql-dev is only for compilation.
RUN apk upgrade --no-cache \
    && apk add --no-cache --virtual .build-deps postgresql-dev \
    && apk add --no-cache libpq \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    # Cleanup: remove build-only packages and temporary files to reduce image size
    && apk del .build-deps \
    && rm -rf /tmp/pear

# Copy custom PHP configuration for development environment
# Ensure docker-php-dev.ini exists in your project root
COPY docker-php-dev.ini "$PHP_INI_DIR/conf.d/dev.ini"

# Set the working directory for the application
WORKDIR /var/www/html

# Copy project files with the correct ownership
# www-data is the default user for PHP-FPM in official images
# Note: This is crucial for security and file write permissions
COPY --chown=www-data:www-data . .

# PHP-FPM default port
EXPOSE 9000

# Start the PHP FastCGI Process Manager
CMD ["php-fpm"]