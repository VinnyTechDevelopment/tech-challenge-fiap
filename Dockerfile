FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    zip \
    unzip \
&& docker-php-ext-configure gd --with-freetype --with-jpeg \
&& docker-php-ext-install -j$(nproc) pdo_mysql mbstring exif pcntl bcmath gd zip opcache \
&& pecl install pcov \
&& docker-php-ext-enable pcov \
&& rm -rf /var/lib/apt/lists/*

# Agente PHP do New Relic. Fica instalado mas inerte enquanto
# NEWRELIC_LICENSE_KEY não for setada em runtime (ver docker/entrypoint.sh) —
# não há conta/License Key ainda, mas deixamos pronto pra ligar (ver README,
# seção Observabilidade).
RUN curl -sSL https://download.newrelic.com/548C16BF.gpg -o /etc/apt/trusted.gpg.d/newrelic.gpg \
 && echo "deb http://apt.newrelic.com/debian/ newrelic non-free" > /etc/apt/sources.list.d/newrelic.list \
 && apt-get update \
 && NR_INSTALL_SILENT=1 apt-get install -y newrelic-php5 \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --no-interaction --prefer-dist --no-progress

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
