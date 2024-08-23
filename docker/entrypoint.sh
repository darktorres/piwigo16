#!/bin/bash

echo "[entrypoint] Starting entrypoint script"

# Clone Piwigo if it doesn't exist
if [ ! -d "piwigo-fork/.git" ]; then
    echo "[entrypoint] Piwigo fork not found. Cloning repository..."

    git clone --branch 14.x https://github.com/darktorres/piwigo temp
    if [ $? -ne 0 ]; then
        echo "[entrypoint] Failed to clone repository."
        exit 1
    fi

    echo "[entrypoint] Copying files from temp to piwigo-fork..."
    cp -a temp/. piwigo-fork
    rm -rf temp/
    
    echo "[entrypoint] Entering piwigo-fork directory"
    cd piwigo-fork || { echo "[entrypoint] Failed to enter piwigo-fork directory"; exit 1; }

    echo "[entrypoint] Running composer install..."
    composer install || { echo "[entrypoint] composer install failed"; exit 1; }

    echo "[entrypoint] Running bun install..."
    bun install || { echo "[entrypoint] bun install failed"; exit 1; }

    echo "[entrypoint] Setting ownership to www-data... (except /galleries)"
    find . -path ./galleries -prune -o -exec chown www-data:www-data {} +

    echo "[entrypoint] Setting default ACLs for www-data... (except /galleries)"
    find . -path ./galleries -prune -o -type d -exec setfacl -d -m u:www-data:rwx {} +
    find . -path ./galleries -prune -o -exec setfacl -m u:www-data:rwx {} +

else
    echo "[entrypoint] piwigo-fork already exists, skipping clone."
fi

echo "[entrypoint] Executing CMD: $*"
exec "$@"
