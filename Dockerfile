FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
	&& a2enmod headers rewrite proxy proxy_http proxy_wstunnel \
	&& rm -rf /var/lib/apt/lists/*

COPY apache-security.conf /etc/apache2/conf-available/security-hardening.conf
COPY mqtt-proxy.conf /etc/apache2/conf-available/mqtt-proxy.conf
RUN a2enconf security-hardening mqtt-proxy
