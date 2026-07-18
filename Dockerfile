FROM php:8.3-cli-alpine
RUN docker-php-ext-install pdo_mysql
ENV PHP_CLI_SERVER_WORKERS=10
WORKDIR /app
COPY engine.php /app/engine.php
EXPOSE 8080
# -t serves engine.php as router for all paths
CMD ["php", "-S", "0.0.0.0:8080", "engine.php"]
