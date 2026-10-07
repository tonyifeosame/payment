# L6: the base image is pinned to a FrankenPHP major and PHP minor rather than
# `latest`, so a rebuild cannot silently change the PHP version under the app.
# PHP 8.4 is inside the CI matrix (8.2 and 8.4).
FROM dunglas/frankenphp:1-php8.4

ARG USER=appuser

RUN \
    useradd ${USER} && \
    setcap -r /usr/local/bin/frankenphp && \
    chown -R ${USER}:${USER} /data/caddy && \
    chown -R ${USER}:${USER} /config/caddy

# L6: the extension installer comes from its published image at a pinned major
# version, not from a moving "latest" release download.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
    bcmath \
    gd \
    intl \
    opcache \
    pcntl \
    pdo_pgsql \
    pdo_sqlite \
    redis \
    zip

RUN apt-get update && apt-get install -y --no-install-recommends \
    supervisor \
    git \
    curl \
    unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN mkdir -p /var/log/supervisor /app/config /app/data

ENV XDG_CONFIG_HOME=/app/config
ENV XDG_DATA_HOME=/app/data
ENV SERVER_NAME=:8000
ENV OCTANE_SERVER=frankenphp

WORKDIR /app
COPY . /app
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/php.ini
COPY docker/docker-entrypoint.sh /docker-entrypoint.sh

RUN composer install --no-dev --no-interaction --optimize-autoloader --no-scripts --no-progress \
    && rm -rf /root/.composer/cache

RUN mkdir -p \
        /app/storage/logs \
        /app/storage/framework/cache/data \
        /app/storage/framework/sessions \
        /app/storage/framework/views \
        /app/storage/app/public \
        /app/bootstrap/cache && \
    php artisan optimize && \
    php artisan storage:link && \
    echo "yes" | php artisan octane:install --server=frankenphp

# L6: no world-writable directories. The application user owns the tree; only
# storage/ and bootstrap/cache/ (logs, caches, compiled views, the cached config
# the entrypoint writes) and the .env the entrypoint generates need writing.
RUN chown -R ${USER}:${USER} /app /var/log/supervisor && \
    chmod -R u+rwX,g+rX,o-w /app && \
    chmod -R ug+rwX /app/storage /app/bootstrap/cache && \
    chmod +x /app/artisan /docker-entrypoint.sh /usr/local/bin/frankenphp

# Only the application port. Caddy's admin API stays on localhost inside the
# container (Octane binds it there) and is not published.
EXPOSE 8000

HEALTHCHECK --interval=10s --timeout=5s CMD curl -fsS http://localhost:8000/up || exit 1

USER ${USER}
ENTRYPOINT ["/docker-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
