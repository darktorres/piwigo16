<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * P23 batch 8d: containerized-environment detection relocated from
 * include/functions.inc.php -- no natural existing class home, stateless.
 */
final class ContainerDetector
{
    /**
     * Detect if Piwigo is running in a containerized environment.
     * Assumes all containers are Linux based and don't enforce php
     * open_basedir rules. Doesn't differentiate between VMs, mutual
     * hosting, and bare metal installs.
     *
     * Possible values:
     *  ('none', null)                 => PHP is not running in a container
     *  ('Official', <VersionCode>)    => PHP is running in an official container
     *  ('LinuxServer', <VersionCode>) => PHP is running in a LinuxServer container
     *  ('Unknown', null)              => PHP is running in a non-identified container
     *
     * @since 16.3
     *
     * @return array{0: string, 1: ?string}
     */
    public static function detect(): array
    {
        // Check if OS is Linux and PHP doesn't restrict opening files
        $open_basedir = ini_get('open_basedir');
        $open_basedir_empty = $open_basedir === false || $open_basedir === '' || $open_basedir === '0';
        if ((strtoupper(substr(PHP_OS, 0, 5)) === 'LINUX' && $open_basedir_empty)) {
            if (file_exists('/proc/2/sched')) { // Check if PID2 exist
                $file = file_get_contents('/proc/2/sched'); // Read PID2 name
                if ($file !== false && str_starts_with($file, 'kthreadd')) { // If PID 2 is kthreadd PHP is not running in a container
                    return ['none', null];
                }
            }

            // PHP is running in a container, trying to determine container type
            $info_file_path = '/var/www/html/piwigo-docker.info';
            $info_file_linuxserver = '/build_version';

            // Check for official container tagfile
            if (is_readable($info_file_path)) {
                $file_lines = @file($info_file_path);
                if (is_array($file_lines) && $file_lines !== [] && trim($file_lines[0]) === 'Official Piwigo container') {
                    $container_version = null;
                    // Take the last line and remove prefix (Build Version)
                    if (preg_match('/^Build Version (.*)$/', $file_lines[count($file_lines) - 1], $matches) === 1) {
                        $container_version = $matches[1];
                    }
                    return ['Official', $container_version];
                }
            }
            // Check for LinuxServer tagfile
            elseif (is_readable($info_file_linuxserver)) {
                $file_lines = file($info_file_linuxserver);
                if (is_array($file_lines) && str_starts_with($file_lines[0], 'Linuxserver.io')) {
                    $container_version = null;
                    if (preg_match('/version:\s*(.*)$/', $file_lines[0], $matches) === 1) {
                        $container_version = $matches[1];
                    }
                    return ['LinuxServer.io', $container_version];
                }
            }
            // If no tagfile are found, default to unknown
            return ['Unknown', null];
        } else {
            // If the OS is not Linux or PHP basedir are enforced, assume PHP is not in a container
            return ['none', null];
        }
    }
}
