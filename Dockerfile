FROM php:7.4-cli
ENV COMPOSER_ALLOW_SUPERUSER 1
ARG DEBIAN_FRONTEND=noninteractive
ARG COMPOSER_FLAGS="--prefer-dist --no-interaction"

RUN apt-get update -q \
    && apt-get install -y --no-install-recommends \
        apt-transport-https \
        ca-certificates \
        git \
        gnupg2 \
        libmcrypt-dev \
        libpq-dev \
        software-properties-common \
        sudo \
        unzip \
        libzip-dev \
        wget \
        iproute2 \
    && rm -rf /var/lib/apt/lists/*

# install composer
RUN cd \
  && curl -sS https://getcomposer.org/installer | php \
  && ln -s /root/composer.phar /usr/local/bin/composer

# install docker
RUN wget https://download.docker.com/linux/debian/gpg \
    && sudo apt-key add gpg \
    && echo "deb [arch=amd64] https://download.docker.com/linux/debian $(lsb_release -cs) stable" | sudo tee -a /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-cache policy docker-ce \
    && apt-get -y install docker-ce \
    && rm -rf /var/lib/apt/lists/*    

# install extensions
RUN docker-php-ext-install zip
RUN pecl channel-update pecl.php.net \
    && pecl config-set php_ini /usr/local/etc/php.ini \
    && yes | pecl install xdebug-2.9.8 \
    && docker-php-ext-enable xdebug

WORKDIR /code/

COPY composer.* /code/
RUN composer install $COMPOSER_FLAGS --no-scripts --no-autoloader

# copy rest of the app
COPY . /code/

# run normal composer - all deps are cached already
RUN composer install $COMPOSER_FLAGS --no-scripts

CMD ["php", "/code/vendor/phpunit/phpunit/phpunit"]
