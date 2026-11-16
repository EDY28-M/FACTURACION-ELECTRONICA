FROM php:8.3-fpm-alpine

# Install build and runtime dependencies
RUN apk add --no-cache \
    mariadb-client \
    openssl \
    icu-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libzip-dev \
    libxml2-dev \
    oniguruma-dev \
    git \
    curl \
    unzip \
    bash \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql gd zip bcmath soap intl opcache pcntl ftp

# Enable OpenSSL 3 legacy provider for SUNAT PKCS#12 (.pfx/.p12) certificates (RC2-40-CBC / 3DES)
RUN sed -i 's/^providers = provider_sect/providers = provider_sect\n\n[provider_sect]\ndefault = default_sect\nlegacy = legacy_sect\n\n[default_sect]\nactivate = 1\n\n[legacy_sect]\nactivate = 1\n\n[old_provider_sect]/' /etc/ssl/openssl.cnf

# Install Composer 2
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 9000
CMD ["php-fpm"]
