# Block-BOT — PHP-FPM ilova tasviri (production, VPS-uslubidagi topologiya)
#
# public/ katalogi web-root, vendor/ src/ config/ bin/ storage/ esa undan
# tashqarida — aynan hozirgi ishlab turgan production (HestiaCP) tuzilmasi
# bilan bir xil, shu bois .htaccess'dagi yo'l bilan bog'liq xatolar
# konteynerlarda qaytarilmaydi.

FROM php:8.3-fpm-alpine AS base

# Runtime kutubxonalari: gd, zip, mysqli/pdo_mysql, intl, ffmpeg (video moderatsiyasi uchun)
RUN apk add --no-cache \
        icu-libs \
        libzip \
        libpng \
        libjpeg-turbo \
        freetype \
        ffmpeg \
        tzdata \
    && apk add --no-cache --virtual .build-deps \
        icu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_mysql \
        mysqli \
        gd \
        zip \
        mbstring \
        intl \
        opcache \
    && apk del .build-deps

# Ishlab chiqarish uchun tavsiya etilgan opcache sozlamalari
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.jit=1255'; \
        echo 'opcache.jit_buffer_size=64M'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini \
    && { \
        echo 'upload_max_filesize=25M'; \
        echo 'post_max_size=25M'; \
        echo 'memory_limit=256M'; \
        echo 'date.timezone=Asia/Tashkent'; \
    } > /usr/local/etc/php/conf.d/blockbot.ini

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Avval faqat composer manifestlari — qatlam keshini samarali ishlatish uchun
COPY composer.json composer.lock* ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --optimize-autoloader \
    || composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --optimize-autoloader

# Ilova kodining qolgan qismi
COPY . .

RUN mkdir -p storage/logs storage/temp storage/sessions \
    && touch storage/logs/.gitkeep storage/temp/.gitkeep storage/sessions/.gitkeep \
    && chown -R www-data:www-data /app \
    && chmod -R 775 storage

USER www-data

EXPOSE 9000

CMD ["php-fpm", "-F"]
