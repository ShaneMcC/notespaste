# Dockerfile for pastebin/notes application
#
# Required volumes:
#   - `/app/public/notes`  - notes data persistence
#   - `/app/config`        - configuration files (optional, can use defaults)
#

FROM shanemcc/docker-apache-php-base:latest

COPY . /app

RUN \
  rm -Rfv /var/www/html && \
  ln -s /app/public /var/www/html && \
  mkdir -p /app/public/notes /app/config && \
  chown -Rfv www-data: /app/ /var/www/
