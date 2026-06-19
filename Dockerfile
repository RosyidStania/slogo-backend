FROM php:8.5-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    git \
    curl \
    libonig-dev \
    libxml2-dev

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# We don't copy the code here because we are using a bind mount in development
# from docker-compose.yml. In production, we would COPY . . and RUN composer install.

EXPOSE 8000

# Run the development server
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
