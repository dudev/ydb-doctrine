FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
        git unzip zip libzip-dev libcurl4-openssl-dev zlib1g-dev g++ autoconf make \
    && docker-php-ext-install bcmath curl zip \
    && pecl channel-update pecl.php.net \
    && pecl install grpc \
    && docker-php-ext-enable grpc \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# ydb-platform/ydb-php-sdk pins google/protobuf ~3.15.8, which trips Composer's
# advisory block (PKSA-tcfz-w4fm-hhk9). That's an upstream SDK constraint, not
# something to fix here - disable the block for this test/dev image only.
RUN composer config --global policy.advisories.block false

WORKDIR /app
