#!/bin/bash
set -e

WEB_PORT=${WEB_PORT:-80}

echo "Configuring Apache to listen on port ${WEB_PORT}..."

# Remove default port 80 from ports.conf
echo "# Managed by entrypoint" > /etc/apache2/ports.conf
echo "Listen ${WEB_PORT}" >> /etc/apache2/ports.conf

# Create VirtualHost config
cat > /etc/apache2/sites-available/000-default.conf << APACHE_CONF
<VirtualHost *:${WEB_PORT}>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html

    <Directory /var/www/html>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
APACHE_CONF

echo "Apache configured to listen on port ${WEB_PORT}"

exec apache2-foreground
