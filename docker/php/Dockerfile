FROM php:8.4-fpm

# System deps
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    ffmpeg \
    libzip-dev \
    libonig-dev \
    libpng-dev \
    libxml2-dev \
    python3 \
    python3-pip \
    pipx \
    docker.io \
  && docker-php-ext-install pdo pdo_mysql zip mbstring exif pcntl \
  && rm -rf /var/lib/apt/lists/*

# Install Edge TTS and yt-dlp (PEP 668 safe) and expose them on the default PATH
ENV PIPX_HOME=/opt/pipx
ENV PIPX_BIN_DIR=/usr/local/bin
RUN pipx install edge-tts && pipx install yt-dlp

# (Optional) sanity checks at build time
RUN command -v edge-tts && edge-tts --version && command -v yt-dlp && yt-dlp --version && ffmpeg -version | head -n 1

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

CMD ["php-fpm"]
