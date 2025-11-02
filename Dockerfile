# Use official PHP image with extensions
FROM php:8.2-apache

# Set working directory
WORKDIR /var/www/html

# Install dependencies
RUN apt-get update && apt-get install -y \
    git zip unzip libpq-dev libpng-dev libonig-dev libxml2-dev curl \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql mbstring exif pcntl bcmath gd

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy all files
COPY . /var/www/html

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install PHP dependencies
RUN composer install --no-dev --no-interaction --optimize-autoloader

# Copy environment file if missing
RUN cp .env.example .env || true

# Generate application key
RUN php artisan key:generate

# Expose port
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
