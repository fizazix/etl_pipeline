FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY public/index.php public/index.php
COPY data/records.php data/records.php

EXPOSE 8081

CMD ["php", "-S", "0.0.0.0:8081", "-t", "public", "public/index.php"]
