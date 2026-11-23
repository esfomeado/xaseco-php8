# The old production environment (tmnf_server main): Ubuntu 14.04 + PHP 5.4.45 from source
FROM ubuntu:14.04
ENV PHP_VERSION=5.4.45
RUN apt-get update && apt-get install -y \
    build-essential autoconf bison re2c libxml2-dev libcurl4-openssl-dev \
    libjpeg-dev libpng-dev libfreetype6-dev libmysqlclient-dev libssl-dev \
    zlib1g-dev wget \
    && rm -rf /var/lib/apt/lists/*
RUN wget -q https://museum.php.net/php5/php-${PHP_VERSION}.tar.gz -O /tmp/php.tar.gz \
    && cd /tmp && tar xzf php.tar.gz && cd php-${PHP_VERSION} \
    && ./configure --prefix=/usr/local/php5 --with-mysql --with-mysqli --enable-mbstring \
        --with-curl --with-openssl --with-zlib --enable-sockets --enable-soap --with-gd \
        --with-jpeg-dir=/usr --with-png-dir=/usr --with-freetype-dir=/usr --enable-bcmath > /dev/null \
    && make -j$(nproc) > /dev/null 2>&1 && make install > /dev/null && cd / && rm -rf /tmp/php*
ENV PATH="/usr/local/php5/bin:${PATH}"
RUN php -v
