<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Piwigo\Http\PathExtractor;

/**
 * Covers all path-extraction modes: PATH_INFO, REQUEST_URI, and the
 * Piwigo "?/path" query-string mode — without any web server config.
 */
final class PathExtractorTest extends TestCase
{
    // ── PATH_INFO mode ────────────────────────────────────────────────────────

    public function test_path_info_returns_clean_path(): void
    {
        $result = PathExtractor::fromServer([
            'PATH_INFO'       => '/category/12-foo',
            'SCRIPT_FILENAME' => '/var/www/html/piwigo/index.php',
            'SCRIPT_NAME'     => '/piwigo/index.php',
            'REQUEST_URI'     => '/piwigo/index.php/category/12-foo',
            'QUERY_STRING'    => '',
        ]);
        self::assertSame('/category/12-foo', $result);
    }

    public function test_path_info_strips_leading_double_slash(): void
    {
        $result = PathExtractor::fromServer([
            'PATH_INFO'       => '//category/12',
            'SCRIPT_FILENAME' => '/srv/piwigo/index.php',
            'SCRIPT_NAME'     => '/index.php',
            'REQUEST_URI'     => '/index.php//category/12',
            'QUERY_STRING'    => '',
        ]);
        self::assertSame('/category/12', $result);
    }

    public function test_path_info_equal_to_script_filename_is_ignored(): void
    {
        // Some ISPs populate PATH_INFO with SCRIPT_FILENAME — treat as absent.
        $result = PathExtractor::fromServer([
            'PATH_INFO'       => '/var/www/piwigo/index.php',
            'SCRIPT_FILENAME' => '/var/www/piwigo/index.php',
            'SCRIPT_NAME'     => '/piwigo/index.php',
            'REQUEST_URI'     => '/piwigo/',
            'QUERY_STRING'    => '',
        ]);
        // Should fall through to REQUEST_URI, not treat the filesystem path as a route
        self::assertSame('/', $result);
    }

    public function test_empty_path_info_is_ignored(): void
    {
        $result = PathExtractor::fromServer([
            'PATH_INFO'       => '',
            'SCRIPT_FILENAME' => '/srv/index.php',
            'SCRIPT_NAME'     => '/index.php',
            'REQUEST_URI'     => '/',
            'QUERY_STRING'    => '',
        ]);
        self::assertSame('/', $result);
    }

    // ── REQUEST_URI mode (clean URLs or explicit /index.php/path) ─────────────

    public function test_request_uri_strips_script_name_prefix(): void
    {
        // /piwigo16/index.php/picture/5 → /picture/5
        $result = PathExtractor::fromServer([
            'PATH_INFO'       => '',
            'SCRIPT_FILENAME' => '/var/www/piwigo16/index.php',
            'SCRIPT_NAME'     => '/piwigo16/index.php',
            'REQUEST_URI'     => '/piwigo16/index.php/picture/5',
            'QUERY_STRING'    => '',
        ]);
        self::assertSame('/picture/5', $result);
    }

    public function test_request_uri_strips_base_dir_for_clean_url(): void
    {
        // Clean URL mode: /piwigo16/category/12 → /category/12
        $result = PathExtractor::fromServer([
            'PATH_INFO'       => '',
            'SCRIPT_FILENAME' => '/var/www/piwigo16/index.php',
            'SCRIPT_NAME'     => '/piwigo16/index.php',
            'REQUEST_URI'     => '/piwigo16/category/12',
            'QUERY_STRING'    => '',
        ]);
        self::assertSame('/category/12', $result);
    }

    public function test_request_uri_root_install_clean_url(): void
    {
        // App at web root: /picture/5 → /picture/5
        $result = PathExtractor::fromServer([
            'PATH_INFO'       => '',
            'SCRIPT_FILENAME' => '/var/www/html/index.php',
            'SCRIPT_NAME'     => '/index.php',
            'REQUEST_URI'     => '/picture/5',
            'QUERY_STRING'    => '',
        ]);
        self::assertSame('/picture/5', $result);
    }

    public function test_request_uri_homepage_returns_slash(): void
    {
        $result = PathExtractor::fromServer([
            'PATH_INFO'       => '',
            'SCRIPT_FILENAME' => '/var/www/piwigo/index.php',
            'SCRIPT_NAME'     => '/piwigo/index.php',
            'REQUEST_URI'     => '/piwigo/',
            'QUERY_STRING'    => '',
        ]);
        self::assertSame('/', $result);
    }

    public function test_request_uri_decodes_percent_encoding(): void
    {
        $result = PathExtractor::fromServer([
            'PATH_INFO'       => '',
            'SCRIPT_FILENAME' => '/var/www/index.php',
            'SCRIPT_NAME'     => '/index.php',
            'REQUEST_URI'     => '/category/my%20album',
            'QUERY_STRING'    => '',
        ]);
        self::assertSame('/category/my album', $result);
    }

    // ── Query-string "?/path" mode (Piwigo's question_mark_in_urls) ──────────

    public function test_query_string_slash_prefix_mode(): void
    {
        $result = PathExtractor::fromServer([
            'PATH_INFO'       => '',
            'SCRIPT_FILENAME' => '/var/www/piwigo/index.php',
            'SCRIPT_NAME'     => '/piwigo/index.php',
            'REQUEST_URI'     => '/piwigo/index.php',
            'QUERY_STRING'    => '/category/12-foo/start-24',
        ]);
        self::assertSame('/category/12-foo/start-24', $result);
    }

    public function test_query_string_path_stops_at_ampersand(): void
    {
        $result = PathExtractor::fromServer([
            'PATH_INFO'       => '',
            'SCRIPT_FILENAME' => '/var/www/piwigo/index.php',
            'SCRIPT_NAME'     => '/piwigo/index.php',
            'REQUEST_URI'     => '/piwigo/index.php',
            'QUERY_STRING'    => '/picture/5&extra=foo',
        ]);
        self::assertSame('/picture/5', $result);
    }

    public function test_regular_query_string_without_slash_prefix_returns_homepage(): void
    {
        $result = PathExtractor::fromServer([
            'PATH_INFO'       => '',
            'SCRIPT_FILENAME' => '/var/www/piwigo/index.php',
            'SCRIPT_NAME'     => '/piwigo/index.php',
            'REQUEST_URI'     => '/piwigo/index.php',
            'QUERY_STRING'    => 'method=pwg.getVersion&format=json',
        ]);
        // WS calls go through ?method=... — the path is / (WsController handles method param)
        self::assertSame('/', $result);
    }

    // ── Fallback ──────────────────────────────────────────────────────────────

    public function test_missing_server_keys_return_homepage(): void
    {
        $result = PathExtractor::fromServer([]);
        self::assertSame('/', $result);
    }

    public function test_path_info_takes_precedence_over_query_string(): void
    {
        $result = PathExtractor::fromServer([
            'PATH_INFO'       => '/picture/7',
            'SCRIPT_FILENAME' => '/var/www/index.php',
            'SCRIPT_NAME'     => '/index.php',
            'REQUEST_URI'     => '/index.php/picture/7',
            'QUERY_STRING'    => '/category/99',
        ]);
        self::assertSame('/picture/7', $result);
    }
}
