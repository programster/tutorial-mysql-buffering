FROM ubuntu:20.04

RUN apt-get update && apt-get dist-upgrade -y

# install php 8
RUN apt install -y software-properties-common apt-transport-https \
  && add-apt-repository ppa:ondrej/php -y \
  && apt update \
  && apt install php8.0-cli -y

RUN apt-get install php8.0-pgsql -y


CMD ["php", "/root/app/main.php"]