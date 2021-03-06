FROM php:5.6
ENV DEBIAN_FRONTEND noninteractive

RUN apt-get update -q \
  && apt-get install unzip git libmcrypt-dev zlib1g-dev -y

RUN docker-php-ext-install mcrypt zip

RUN cd \
  && curl -sS https://getcomposer.org/installer | php \
  && ln -s /root/composer.phar /usr/local/bin/composer 
