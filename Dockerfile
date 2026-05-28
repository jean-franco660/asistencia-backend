FROM php:8.3-cli

# Install system deps
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libssl-dev \
    wget \
    ca-certificates \
    procps \
    gettext-base \
  && docker-php-ext-install pdo_mysql mbstring xml zip

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files first and install to leverage cache
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Copy app
COPY . ./

# Ensure storage and bootstrap cache dirs exist with proper permissions
RUN mkdir -p storage/framework storage/logs bootstrap/cache && \
    chown -R www-data:www-data storage bootstrap/cache || true

# Copy entrypoint
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENV PORT=8080
EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:${PORT}", "-t", "public"]
