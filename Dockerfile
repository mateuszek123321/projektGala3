FROM php:8.1-apache

# Instalacja rozszerzeń PHP
RUN docker-php-ext-install pdo pdo_mysql

# Włączenie mod_rewrite dla Apache
RUN a2enmod rewrite

# Ustawienie uprawnień
RUN chown -R www-data:www-data /var/www/html

# Ustawienie zmiennej środowiskowej
ENV DOCKER_ENV=true

# Konfiguracja Apache
RUN echo '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/custom.conf \
    && a2enconf custom

WORKDIR /var/www/html