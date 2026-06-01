FROM php:8.2-apache

RUN /path/to/httpd -l

COPY . /var/www/html/