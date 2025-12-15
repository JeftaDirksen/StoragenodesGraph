FROM php:8-apache

RUN apt-get update && apt-get install -y rrdtool

WORKDIR /var/www/html

COPY index.html ./
COPY collector.php ./
COPY --chmod=+x entrypoint.sh ./
COPY favicon.png ./

EXPOSE 80

ENTRYPOINT ["./entrypoint.sh"]
