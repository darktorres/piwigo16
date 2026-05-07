<?php

declare(strict_types=1);

namespace Piwigo\Http;

/**
 * Extracts the logical route path from the PHP superglobal $_SERVER array
 * without requiring any web-server-specific URL rewriting.
 *
 * Resolution order:
 *   1. PATH_INFO  — set when the URL is index.php/path (or FastCGI)
 *   2. REQUEST_URI — parsed and stripped of the base-dir + script-filename prefix
 *      (covers clean-URL mode when a server handles rewriting)
 *   3. QUERY_STRING starting with '/' — Piwigo's "question_mark_in_urls" mode
 *      (e.g. index.php?/category/12-foo)
 *   4. '/' — homepage fallback
 */
final class PathExtractor
{
    /**
     * @param array<mixed> $server  Typically $_SERVER.
     */
    public static function fromServer(array $server): string
    {
        // 1. PATH_INFO (index.php/path or CGI/FastCGI)
        // Some ISPs set PATH_INFO to SCRIPT_FILENAME — guard against that.
        $pathInfo   = is_scalar($server['PATH_INFO'] ?? null) ? (string) $server['PATH_INFO'] : '';
        $scriptFile = is_scalar($server['SCRIPT_FILENAME'] ?? null) ? (string) $server['SCRIPT_FILENAME'] : '';
        if ($pathInfo !== '' && $pathInfo !== $scriptFile) {
            return '/' . ltrim($pathInfo, '/');
        }

        // 2. REQUEST_URI minus base directory and script filename
        $rawUri  = is_scalar($server['REQUEST_URI'] ?? null) ? (string) $server['REQUEST_URI'] : '/';
        $uriPath = parse_url($rawUri, PHP_URL_PATH);
        $uriPath = is_string($uriPath) ? rawurldecode($uriPath) : '/';

        // SCRIPT_NAME is like /piwigo16/index.php — derive the base directory.
        $scriptName = is_scalar($server['SCRIPT_NAME'] ?? null) ? (string) $server['SCRIPT_NAME'] : '/index.php';
        $scriptDir  = rtrim(dirname($scriptName), '/'); // e.g. /piwigo16 (empty string for root)

        // Strip base directory prefix (subdirectory installs)
        if ($scriptDir !== '' && str_starts_with($uriPath, $scriptDir . '/')) {
            $uriPath = substr($uriPath, strlen($scriptDir));
        }

        // Strip /index.php prefix (explicit front-controller access)
        $scriptBasename = '/' . basename($scriptName); // /index.php
        if (str_starts_with($uriPath, $scriptBasename)) {
            $uriPath = substr($uriPath, strlen($scriptBasename));
        }

        if ($uriPath !== '' && $uriPath !== '/') {
            return '/' . ltrim($uriPath, '/');
        }

        // 3. QUERY_STRING ?/path mode (Piwigo's question_mark_in_urls)
        $qs = is_scalar($server['QUERY_STRING'] ?? null) ? (string) $server['QUERY_STRING'] : '';
        if (str_starts_with($qs, '/')) {
            // Take only the path segment — stop at & (additional params follow)
            return explode('&', $qs, 2)[0];
        }

        return '/';
    }
}
