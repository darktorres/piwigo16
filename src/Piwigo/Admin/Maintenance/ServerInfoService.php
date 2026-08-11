<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance;

use Piwigo\Admin\Maintenance\Projection\ServerInfo;

/**
 * SEC-22: replaces the 2 raw phpinfo() call sites (admin/maintenance_actions.php,
 * admin/maintenance_env.php -- both gated behind AccessLevel::Administrator
 * + a pwg_token check, but phpinfo() itself dumps far more than an admin
 * troubleshooting page needs: full server filesystem paths, every loaded
 * module's build flags, environment variables, and (depending on php.ini)
 * a phpinfo() build that leaks the server's real IP/hostname). Curated to
 * what's actually useful for troubleshooting a Piwigo install: PHP/SAPI/OS
 * version, loaded extensions, ini settings covering upload/execution limits
 * (memory_limit, upload_max_filesize, post_max_size, max_execution_time,
 * max_input_time, max_file_uploads), plus a few general diagnostics
 * (default_charset, date.timezone, display_errors).
 */
final class ServerInfoService
{
    private const array KEY_INI_SETTINGS = [
        'memory_limit',
        'upload_max_filesize',
        'post_max_size',
        'max_execution_time',
        'max_input_time',
        'max_file_uploads',
        'default_charset',
        'date.timezone',
        'display_errors',
    ];

    public function curatedInfo(): ServerInfo
    {
        $extensions = get_loaded_extensions();
        sort($extensions);

        $ini = [];
        foreach (self::KEY_INI_SETTINGS as $setting) {
            $value = ini_get($setting);
            // ini_get() only returns false for an unknown directive name; every
            // entry in KEY_INI_SETTINGS above is a real, always-registered core
            // (or always-loaded-extension) directive on any PHP build this app
            // supports, so this branch is unreachable in practice. Kept as
            // defensive typing for the return signature, not because it can
            // actually fire.
            $ini[$setting] = $value === false ? '' : $value;
        }

        return new ServerInfo(
            phpVersion: PHP_VERSION,
            sapi: PHP_SAPI,
            os: PHP_OS,
            extensions: $extensions,
            ini: $ini,
        );
    }

    /**
     * Renders the curated info as a minimal standalone HTML page, matching
     * the legacy phpinfo() call sites' own "direct output, not through
     * Smarty" shape (both callers do `phpinfo(); exit();`, never touching
     * $template).
     */
    public function renderHtml(): string
    {
        $info = $this->curatedInfo();

        $html = "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><title>Server information</title></head><body>";
        $html .= '<h1>Server information</h1>';
        $html .= '<table border="1" cellpadding="4">';
        // PHP_VERSION/PHP_SAPI/PHP_OS and loaded-extension names are all
        // compile-time-determined identifiers that can never contain an
        // HTML metacharacter on any real PHP build -- the htmlspecialchars()
        // calls below (and on the extension li below) are defense-in-depth,
        // not covering an actually-reachable escaping bug. The ini setting
        // *values* on the other hand are genuinely attacker/operator
        // -influenceable (e.g. `display_errors` accepts an arbitrary
        // ini_set() string) and are covered by a real escaping test.
        $html .= '<tr><th>PHP version</th><td>' . htmlspecialchars($info->phpVersion) . '</td></tr>';
        $html .= '<tr><th>SAPI</th><td>' . htmlspecialchars($info->sapi) . '</td></tr>';
        $html .= '<tr><th>OS</th><td>' . htmlspecialchars($info->os) . '</td></tr>';
        foreach ($info->ini as $setting => $value) {
            $html .= '<tr><th>' . htmlspecialchars($setting) . '</th><td>' . htmlspecialchars($value) . '</td></tr>';
        }
        $html .= '</table>';
        $html .= '<h2>Loaded extensions</h2><ul>';
        foreach ($info->extensions as $extension) {
            $html .= '<li>' . htmlspecialchars($extension) . '</li>';
        }
        $html .= '</ul></body></html>';

        return $html;
    }
}
