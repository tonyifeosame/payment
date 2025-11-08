FROM dunglas/frankenphp

ARG USER=appuser

RUN \
    useradd ${USER} && \
    setcap -r /usr/local/bin/frankenphp && \
    chown -R ${USER}:${USER} /data/caddy && \
    chown -R ${USER}:${USER} /config/caddy

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions

RUN install-php-extensions \
    bcmath \
    gd \
    gnupg \
    intl \
    opcache \
    pcntl \
    pdo_pgsql \
    pdo_sqlite \
    redis \
    zip

RUN apt-get update && apt-get install -y \
    bash \
    supervisor \
    git \
    curl \
    unzip \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

RUN mkdir -p /var/log/supervisor /app/storage/logs /app/config /app/data

ENV XDG_CONFIG_HOME=/app/config
ENV XDG_DATA_HOME=/app/data
ENV SERVER_NAME=:8000
ENV OCTANE_SERVER=frankenphp

WORKDIR /app
COPY . /app
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/php.ini
COPY docker/docker-entrypoint.sh /docker-entrypoint.sh

RUN chmod -R 777 /app/storage /app/bootstrap/cache && \
    chmod -R 777 /app/public && \
    chown -R ${USER}:${USER} /app && \
    chown -R ${USER}:${USER} /var/log/supervisor && \
    chown -R ${USER}:${USER} /var/run && \
    chmod +x /app/artisan

RUN composer install --no-dev --no-interaction --optimize-autoloader --no-scripts

RUN rm -rf /app/bootstrap/cache/* && \
    rm -rf /app/storage/framework/cache/* && \
    rm -rf /app/storage/framework/config/* && \
    rm -rf /app/storage/framework/views/* && \
    rm -rf /app/storage/framework/sessions/* && \
    rm -rf /app/storage/framework/testing/* && \
    rm -rf /app/storage/framework/views/* && \
    rm -rf /app/storage/framework/views/* && \
    php artisan optimize && \
    php artisan storage:link && \
    echo "yes" | php artisan octane:install --server=frankenphp

RUN chmod +x /usr/local/bin/frankenphp

EXPOSE 8000
EXPOSE 443
EXPOSE 2019

HEALTHCHECK --interval=10s --timeout=5s CMD curl -f http://localhost:8000/ || exit 1

RUN chmod +x /docker-entrypoint.sh
USER ${USER}
ENTRYPOINT ["/docker-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
