FROM ubuntu:26.04
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends \
    php8.5-cli php8.5-mysql php8.5-xml php8.5-mbstring php8.5-curl \
    && rm -rf /var/lib/apt/lists/*
