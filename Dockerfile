FROM php:8.3-cli

# Install system deps
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libssl-dev \
    wget \
    ca-certificates \
    procps \
    gettext-base \
  && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
  && docker-php-ext-install -j"$(nproc)" gd pdo_mysql mbstring xml zip \
  && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Create non-root user for running Composer and the app
RUN groupadd -g 1000 app || true \
  && useradd -u 1000 -m -g app -s /bin/sh app || true \
  && mkdir -p /home/app/.composer && chown -R app:app /home/app

# Copy application source first so composer scripts can access artisan and config files
COPY . ./

# Ensure storage and bootstrap cache dirs exist with proper permissions before Composer runs
RUN mkdir -p storage/framework storage/logs bootstrap/cache && \
  chown -R app:app storage bootstrap/cache || true

# Copy composer files and install as non-root to leverage cache and improve security
COPY composer.json composer.lock ./
RUN chown app:app composer.json composer.lock && \
  su -s /bin/sh app -c "composer install --no-dev --optimize-autoloader --no-interaction --no-progress"

# Copy entrypoint
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENV PORT=8080
EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:${PORT}", "-t", "public"]
USER app
