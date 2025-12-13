FROM php:8-apache

RUN apt-get update && apt-get install -y rrdtool

WORKDIR /var/www/html

COPY *.php ./
COPY --chmod=+x entrypoint.sh ./

EXPOSE 80

ENTRYPOINT ["./entrypoint.sh"]
