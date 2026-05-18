<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Config\Config;

/**
 * Resolves the base URL of the Piwigo Extension Manager (PEM) — i.e. the
 * extension-marketplace endpoint that admin pages hit for plugin/theme/
 * language metadata.
 *
 * Replaces the legacy `define('PEM_URL', …)` writer in CommonBootstrap.
 * If `Config::alternativePemUrl()` is set and non-empty, that takes
 * precedence; otherwise the URL is composed from the current request's
 * scheme and host plus the fork's `/piwigo16-ext` path.
 */
final class PemUrlResolver
{
    public function url(): string
    {
        if (Config::has('alternative_pem_url') && Config::alternativePemUrl() !== '') {
            return Config::alternativePemUrl();
        }

        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        /** @var mixed $hostRaw */
        $hostRaw = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $host    = is_string($hostRaw) ? $hostRaw : 'localhost';

        return $scheme . '://' . $host . '/piwigo16-ext';
    }
}
