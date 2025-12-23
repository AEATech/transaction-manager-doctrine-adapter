FROM php:8.4.11-cli-bullseye

RUN apt-get update && \
    apt-get install -y libzip-dev wget git libssh2-1 default-mysql-client libpq-dev postgresql-client

# Install PHP core extensions
RUN docker-php-ext-install pdo_mysql pdo_pgsql opcache

# Install PHP pecl extensions
RUN pecl install zip

# Enable extensions
RUN docker-php-ext-enable zip

COPY php.ini /usr/local/etc/php/conf.d/99-bench.ini

ARG OWNER_USER
ARG OWNER_USER_ID

# Add owner user if not exists
RUN /bin/bash -c 'useradd -u ${OWNER_USER_ID} ${OWNER_USER}'

# Change user
USER ${OWNER_USER}
