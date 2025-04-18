# Use Ubuntu 24.04 as the base image
FROM ubuntu:24.04

# Set environment variables
ENV DEBIAN_FRONTEND=noninteractive

# Install prerequisites and PHP packages
RUN <<"EOF"
    apt-get update
    apt-get install -y --no-install-recommends software-properties-common
    add-apt-repository ppa:ondrej/php
    apt-get install -y --no-install-recommends \
        acl \
        bash-completion \
        curl \
        git \
        gnupg2 \
        htop \
        jq \
        libasound2t64 \
        libatk-bridge2.0-0 \
        libatk1.0-0 \
        libcups2 \
        libdrm2 \
        libgbm1 \
        libvips \
        libxcomposite1 \
        libxdamage1 \
        libxfixes3 \
        libxkbcommon0 \
        libxrandr2 \
        lsb-release \
        nano \
        nginx \
        php8.4-cli \
        php8.4-curl \
        php8.4-exif \
        php8.4-ffi \
        php8.4-fileinfo \
        php8.4-fpm \
        php8.4-gd \
        php8.4-intl \
        php8.4-mbstring \
        php8.4-mysqli \
        php8.4-pgsql \
        php8.4-xdebug \
        php8.4-xml \
        php8.4-zip \
        sudo \
        unzip \
        wget
    apt-get clean
    rm -rf /var/lib/apt/lists/*
EOF

# Install Percona MySQL Server
RUN <<"EOF"
    wget https://repo.percona.com/apt/percona-release_latest.generic_all.deb
    dpkg -i percona-release_latest.generic_all.deb
    percona-release enable ps-84-lts
    apt-get install -y percona-server-server
    apt-get clean
    rm -rf /var/lib/apt/lists/*
EOF

# Install PostgreSQL
RUN <<"EOF"
    apt-get update
    apt-get install -y wget gnupg
    echo "deb http://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" > /etc/apt/sources.list.d/pgdg.list
    wget --quiet -O - https://www.postgresql.org/media/keys/ACCC4CF8.asc | apt-key add -
    apt-get update
    apt-get install -y postgresql-17 postgresql-client-17
    apt-get clean
    rm -rf /var/lib/apt/lists/*
EOF

# Install Composer globally
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install Bun
RUN curl -fsSL https://bun.sh/install | bash

# Add Bun to PATH
ENV BUN_INSTALL=/root/.bun
ENV PATH="${BUN_INSTALL}/bin:$PATH"

# Install NVM and Node.js
ENV NVM_DIR=/root/.nvm \
    NODE_VERSION=v20.19.0

RUN <<"EOF"
    # Fetch the latest NVM version dynamically
    NVM_VERSION=$(curl -sL https://api.github.com/repos/nvm-sh/nvm/releases/latest | jq -r .tag_name)
    echo "Installing NVM $NVM_VERSION"
    curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/$NVM_VERSION/install.sh | bash
    . "$NVM_DIR/nvm.sh"
    nvm install $NODE_VERSION
    nvm use $NODE_VERSION
    npm install -g npm@latest
    echo 'export NVM_DIR=$NVM_DIR' >> /root/.bashrc
    echo '[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"' >> /root/.bashrc
    echo '[ -s "$NVM_DIR/bash_completion" ] && \. "$NVM_DIR/bash_completion"' >> /root/.bashrc
EOF

# Add Node.js and NPM to PATH
ENV PATH="$NVM_DIR/versions/node/$NODE_VERSION/bin:$PATH"

# Append custom MySQL configuration
COPY mysql.conf /tmp/custom-mysql.conf
RUN cat /tmp/custom-mysql.conf >> /etc/mysql/my.cnf

# Setup MySQL user for 'www-data'
RUN <<"EOF"
    service mysql start
    mysql -u root -e "CREATE USER IF NOT EXISTS 'www-data'@'localhost' IDENTIFIED WITH auth_socket; GRANT ALL PRIVILEGES ON *.* TO 'www-data'@'localhost'; FLUSH PRIVILEGES;"
EOF

# Setup PostgreSQL user for 'www-data'
RUN <<"EOF"
    # Set listen_addresses to '' in postgresql.conf to disable networking
    sed -i "s/^#listen_addresses = 'localhost'/listen_addresses = ''/" /etc/postgresql/*/main/postgresql.conf

    # Modify pg_hba.conf to allow passwordless socket authentication for 'www-data'
    echo "local   all             www-data                                trust" >> /etc/postgresql/17/main/pg_hba.conf

    service postgresql start
    sudo -u postgres psql -c "CREATE USER \"www-data\"; ALTER USER \"www-data\" WITH SUPERUSER;"
EOF

# Remove Nginx default config
RUN rm /etc/nginx/sites-enabled/default

# Append custom Nginx configuration
COPY nginx.conf /tmp/custom-nginx.conf
RUN cat /tmp/custom-nginx.conf >> /etc/nginx/conf.d/nginx.conf && rm /tmp/custom-nginx.conf

# Append custom PHP-FPM configuration
COPY php-fpm.conf /tmp/custom-php-fpm.conf
RUN cat /tmp/custom-php-fpm.conf >> /etc/php/8.4/fpm/pool.d/www.conf

# Append custom PHP configuration
COPY custom-php.ini /tmp/custom-php.ini
RUN cat /tmp/custom-php.ini >> /etc/php/8.4/fpm/php.ini && rm /tmp/custom-php.ini

# Comment out opcache.jit=off in the opcache.ini file
RUN sed -i 's/^opcache.jit=off/;opcache.jit=off/' /etc/php/8.4/mods-available/opcache.ini

# Create opcache file cache folder
RUN mkdir /var/www/php_opcache

# Set git safe directory
RUN git config --global --add safe.directory /var/www/html/piwigo-fork

# Set up working directory
WORKDIR /var/www/html

# Copy entrypoint script and make it executable
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Set the entry point
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# Start services
CMD ["bash", "-c", "service mysql start && service postgresql start && service php8.4-fpm start && service nginx start && tail -f /dev/null"]
