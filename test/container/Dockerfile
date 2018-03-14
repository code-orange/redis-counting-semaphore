FROM shippingdocker/php-composer:latest
COPY . /usr/src/myapp
WORKDIR /usr/src/myapp
CMD composer install && php ./index.php
