<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin\inc;

use Exception;
use Piwigo\inc\functions;

final class functions_install
{
    public static function parse_sql_file(
        string $filepath
    ): array {
        $sql = file_get_contents($filepath);
        $len = strlen($sql);
        $queries = [];
        $current = '';
        $state = [
            'in_single_quote' => false,
            'in_double_quote' => false,
            'in_dollar_quote' => false,
            'dollar_tag' => null,
            'in_line_comment' => false,
            'in_block_comment' => false,
        ];

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            // Handle line comments
            if (! $state['in_single_quote'] &&
                ! $state['in_double_quote'] &&
                ! $state['in_dollar_quote'] &&
                ! $state['in_block_comment'] &&
                ($char === '-' && $next === '-')
            ) {
                $state['in_line_comment'] = true;
            }

            if ($char === "\n" &&
                $state['in_line_comment']
            ) {
                $state['in_line_comment'] = false;
            }

            // Block comments
            if (! $state['in_single_quote'] &&
                ! $state['in_double_quote'] &&
                ! $state['in_dollar_quote']
            ) {
                if ($char === '/' &&
                    $next === '*'
                ) {
                    $state['in_block_comment'] = true;
                }

                if ($char === '*' &&
                    $next === '/'
                ) {
                    $state['in_block_comment'] = false;
                    $i++; // Skip '/'
                    $current .= '*/';
                    continue;
                }
            }

            // Skip all comment chars
            if ($state['in_line_comment'] ||
                $state['in_block_comment']
            ) {
                $current .= $char;
                continue;
            }

            // Dollar-quoted blocks
            if (! $state['in_single_quote'] &&
                ! $state['in_double_quote'] &&
                preg_match('/^\$[a-zA-Z_]*\$/', substr($sql, $i), $matches)
            ) {
                $tag = $matches[0];

                if (! $state['in_dollar_quote']) {
                    $state['in_dollar_quote'] = true;
                    $state['dollar_tag'] = $tag;
                } elseif ($tag === $state['dollar_tag']) {
                    $state['in_dollar_quote'] = false;
                    $state['dollar_tag'] = null;
                }

                $current .= $tag;
                $i += strlen($tag) - 1;
                continue;
            }

            // Handle string quotes
            if ($char === "'" &&
                ! $state['in_double_quote'] &&
                ! $state['in_dollar_quote']
            ) {
                $state['in_single_quote'] = ! $state['in_single_quote'];
            }

            if ($char === '"' &&
                ! $state['in_single_quote'] &&
                ! $state['in_dollar_quote']
            ) {
                $state['in_double_quote'] = ! $state['in_double_quote'];
            }

            // Statement terminator
            if ($char === ';' &&
                ! $state['in_single_quote'] &&
                ! $state['in_double_quote'] &&
                ! $state['in_dollar_quote']
            ) {
                $queries[] = trim($current . ';');
                $current = '';
                continue;
            }

            $current .= $char;
        }

        // Last statement
        if (trim($current) !== '' &&
            trim($current) !== '0'
        ) {
            $queries[] = trim($current);
        }

        return $queries;
    }

    /**
     * Loads a SQL file and executes all queries.
     * Before executing a query, $replaced is... replaced by $replacing. This is
     * useful when the SQL file contains generic words. Drop table queries are
     * not executed.
     */
    public static function execute_sqlfile(
        string $filepath
    ): void {
        global $conf;

        $queries = self::parse_sql_file($filepath);

        foreach ($queries as $query) {
            if (! preg_match('/^DROP TABLE/i', $query)) {
                $conf->sql_backend::pwg_query($query);
            }
        }
    }

    /**
     * Automatically activate all core themes in the "themes" directory.
     */
    public static function activate_core_themes(): void
    {
        $themes = new themes();

        foreach (array_keys($themes->fs_themes) as $theme_id) {
            if (in_array($theme_id, ['modus', 'smartpocket'])) {
                $themes->perform_action('activate', $theme_id);
            }
        }
    }

    /**
     * Automatically activate some core plugins
     */
    public static function activate_core_plugins(): void
    {
        $plugins = new plugins();

        foreach (array_keys($plugins->fs_plugins) as $plugin_id) {
            if (in_array($plugin_id, [])) {
                $plugins->perform_action('activate', $plugin_id);
            }
        }
    }

    /**
     * Connect to database during installation. Uses $_POST.
     *
     * @param array<string> $errors - populated with errors
     */
    public static function install_db_connect(
        array &$errors
    ): void {
        global $conf;

        try {
            // first connect to default database
            $conf->sql_backend::pwg_db_connect(
                $_POST['dbhost'],
                $_POST['dbuser'],
                $_POST['dbpasswd'],
                ''
            );
            $conf->sql_backend::pwg_db_check_version();

            if ($conf->dblayer === 'pgsql') {
                $conf->sql_backend::pwg_query("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$_POST['dbname']}' AND pid <> pg_backend_pid();");
            }

            $conf->sql_backend::pwg_query("DROP DATABASE IF EXISTS {$_POST['dbname']};");
            $conf->sql_backend::pwg_query("CREATE DATABASE {$_POST['dbname']};");
            // then connect to Piwigo database
            $conf->sql_backend::pwg_db_connect(
                $_POST['dbhost'],
                $_POST['dbuser'],
                $_POST['dbpasswd'],
                $_POST['dbname']
            );
        } catch (Exception $exception) {
            $errors[] = functions::l10n($exception->getMessage());
        }
    }
}
