FROM php:8.2-apache

# Install dependencies, git, and PHP sockets extension
RUN apt-get update && \
    apt-get install -y --no-install-recommends curl git && \
    docker-php-ext-install sockets && \
    rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite

# Set proper permissions for web directory
RUN chown -R www-data:www-data /var/www/html

# Create banners directory with proper permissions
RUN mkdir -p /var/www/html/banners && \
    chmod 777 /var/www/html/banners && \
    chown -R www-data:www-data /var/www/html/banners

WORKDIR /var/www/html

# Copy application files
COPY www/ /var/www/html/

# Clone BFBC2 assets from GitHub and copy to static folder
RUN git clone --depth 1 --filter=blob:none --sparse https://github.com/AdKats/Procon-1.git /tmp/procon && \
    cd /tmp/procon && \
    git sparse-checkout set src/Resources/Archive/Media/BFBC2 && \
    mkdir -p /var/www/html/static && \
    cp -r src/Resources/Archive/Media/BFBC2 /var/www/html/static/ && \
    cd / && \
    rm -rf /tmp/procon

# Copy custom entrypoint script
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Ensure correct permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html && \
    chmod 777 /var/www/html/banners

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
