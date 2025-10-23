# Dockerfile for pastebin/notes application
#
# Required volumes:
#   - `/app/public/notes`  - notes data persistence
#   - `/app/config`        - configuration files (optional, can use defaults)
#

FROM shanemcc/docker-apache-php-base:latest

COPY . /app

WORKDIR /app

RUN \
  rm -Rfv /var/www/html && \
  ln -s /app/public /var/www/html && \
  mkdir -p /app/public/notes /app/config && \
  curl -sS https://getcomposer.org/installer | php -- --no-ansi --install-dir=/usr/bin --filename=composer && \
  composer install && \
  chown -Rfv www-data: /app/ /var/www/
