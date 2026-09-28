FROM php:8.3-apache

# Install sistem dasar yang dibutuhin ekstensi PHP dan Composer
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libonig-dev \
    libxml2-dev \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Install ekstensi PHP yang di-request 
# (Catatan: ekstensi 'json' sudah tertanam permanen sejak PHP 8.0)
RUN docker-php-ext-install mysqli pdo_mysql curl mbstring xml sockets

# Tarik dan pasang Composer versi 2.8+
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Aktifkan mod_rewrite Apache (biasanya wajib untuk routing PHP)
RUN a2enmod rewrite

RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
