# syntax=docker/dockerfile:1
# Multi-stage build — see docs/PLAN-REPLAY.md P4 and docs/DEPLOYMENT.md.
#
# Writable at runtime (mount as volumes / tmpfs, everything else stays
# read-only — see docker-compose.yml): _data/ (cache), local/ (config +
# install sentinel), galleries/ (photo storage), upload/ (incoming uploads).

# ─── Stage 1: PHP dependencies ──────────────────────────────────────────────
FROM composer:2 AS builder
WORKDIR /app
COPY composer.json composer.lock ./
# --ignore-platform-reqs: the composer:2 image is a bare CLI, not the target
# runtime — its actual PHP extensions are installed in the production stage
# below, so vendor resolution here doesn't need them loaded.
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction \
        --prefer-dist --ignore-platform-reqs
COPY . .
# --classmap-authoritative comes back once P6 gives the autoloader a real
# PSR-4 map to be authoritative about (see docs/RUNBOOK.md) — nothing is
# namespaced yet, so a plain optimized dump-autoload matches today's dev flow.
RUN composer dump-autoload --no-dev --optimize

# ─── Stage 2: frontend assets ────────────────────────────────────────────────
FROM oven/bun:1 AS frontend
WORKDIR /app
COPY package.json bun.lock ./
RUN bun install --frozen-lockfile
COPY . .
RUN bun run build

# ─── Stage 3: production runtime (FrankenPHP — the recommended runtime, ADR-0013) ──
FROM dunglas/frankenphp:1-php8.5 AS production

# The base image already ships ctype, curl, dom (+lexbor), fileinfo, filter,
# iconv, mbstring, openssl, session, SimpleXML built in (verified via `php
# -m`) — only these five are actually missing from composer.json's require
# list, so only these get built here.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev libzip-dev libwebp-dev libjpeg62-turbo-dev libpng-dev \
        libxml2-dev libmagickwand-dev libvips-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" calendar gd intl mysqli zip \
    && pecl install imagick redis apcu \
    && docker-php-ext-enable imagick redis apcu \
    # No --auto-remove: that would cascade into removing the runtime shared
    # libraries (libpng16, libicuio, libMagickWand, libzip...) that these
    # -dev metapackages pulled in as dependencies and that the extensions
    # actually need loaded at runtime — only the -dev/headers packages
    # themselves are build-time-only.
    && apt-get purge -y \
        libicu-dev libzip-dev libwebp-dev libjpeg62-turbo-dev libpng-dev libxml2-dev libmagickwand-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY --from=builder /app/vendor ./vendor
COPY --from=frontend /app/dist ./dist
COPY . .
COPY --from=builder /app/vendor/autoload.php ./vendor/autoload.php
COPY docker/Caddyfile /etc/frankenphp/Caddyfile

RUN mkdir -p _data local galleries upload /config/caddy /data/caddy \
    && chown -R www-data:www-data _data local galleries upload /config/caddy /data/caddy

USER www-data

# Needs CAP_NET_BIND_SERVICE to bind :80 as non-root — see docker/Caddyfile's
# comment; docker-compose.yml/Helm both cap_add it back explicitly.
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

EXPOSE 80

# ─── Dev stage: production image + composer/bun dev tooling, for
# .devcontainer (VS Code / GitHub Codespaces) — the one stage with both PHP
# extensions and a JS runtime, so intelephense/composer/bun/pest all work in
# the same exec session. Not used by CI or the `test` compose profile below
# (those stay narrowly scoped to one toolchain each).
FROM production AS dev
USER root
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=frontend /usr/local/bin/bun /usr/local/bin/bun
RUN composer install --no-interaction --prefer-dist --ignore-platform-reqs
USER www-data

# ─── Test stage: frontend image + full (dev) deps, for
# `docker compose --profile test` — runs the JS/TS Vitest suite (`just test`
# is literally `bun run test`, see justfile). PHP suites need either a live
# DB+webserver (Integration/Contract) or a browser/Chromium stack
# (Browser/VR); wiring a second containerized app+DB pair to support those
# is real, separate scope — they stay on bare-metal CI for now (see
# docs/PLAN-REPLAY.md P4's explicit decision not to migrate that pipeline).
FROM frontend AS test
ENTRYPOINT ["bun", "run", "test"]

# ─── Fallback stage: classic Apache + mod_php (for hosts that need Apache) ──
FROM php:8.5-apache AS production-apache

# Same already-built-in set as the production stage (verified via `php -m` —
# both images share the same underlying official php build).
RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev libzip-dev libwebp-dev libjpeg62-turbo-dev libpng-dev \
        libxml2-dev libmagickwand-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" calendar gd intl mysqli zip \
    && pecl install imagick redis apcu \
    && docker-php-ext-enable imagick redis apcu \
    && a2enmod rewrite headers \
    # No --auto-remove — see the production stage's comment on this same
    # pattern: it would also remove the runtime shared libraries these
    # -dev packages pulled in, which the extensions need loaded at runtime.
    && apt-get purge -y \
        libicu-dev libzip-dev libwebp-dev libjpeg62-turbo-dev libpng-dev libxml2-dev libmagickwand-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY --from=builder /app/vendor ./vendor
COPY --from=frontend /app/dist ./dist
COPY . .
COPY --from=builder /app/vendor/autoload.php ./vendor/autoload.php

RUN mkdir -p _data local galleries upload \
    && chown -R www-data:www-data _data local galleries upload

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/health || exit 1
