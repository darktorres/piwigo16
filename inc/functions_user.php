<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

use Exception;
use Piwigo\inc\dblayer\functions_mysqli;
use Random\RandomException;

functions_plugins::add_event_handler('try_log_user', functions_user::pwg_login(...));

final class functions_user
{
    /**
     * Checks if an email is well formed and not already in use.
     *
     * @return string|null error message or nothing
     */
    public static function validate_mail_address(
        int|string|null $user_id,
        string $mail_address
    ): ?string {
        global $conf;

        if (empty($mail_address) &&
            ! ($conf->obligatory_user_mail_address &&
            in_array(functions::script_basename(), ['register', 'profile']))
        ) {
            return '';
        }

        if (! functions::email_check_format($mail_address)) {
            return functions::l10n('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
        }

        if (defined('PHPWG_INSTALLED') &&
            ! empty($mail_address)
        ) {
            $exclude_user_condition = is_numeric($user_id) ? "AND {$conf->user_fields['id']} != '{$user_id}'" : '';
            $query = <<<SQL
                SELECT COUNT(*)
                FROM users
                WHERE UPPER({$conf->user_fields['email']}) = UPPER('{$mail_address}')
                    {$exclude_user_condition};
                SQL;
            [$count] = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

            if ($count != 0) {
                return functions::l10n('this email address is already in use');
            }
        }

        return null;
    }

    /**
     * Checks if a login is not already in use.
     * Comparison is case insensitive.
     *
     * @return string|null error message or nothing
     */
    public static function validate_login_case(
        string $login
    ): ?string {
        global $conf;

        if (defined('PHPWG_INSTALLED')) {
            $escaped_username_field = stripslashes($conf->user_fields['username']);
            $lowered_login = strtolower($login);
            $query = <<<SQL
                SELECT {$conf->user_fields['username']}
                FROM users
                WHERE LOWER({$escaped_username_field}) = '{$lowered_login}';
                SQL;

            $count = functions_mysqli::pwg_db_num_rows(functions_mysqli::pwg_query($query));

            if ($count > 0) {
                return functions::l10n('this login is already used');
            }
        }

        return null;
    }

    /**
     * Searches for user with the same username in different case.
     *
     * @param string $username typically typed in by user for identification
     * @return string found in database
     */
    public static function search_case_username(
        string $username
    ): string {
        global $conf;

        $username_lo = strtolower($username);

        $SCU_users = [];

        $q = functions_mysqli::pwg_query(<<<SQL
            SELECT {$conf->user_fields['username']} AS username
            FROM users;
            SQL);

        while ($r = functions_mysqli::pwg_db_fetch_assoc($q)) {
            $SCU_users[$r['username']] = strtolower($r['username']);
        }

        // $SCU_users is now an associative table where the key is the account as
        // registered in the DB, and the value is this same account, in lower case

        $users_found = array_keys($SCU_users, $username_lo);
        // $users_found is now a table of which the values are all the accounts
        // which can be written in lowercase the same way as $username
        if (count($users_found) != 1) { // If ambiguous, don't allow lowercase writing
            return $username;
        } // but normal writing will work

        return $users_found[0];
    }

    /**
     * Creates a new user.
     *
     * @param array $errors populated with error messages
     * @return int|false user id or false
     * @throws Exception
     */
    public static function register_user(
        string $login,
        string $password,
        string $mail_address,
        bool $notify_admin = true,
        array &$errors = [],
        bool $notify_user = false
    ): false|int {
        global $conf;

        if ($login === '') {
            $errors[] = functions::l10n('Please, enter a login');
        }

        if (preg_match('/^.* $/', $login)) {
            $errors[] = functions::l10n("login mustn't end with a space character");
        }

        if (preg_match('/^ .*$/', $login)) {
            $errors[] = functions::l10n("login mustn't start with a space character");
        }

        if (self::get_userid($login)) {
            $errors[] = functions::l10n('this login is already used');
        }

        if ($login !== strip_tags($login)) {
            $errors[] = functions::l10n('html tags are not allowed in login');
        }

        $mail_error = self::validate_mail_address(null, $mail_address);

        if ($mail_error != '') {
            $errors[] = $mail_error;
        }

        if ($conf->insensitive_case_logon) {
            $login_error = self::validate_login_case($login);

            if ($login_error != '') {
                $errors[] = $login_error;
            }
        }

        $errors = functions_plugins::trigger_change(
            'register_user_check',
            $errors,
            [
                'username' => $login,
                'password' => $password,
                'email' => $mail_address,
            ]
        );

        // if no error until here, registration of the user
        if (empty($errors)) {
            $insert = [
                $conf->user_fields['username'] => $login,
                $conf->user_fields['password'] => ($conf->password_hash)($password),
                $conf->user_fields['email'] => $mail_address,
            ];

            functions_mysqli::single_insert('users', $insert);
            $user_id = functions_mysqli::pwg_db_insert_id();

            // Assign by default groups
            $query = <<<SQL
                SELECT id
                FROM `groups`
                WHERE is_default = 'true'
                ORDER BY id ASC;
                SQL;
            $result = functions_mysqli::pwg_query($query);

            $inserts = [];

            while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
                $inserts[] = [
                    'user_id' => $user_id,
                    'group_id' => $row['id'],
                ];
            }

            if (count($inserts) != 0) {
                functions_mysqli::mass_inserts('user_group', ['user_id', 'group_id'], $inserts);
            }

            $override = [];

            if ($conf->browser_language) {
                $language = self::get_browser_language();

                if ($language) {
                    $override['language'] = $language;
                }
            }

            self::create_user_infos($user_id, $override);

            if ($notify_admin &&
                $conf->email_admin_on_new_user !== 'none'
            ) {
                require_once __DIR__ . '/../inc/functions_mail.php';
                $admin_url = functions_url::get_absolute_root_url() . 'admin.php?page=user_list&username=' . $login;

                $keyargs_content = [
                    functions::get_l10n_args('User: %s', stripslashes($login)),
                    functions::get_l10n_args('Email: %s', $mail_address),
                    functions::get_l10n_args(''),
                    functions::get_l10n_args('Admin: %s', $admin_url),
                ];

                $group_id = null;

                if (preg_match('/^group:(\d+)$/', $conf->email_admin_on_new_user, $matches)) {
                    $group_id = $matches[1];
                }

                functions_mail::pwg_mail_notification_admins(
                    functions::get_l10n_args('Registration of %s', stripslashes($login)),
                    $keyargs_content,
                    true, // $send_technical_details
                    $group_id
                );
            }

            if ($notify_user &&
                functions::email_check_format($mail_address)
            ) {
                require_once __DIR__ . '/../inc/functions_mail.php';

                $keyargs_content = [
                    functions::get_l10n_args('Hello %s,', stripslashes($login)),
                    functions::get_l10n_args('Thank you for registering at %s!', $conf->gallery_title),
                    functions::get_l10n_args(''),
                    functions::get_l10n_args('Here are your connection settings'),
                    functions::get_l10n_args(''),
                    functions::get_l10n_args('Link: %s', functions_url::get_absolute_root_url()),
                    functions::get_l10n_args('Username: %s', stripslashes($login)),
                    functions::get_l10n_args('Password: %s', stripslashes($password)),
                    functions::get_l10n_args('Email: %s', $mail_address),
                    functions::get_l10n_args(''),
                    functions::get_l10n_args("If you think you've received this email in error, please contact us at %s", functions::get_webmaster_mail_address()),
                ];

                functions_mail::pwg_mail(
                    $mail_address,
                    [
                        'subject' => '[' . $conf->gallery_title . '] ' . functions::l10n('Registration'),
                        'content' => functions::l10n_args($keyargs_content),
                        'content_format' => 'text/plain',
                    ]
                );
            }

            functions_plugins::trigger_notify(
                'register_user',
                [
                    'id' => $user_id,
                    'username' => $login,
                    'email' => $mail_address,
                ]
            );

            functions::pwg_activity('user', $user_id, 'add');

            return $user_id;
        }

        return false;
    }

    /**
     * Fetches user data from database.
     * Same that getuserdata() but with additional tests for guest.
     */
    public static function build_user(
        int $user_id,
        bool $use_cache = true
    ): array {
        global $conf;

        $user['id'] = $user_id;
        $user = array_merge($user, self::getuserdata($user_id, $use_cache));

        if ($user['id'] == $conf->guest_id &&
            $user['status'] != 'guest'
        ) {
            $user['status'] = 'guest';
            $user['internal_status']['guest_must_be_guest'] = true;
        }

        // Check user theme. 2 possible problems:
        // 1. the user_infos.theme was not found in the themes table, thus themes.name is null
        // 2. the theme is not really installed on the filesystem
        if (! isset($user['theme_name']) ||
            ! functions::check_theme_installed($user['theme'])
        ) {
            $user['theme'] = self::get_default_theme();
            $user['theme_name'] = $user['theme'];
        }

        return $user;
    }

    /**
     * Finds information related to the user identifier.
     */
    public static function getuserdata(
        int $user_id,
        bool $use_cache = false
    ): array {
        global $conf;

        // retrieve basic user data
        $query = <<<SQL
            SELECT

            SQL;
        $is_first = true;

        foreach ($conf->user_fields as $pwgfield => $dbfield) {
            if ($is_first) {
                $is_first = false;
            } else {
                $query .= ",\n";
            }

            $query .= "{$dbfield} AS {$pwgfield}";
        }

        $query .= "\n";
        $query .= <<<SQL
            FROM users
            WHERE {$conf->user_fields['id']} = {$user_id};
            SQL;
        $row = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));

        // retrieve additional user data ?
        if ($conf->external_authentication) {
            $query = <<<SQL
                SELECT COUNT(1) AS counter
                FROM user_infos AS ui
                LEFT JOIN user_cache AS uc ON ui.user_id = uc.user_id
                LEFT JOIN themes AS t ON t.id = ui.theme
                WHERE ui.user_id = {$user_id}
                GROUP BY ui.user_id;
                SQL;
            [$counter] = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

            if ($counter != 1) {
                self::create_user_infos($user_id);
            }
        }

        // retrieve user info
        $query = <<<SQL
            SELECT ui.*, uc.*, t.name AS theme_name
            FROM user_infos AS ui
            LEFT JOIN user_cache AS uc ON ui.user_id = uc.user_id
            LEFT JOIN themes AS t ON t.id = ui.theme
            WHERE ui.user_id = {$user_id};
            SQL;

        $result = functions_mysqli::pwg_query($query);
        $user_infos_row = functions_mysqli::pwg_db_fetch_assoc($result);

        // then merge basic + additional user data
        $userdata = array_merge($row, $user_infos_row);

        foreach ($userdata as &$value) {
            // If the field is true or false, the variable is transformed into a boolean value.
            if ($value == 'true') {
                $value = true;
            } elseif ($value == 'false') {
                $value = false;
            }
        }

        unset($value);

        $userdata['preferences'] = empty($userdata['preferences']) ? [] : unserialize($userdata['preferences']);

        if ($use_cache && (! isset($userdata['need_update']) || ! is_bool($userdata['need_update']) || $userdata['need_update'])) {
            $userdata['cache_update_time'] = time();
            // Set need update are done
            $userdata['need_update'] = false;
            $userdata['forbidden_categories'] = self::calculate_permissions($userdata['id'], $userdata['status']);
            /* now we build the list of forbidden images (this list does not contain
               images that are not in at least an authorized category)*/
            $query = <<<SQL
                    SELECT DISTINCT id
                    FROM images INNER JOIN image_category ON id = image_id
                    WHERE category_id NOT IN ({$userdata['forbidden_categories']})
                        AND level > {$userdata['level']};
                    SQL;
            $forbidden_ids = functions_mysqli::query2array($query, null, 'id');
            if ($forbidden_ids === []) {
                $forbidden_ids[] = 0;
            }

            $userdata['image_access_type'] = 'NOT IN';
            //TODO maybe later
            $userdata['image_access_list'] = implode(', ', $forbidden_ids);
            $query = <<<SQL
                    SELECT COUNT(DISTINCT image_id) AS total
                    FROM image_category
                    WHERE category_id NOT IN ({$userdata['forbidden_categories']})
                        AND image_id {$userdata['image_access_type']} ({$userdata['image_access_list']});
                    SQL;
            [$userdata['nb_total_images']] = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));
            // now we update user cache categories
            $user_cache_cats = functions_category::get_computed_categories($userdata);
            if (! self::is_admin($userdata['status'])) { // for non admins we forbid categories with no image (feature 1053)
                $forbidden_ids = [];

                foreach ($user_cache_cats as $cat) {
                    if ($cat['count_images'] == 0) {
                        $forbidden_ids[] = $cat['cat_id'];
                        functions_category::remove_computed_category($user_cache_cats, $cat);
                    }
                }

                if ($forbidden_ids !== []) {
                    if (empty($userdata['forbidden_categories'])) {
                        $userdata['forbidden_categories'] = implode(', ', $forbidden_ids);
                    } else {
                        $userdata['forbidden_categories'] .= ',' . implode(', ', $forbidden_ids);
                    }
                }
            }

            // delete user cache
            $query = <<<SQL
                    DELETE FROM user_cache_categories
                    WHERE user_id = {$userdata['id']};
                    SQL;
            functions_mysqli::pwg_query($query);
            // Due to concurrency issues, we ask MySQL to ignore errors on
            // insert. This may happen when cache needs refresh and that Piwigo is
            // called "very simultaneously".
            functions_mysqli::mass_inserts(
                'user_cache_categories',
                [
                    'user_id', 'cat_id',
                    'date_last', 'max_date_last', 'nb_images', 'count_images', 'nb_categories', 'count_categories',
                ],
                $user_cache_cats,
                [
                    'ignore' => true,
                ]
            );
            // update user cache
            $query = <<<SQL
                    DELETE FROM user_cache
                    WHERE user_id = {$userdata['id']};
                    SQL;
            functions_mysqli::pwg_query($query);
            // for the same reason as user_cache_categories, we ignore error on
            // this insert
            $boolean_to_string = functions_mysqli::boolean_to_string($userdata['need_update']);
            $empty_last_photo_date = empty($userdata['last_photo_date']) ? 'NULL' : "'{$userdata['last_photo_date']}'";
            $query = <<<SQL
                    INSERT IGNORE INTO user_cache
                        (
                            user_id, need_update, cache_update_time, forbidden_categories, nb_total_images,
                            last_photo_date, image_access_type, image_access_list
                        )
                    VALUES
                        (
                            {$userdata['id']}, '{$boolean_to_string}', {$userdata['cache_update_time']}, '{$userdata['forbidden_categories']}',
                            {$userdata['nb_total_images']}, {$empty_last_photo_date}, '{$userdata['image_access_type']}', '{$userdata['image_access_list']}'
                        );
                    SQL;
            functions_mysqli::pwg_query($query);
        }

        return $userdata;
    }

    /**
     * Deletes favorites of the current user if he's not allowed to see them.
     */
    public static function check_user_favorites(): void
    {
        global $user;

        if ($user['forbidden_categories'] == '') {
            return;
        }

        // $filter['visible_categories'] and $filter['visible_images']
        // must be not used because filter != restriction
        // retrieving images allowed : belonging to at least one authorized
        // category
        $sql_condition = self::get_sql_condition_FandF(
            [
                'forbidden_categories' => 'ic.category_id',
            ],
            'AND'
        );

        $query = <<<SQL
            SELECT DISTINCT f.image_id
            FROM favorites AS f
            INNER JOIN image_category AS ic ON f.image_id = ic.image_id
            WHERE f.user_id = {$user['id']}
                {$sql_condition};
            SQL;
        $authorizeds = functions_mysqli::query2array($query, null, 'image_id');

        $query = <<<SQL
            SELECT image_id
            FROM favorites
            WHERE user_id = {$user['id']};
            SQL;
        $favorites = functions_mysqli::query2array($query, null, 'image_id');

        $to_deletes = array_diff($favorites, $authorizeds);

        if ($to_deletes !== []) {
            $to_deletes_imploded = implode(', ', $to_deletes);
            $query = <<<SQL
                DELETE FROM favorites
                WHERE image_id IN ({$to_deletes_imploded})
                    AND user_id = {$user['id']};
                SQL;
            functions_mysqli::pwg_query($query);
        }
    }

    /**
     * Calculates the list of forbidden categories for a given user.
     *
     * Calculation is based on private categories minus categories authorized to
     * the groups the user belongs to minus the categories directly authorized
     * to the user. The list contains at least 0 to be compliant with queries
     * such as "WHERE category_id NOT IN ($forbidden_categories)"
     *
     * @return string comma separated ids
     */
    public static function calculate_permissions(
        int|string $user_id,
        string $user_status
    ): string {
        $query = <<<SQL
            SELECT id
            FROM categories
            WHERE status = 'private';
            SQL;
        $private_array = functions_mysqli::query2array($query, null, 'id');

        // retrieve category ids directly authorized to the user
        $query = <<<SQL
            SELECT cat_id
            FROM user_access
            WHERE user_id = {$user_id};
            SQL;
        $authorized_array = functions_mysqli::query2array($query, null, 'cat_id');

        // retrieve category ids authorized to the groups the user belongs to
        $query = <<<SQL
            SELECT cat_id
            FROM user_group AS ug
            INNER JOIN group_access AS ga ON ug.group_id = ga.group_id
            WHERE ug.user_id = {$user_id};
            SQL;
        $authorized_array =
          array_merge(
              $authorized_array,
              functions_mysqli::query2array($query, null, 'cat_id')
          );

        // deduplicate ids : some private categories might be authorized for the
        // groups and for the user
        $authorized_array = array_unique($authorized_array);

        // only unauthorized private categories are forbidden
        $forbidden_array = array_diff($private_array, $authorized_array);

        // if user is not an admin, locked categories are forbidden
        if (! self::is_admin($user_status)) {
            $query = <<<SQL
                SELECT id
                FROM categories
                WHERE visible = 'false';
                SQL;
            $forbidden_array = array_merge($forbidden_array, functions_mysqli::query2array($query, null, 'id'));
            $forbidden_array = array_unique($forbidden_array);
        }

        if ($forbidden_array === []) { // at least, the list contains 0 value. This category does not exists so
            // where clauses such as "WHERE category_id NOT IN (0)" will always be
            // true.
            $forbidden_array[] = 0;
        }

        return implode(', ', $forbidden_array);
    }

    /**
     * Returns user identifier thanks to his name.
     */
    public static function get_userid(
        string $username
    ): false|int {
        global $conf;

        $username = functions_mysqli::pwg_db_real_escape_string($username);

        $query = <<<SQL
            SELECT {$conf->user_fields['id']}
            FROM users
            WHERE {$conf->user_fields['username']} = '{$username}';
            SQL;
        $result = functions_mysqli::pwg_query($query);

        if (functions_mysqli::pwg_db_num_rows($result) == 0) {
            return false;
        }

        [$user_id] = functions_mysqli::pwg_db_fetch_row($result);
        return $user_id;
    }

    /**
     * Returns user identifier thanks to his email.
     */
    public static function get_userid_by_email(
        string $email
    ): false|int {
        global $conf;

        $email = functions_mysqli::pwg_db_real_escape_string($email);

        $query = <<<SQL
            SELECT {$conf->user_fields['id']}
            FROM users
            WHERE UPPER({$conf->user_fields['email']}) = UPPER('{$email}');
            SQL;
        $result = functions_mysqli::pwg_query($query);

        if (functions_mysqli::pwg_db_num_rows($result) == 0) {
            return false;
        }

        [$user_id] = functions_mysqli::pwg_db_fetch_row($result);
        return $user_id;
    }

    /**
     * Returns a array with default user values.
     *
     * @param bool $convert_str converts 'true' and 'false' into booleans
     */
    public static function get_default_user_info(
        bool $convert_str = true
    ): array|bool {
        global $cache, $conf;

        if (! isset($cache['default_user'])) {
            $query = <<<SQL
                SELECT *
                FROM user_infos
                WHERE user_id = {$conf->default_user_id};
                SQL;

            $result = functions_mysqli::pwg_query($query);

            if (functions_mysqli::pwg_db_num_rows($result) > 0) {
                $cache['default_user'] = functions_mysqli::pwg_db_fetch_assoc($result);

                unset($cache['default_user']['user_id']);
                unset($cache['default_user']['status']);
                unset($cache['default_user']['registration_date']);
            } else {
                $cache['default_user'] = false;
            }
        }

        if (is_array($cache['default_user']) &&
            $convert_str
        ) {
            $default_user = $cache['default_user'];

            foreach ($default_user as &$value) {
                // If the field is true or false, the variable is transformed into a boolean value.
                if ($value == 'true') {
                    $value = true;
                } elseif ($value == 'false') {
                    $value = false;
                }
            }

            return $default_user;
        }

        return $cache['default_user'];
    }

    /**
     * Returns a default user value.
     */
    public static function get_default_user_value(
        string $value_name,
        string $default
    ): string {
        $default_user = self::get_default_user_info();

        if ($default_user === false ||
            empty($default_user[$value_name])
        ) {
            return $default;
        }

        return $default_user[$value_name];
    }

    /**
     * Returns the default theme.
     * If the default theme is not available it returns the first available one.
     */
    public static function get_default_theme(): string
    {
        $theme = self::get_default_user_value('theme', PHPWG_DEFAULT_TEMPLATE);

        if (functions::check_theme_installed($theme)) {
            return $theme;
        }

        // let's find the first available theme
        $active_themes = array_keys(functions::get_pwg_themes());
        return $active_themes[0] ?? 'default';
    }

    /**
     * Returns the default language.
     */
    public static function get_default_language(): string
    {
        return self::get_default_user_value('language', PHPWG_DEFAULT_LANGUAGE);
    }

    /**
     * Tries to find the browser language among available languages.
     */
    public static function get_browser_language(): false|string
    {
        $language_header = $_SERVER['HTTP_ACCEPT_LANGUAGE'];

        if ($language_header == '') {
            return false;
        }

        // case insensitive match
        // 'en-US;q=0.9, fr-CH, kok-IN;q=0.7' => 'en_us;q=0.9, fr_ch, kok_in;q=0.7'
        $language_header = strtolower(str_replace('-', '_', $language_header));
        $match_pattern = '/(([a-z]{1,8})(?:_[a-z0-9]{1,8})*)\s*(?:;\s*q\s*=\s*([01](?:\.\d{0,3})?))?/';
        $matches = null;
        preg_match_all($match_pattern, $language_header, $matches);
        $accept_languages_full = $matches[1];  // ['en-us', 'fr-ch', 'kok-in']
        $accept_languages_short = $matches[2];  // ['en', 'fr', 'kok']

        if ($accept_languages_full === []) {
            return false;
        }

        // if the quality value is absent for an language, use 1 as the default
        $q_values = $matches[3];  // ['0.9', '', '0.7']

        foreach (array_keys($q_values) as $i) {
            $q_values[$i] = ($q_values[$i] === '') ? 1 : floatval($q_values[$i]);
        }

        // since quick sort is not stable,
        // sort by $indices explicitly after sorting by $q_values
        $indices = range(1, count($q_values));
        array_multisort(
            $q_values,
            SORT_DESC,
            SORT_NUMERIC,
            $indices,
            SORT_ASC,
            SORT_NUMERIC,
            $accept_languages_full,
            $accept_languages_short
        );

        // list all enabled language codes in the Piwigo installation
        // in both full and short forms, and case insensitive
        $languages_available = [];

        foreach (array_keys(functions::get_languages()) as $language_code) {
            $lowercase_full = strtolower($language_code);
            $lowercase_parts = explode('_', $lowercase_full, 2);
            $lowercase_prefix = $lowercase_parts[0];
            $languages_available[$lowercase_full] = $language_code;
            $languages_available[$lowercase_prefix] = $language_code;
        }

        foreach (array_keys($q_values) as $i) {
            // if the exact language variant is present, make sure it's chosen
            // en-US;q=0.9 => en_us => en_US
            if (array_key_exists($accept_languages_full[$i], $languages_available)) {
                return $languages_available[$accept_languages_full[$i]];
            }
            // only in case that an exact match was not available,
            // should we fallback to other variants in the same language family
            // fr_CH => fr => fr_FR
            elseif (array_key_exists($accept_languages_short[$i], $languages_available)) {
                return $languages_available[$accept_languages_short[$i]];
            }
        }

        return false;
    }

    /**
     * Creates user information based on default values.
     *
     * @param int|array<int> $user_ids
     * @param ?array $override_values values used to override default user values
     */
    public static function create_user_infos(
        array|int $user_ids,
        ?array $override_values = null
    ): void {
        global $conf;

        if (! is_array($user_ids)) {
            $user_ids = [$user_ids];
        }

        if ($user_ids !== []) {
            $inserts = [];
            [$dbnow] = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query('SELECT NOW();'));

            $default_user = self::get_default_user_info(false);

            if ($default_user === false) {
                // Default on structure are used
                $default_user = [];
            }

            if ($override_values !== null) {
                $default_user = array_merge($default_user, $override_values);
            }

            foreach ($user_ids as $user_id) {
                $level = $default_user['level'] ?? 0;

                if ($user_id == $conf->webmaster_id) {
                    $status = 'webmaster';
                    $level = max($conf->available_permission_levels);
                } elseif ($user_id == $conf->guest_id ||
                          $user_id == $conf->default_user_id
                ) {
                    $status = 'guest';
                } else {
                    $status = 'normal';
                }

                $insert = array_merge(
                    array_map(functions_mysqli::pwg_db_real_escape_string(...), $default_user),
                    [
                        'user_id' => $user_id,
                        'status' => $status,
                        'registration_date' => $dbnow,
                        'level' => $level,
                    ]
                );

                $inserts[] = $insert;
            }

            functions_mysqli::mass_inserts('user_infos', array_keys($inserts[0]), $inserts);
        }
    }

    /**
     * Returns the auto login key for an user or false if the user is not found.
     *
     * @param string|null $username file with corresponding username
     */
    public static function calculate_auto_login_key(
        int|string $user_id,
        int|string $time,
        ?string &$username
    ): false|string {
        global $conf;
        $query = <<<SQL
            SELECT {$conf->user_fields['username']} AS username, {$conf->user_fields['password']} AS password
            FROM users
            WHERE {$conf->user_fields['id']} = {$user_id};
            SQL;
        $result = functions_mysqli::pwg_query($query);

        if (functions_mysqli::pwg_db_num_rows($result) > 0) {
            $row = functions_mysqli::pwg_db_fetch_assoc($result);
            $username = stripslashes($row['username']);
            $data = $time . $user_id . $username;
            $key = base64_encode(hash_hmac('sha1', $data, $conf->secret_key . $row['password'], true));
            return $key;
        }

        return false;
    }

    /**
     * Performs all required actions for user login.
     */
    public static function log_user(
        int|string $user_id,
        bool $remember_me
    ): void {
        global $conf, $user;

        if ($remember_me &&
            $conf->authorize_remembering
        ) {
            $now = time();
            $key = self::calculate_auto_login_key($user_id, $now, $username);

            if ($key !== false) {
                $cookie = $user_id . '-' . $now . '-' . $key;
                setcookie(
                    $conf->remember_me_name,
                    $cookie,
                    [
                        'expires' => time() + $conf->remember_me_length,
                        'path' => functions_cookie::cookie_path(),
                        'domain' => ini_get('session.cookie_domain'),
                        'secure' => (bool) ini_get('session.cookie_secure'),
                        'httponly' => (bool) ini_get('session.cookie_httponly'),
                    ]
                );
            }
        } else { // make sure we clean any remember me ...
            setcookie($conf->remember_me_name, '', [
                'expires' => 0,
                'path' => functions_cookie::cookie_path(),
                'domain' => ini_get('session.cookie_domain'),
            ]);
        }

        if (session_id() != '') { // we regenerate the session for security reasons
            // see http://www.acros.si/papers/session_fixation.pdf
            session_regenerate_id(true);
        } else {
            session_start();
        }

        $_SESSION['pwg_uid'] = (int) $user_id;

        $user['id'] = $_SESSION['pwg_uid'];
        functions_plugins::trigger_notify('user_login', $user['id']);
        functions::pwg_activity('user', $user['id'], 'login');
    }

    /**
     * Performs auto-connection when cookie remember_me exists.
     */
    public static function auto_login(): bool
    {
        global $conf;

        if (isset($_COOKIE[$conf->remember_me_name])) {
            $cookie = explode('-', stripslashes($_COOKIE[$conf->remember_me_name]));

            if (count($cookie) === 3 &&
                is_numeric($cookie[0]) &&
                is_numeric($cookie[1]) &&
                time() - $conf->remember_me_length <= $cookie[1] &&
                time() >= $cookie[1] /*cookie generated in the past*/
            ) {
                $key = self::calculate_auto_login_key($cookie[0], $cookie[1], $username);

                if ($key !== false &&
                    $key === $cookie[2]
                ) {
                    self::log_user($cookie[0], true);
                    functions_plugins::trigger_notify('login_success', stripslashes($username));
                    return true;
                }
            }

            setcookie($conf->remember_me_name, '', [
                'expires' => 0,
                'path' => functions_cookie::cookie_path(),
                'domain' => ini_get('session.cookie_domain'),
            ]);
        }

        return false;
    }

    /**
     * Hashes a password.
     *
     * @param string $password plain text
     */
    public static function pwg_password_hash(
        string $password
    ): string {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Verifies a password.
     *
     * @param string $password plain text
     * @param string $hash may be md5 or phpass hashed password
     */
    public static function pwg_password_verify(
        string $password,
        string $hash
    ): bool {
        return password_verify($password, $hash);
    }

    /**
     * Tries to login a user given username and password (must be MySql escaped).
     */
    public static function try_log_user(
        string $username,
        string $password,
        bool $remember_me
    ): bool {
        return functions_plugins::trigger_change('try_log_user', false, $username, $password, $remember_me);
    }

    /**
     * Default method for user login, can be overwritten with 'try_log_user' trigger.
     * @see try_log_user()
     */
    public static function pwg_login(
        bool $success,
        string $username,
        string $password,
        bool $remember_me
    ): bool {
        if ($success) {
            return true;
        }

        // we force the session table to be clean
        functions_session::pwg_session_gc();

        global $conf;

        $user_found = false;
        // retrieving the encrypted password of the login submitted
        $escaped_username = functions_mysqli::pwg_db_real_escape_string($username);
        $query = <<<SQL
            SELECT {$conf->user_fields['id']} AS id, {$conf->user_fields['password']} AS password
            FROM users
            WHERE {$conf->user_fields['username']} = '{$escaped_username}';
            SQL;

        $row = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));

        if (isset($row['id']) &&
            ($conf->password_verify)($password, $row['password'], $row['id'])
        ) {
            $user_found = true;
        }

        // If we didn't find a matching user name, we search for email address
        if (! $user_found) {
            $escaped_username = functions_mysqli::pwg_db_real_escape_string($username);
            $query = <<<SQL
                SELECT {$conf->user_fields['id']} AS id, {$conf->user_fields['password']} AS password
                FROM users
                WHERE {$conf->user_fields['email']} = '{$escaped_username}';
                SQL;

            $row = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));

            if (isset($row['id']) &&
                ($conf->password_verify)($password, $row['password'], $row['id'])
            ) {
                $user_found = true;
            }
        }

        if ($user_found) {
            // if user status is "guest" then she should not be granted to log in.
            // The user may not exist in the user_infos table, so we consider it's a "normal" user by default
            $status = 'normal';

            $query = <<<SQL
                SELECT *
                FROM user_infos
                WHERE user_id = {$row['id']};
                SQL;
            $result = functions_mysqli::pwg_query($query);

            while ($user_infos_row = functions_mysqli::pwg_db_fetch_assoc($result)) {
                $status = $user_infos_row['status'];
            }

            if ($status != 'guest') {
                self::log_user($row['id'], $remember_me);
                functions_plugins::trigger_notify('login_success', stripslashes($username));
                return true;
            }
        }

        functions_plugins::trigger_notify('login_failure', stripslashes($username));
        return false;
    }

    /**
     * Performs all the cleanup on user logout.
     */
    public static function logout_user(): void
    {
        global $conf;

        functions_plugins::trigger_notify('user_logout', $_SESSION['pwg_uid']);
        functions::pwg_activity('user', $_SESSION['pwg_uid'], 'logout');

        $_SESSION = [];
        session_unset();
        session_destroy();
        setcookie(
            session_name(),
            '',
            [
                'expires' => 0,
                'path' => ini_get('session.cookie_path'),
                'domain' => ini_get('session.cookie_domain'),
            ]
        );
        setcookie($conf->remember_me_name, '', [
            'expires' => 0,
            'path' => functions_cookie::cookie_path(),
            'domain' => ini_get('session.cookie_domain'),
        ]);
    }

    /**
     * Return user status.
     *
     * @param string $user_status used if $user not initialized
     */
    public static function get_user_status(
        string $user_status = ''
    ): string {
        global $user;

        if (empty($user_status)) {
            $user_status = $user['status'] ?? '';
        }

        return $user_status;
    }

    /**
     * Return ACCESS_* value for a given $status.
     *
     * @param string $user_status used if $user not initialized
     * @return int one of ACCESS_* constants
     */
    public static function get_access_type_status(
        string $user_status = ''
    ): int {
        global $conf;

        $access_type_status = match (self::get_user_status($user_status)) {
            'guest' => $conf->guest_access ? ACCESS_GUEST : ACCESS_FREE,
            'generic' => ACCESS_GUEST,
            'normal' => ACCESS_CLASSIC,
            'admin' => ACCESS_ADMINISTRATOR,
            'webmaster' => ACCESS_WEBMASTER,
            default => ACCESS_FREE,
        };

        return $access_type_status;
    }

    /**
     * Returns if user has access to a particular ACCESS_*
     *
     * @param bool|int $access_type one of ACCESS_* constants
     * @param string $user_status used if $user not initialized
     */
    public static function is_authorized_status(
        bool|int $access_type,
        string $user_status = ''
    ): bool {
        return self::get_access_type_status($user_status) >= $access_type;
    }

    /**
     * Abort script if user has no access to a particular ACCESS_*
     *
     * @param int $access_type one of ACCESS_* constants
     * @param string $user_status used if $user not initialized
     */
    public static function check_status(
        int $access_type,
        string $user_status = ''
    ): void {
        if (! self::is_authorized_status($access_type, $user_status)) {
            functions_html::access_denied();
        }
    }

    /**
     * Returns if user is generic.
     *
     * @param string $user_status used if $user not initialized
     */
    public static function is_generic(
        string $user_status = ''
    ): bool {
        return self::get_user_status($user_status) === 'generic';
    }

    /**
     * Returns if user is a guest.
     *
     * @param string $user_status used if $user not initialized
     */
    public static function is_a_guest(
        string $user_status = ''
    ): bool {
        return self::get_user_status($user_status) === 'guest';
    }

    /**
     * Returns if user is, at least, a classic user.
     *
     * @param string $user_status used if $user not initialized
     */
    public static function is_classic_user(
        string $user_status = ''
    ): bool {
        return self::is_authorized_status(ACCESS_CLASSIC, $user_status);
    }

    /**
     * Returns if user is, at least, an administrator.
     *
     * @param string $user_status used if $user not initialized
     */
    public static function is_admin(
        string $user_status = ''
    ): bool {
        return self::is_authorized_status(ACCESS_ADMINISTRATOR, $user_status);
    }

    /**
     * Returns if user is a webmaster.
     *
     * @param string $user_status used if $user not initialized
     */
    public static function is_webmaster(
        string $user_status = ''
    ): bool {
        return self::is_authorized_status(ACCESS_WEBMASTER, $user_status);
    }

    /**
     * Returns if current user can edit/delete/validate a comment.
     *
     * @param string $action edit/delete/validate
     */
    public static function can_manage_comment(
        string $action,
        int $comment_author_id
    ): bool {
        global $user, $conf;

        if (self::is_a_guest()) {
            return false;
        }

        if (! in_array($action, ['delete', 'edit', 'validate'])) {
            return false;
        }

        if (self::is_admin()) {
            return true;
        }

        if ($action === 'edit' && $conf->user_can_edit_comment && $comment_author_id == $user['id']
        ) {
            return true;
        }

        return $action === 'delete' && $conf->user_can_delete_comment && $comment_author_id == $user['id'];
    }

    /**
     * Compute sql WHERE condition with restrict and filter data.
     * "FandF" means Forbidden and Filters.
     *
     * @param array{
     *     forbidden_categories: string,
     *     visible_categories: string,
     *     forbidden_images?: string,
     *     visible_images: string
     * } $condition_fields one witch fields apply each filter
     * @param ?string $prefix_condition prefixes query if condition is not empty
     * @param bool $force_one_condition use at least "1 = 1"
     */
    public static function get_sql_condition_FandF(
        array $condition_fields,
        ?string $prefix_condition = null,
        bool $force_one_condition = false
    ): string {
        global $user, $filter;

        $sql_list = [];

        foreach ($condition_fields as $condition => $field_name) {
            switch ($condition) {
                case 'forbidden_categories':
                    if (! empty($user['forbidden_categories'])) {
                        $sql_list[] = $field_name . ' NOT IN (' . $user['forbidden_categories'] . ')';
                    }

                    break;

                case 'visible_categories':
                    if (! empty($filter['visible_categories'])) {
                        $sql_list[] = $field_name . ' IN (' . $filter['visible_categories'] . ')';
                    }

                    break;

                case 'visible_images':
                    if (! empty($filter['visible_images'])) {
                        $sql_list[] = $field_name . ' IN (' . $filter['visible_images'] . ')';
                    }
                    // note there is no break - visible include forbidden
                    // no break

                case 'forbidden_images':
                    if (! empty($user['image_access_list']) ||
                        $user['image_access_type'] != 'NOT IN'
                    ) {
                        $table_prefix = null;

                        if ($field_name == 'id') {
                            $table_prefix = '';
                        } elseif ($field_name == 'i.id') {
                            $table_prefix = 'i.';
                        }

                        if (isset($table_prefix)) {
                            $sql_list[] = $table_prefix . 'level<=' . $user['level'];
                        } elseif (! empty($user['image_access_list']) &&
                                  ! empty($user['image_access_type'])
                        ) {
                            $sql_list[] = $field_name . ' ' . $user['image_access_type'] . ' (' . $user['image_access_list'] . ')';
                        }
                    }

                    break;

                default:
                    exit('Unknown condition');
            }
        }

        if ($sql_list !== []) {
            $sql = '(' . implode(' AND ', $sql_list) . ')';
        } else {
            $sql = $force_one_condition ? '1 = 1' : '';
        }

        if (isset($prefix_condition) &&
            ! empty($sql)
        ) {
            $sql = $prefix_condition . ' ' . $sql;
        }

        return $sql;
    }

    /**
     * Returns sql WHERE condition for recent photos/albums for current user.
     */
    public static function get_recent_photos_sql(
        string $db_field
    ): string {
        global $user;

        if (! isset($user['last_photo_date'])) {
            return '0 = 1';
        }

        $recent_period = functions_mysqli::pwg_db_get_recent_period_expression($user['recent_period']);
        $last_photo_date = functions_mysqli::pwg_db_get_recent_period_expression(1, $user['last_photo_date']);

        return "{$db_field} >= LEAST({$recent_period}, {$last_photo_date})";
    }

    /**
     * Performs auto-connection if authentication key is valid.
     */
    public static function auth_key_login(
        string $auth_key
    ): bool {
        global $conf, $user, $page;

        if (! preg_match('/^[a-z0-9]{30}$/i', $auth_key)) {
            return false;
        }

        $query = <<<SQL
            SELECT *, {$conf->user_fields['username']} AS username, NOW() AS dbnow
            FROM user_auth_keys AS uak
            JOIN user_infos AS ui ON uak.user_id = ui.user_id
            JOIN users AS u ON u.{$conf->user_fields['id']} = ui.user_id
            WHERE auth_key = '{$auth_key}';
            SQL;
        $keys = functions_mysqli::query2array($query);

        if (count($keys) == 0) {
            return false;
        }

        $key = $keys[0];

        // is the key still valid?
        if (strtotime($key['expired_on']) < strtotime($key['dbnow'])) {
            $page['auth_key_invalid'] = true;
            return false;
        }

        // admin/webmaster/guest can't get connected with authentication keys
        if (! in_array($key['status'], ['normal', 'generic'])) {
            return false;
        }

        $user['id'] = $key['user_id'];
        self::log_user($user['id'], false);
        functions_plugins::trigger_notify('login_success', $key['username']);

        // to be registered in history table by pwg_log function
        $page['auth_key_id'] = $key['auth_key_id'];

        return true;
    }

    /**
     * Creates an authentication key.
     *
     * @throws RandomException
     */
    public static function create_user_auth_key(
        int $user_id,
        ?string $user_status = null
    ): false|array {
        global $conf;

        if ($conf->auth_key_duration == 0) {
            return false;
        }

        if (! isset($user_status)) {
            // we have to find the user status
            $query = <<<SQL
                SELECT status
                FROM user_infos
                WHERE user_id = {$user_id};
                SQL;
            $user_infos = functions_mysqli::query2array($query);

            if (count($user_infos) == 0) {
                return false;
            }

            $user_status = $user_infos[0]['status'];
        }

        if (! in_array($user_status, ['normal', 'generic'])) {
            return false;
        }

        $candidate = functions_session::generate_key(30);

        $query = <<<SQL
            SELECT COUNT(*), NOW(), ADDDATE(NOW(), INTERVAL {$conf->auth_key_duration} SECOND)
            FROM user_auth_keys
            WHERE auth_key = '{$candidate}';
            SQL;
        [$counter, $now, $expiration] = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

        if ($counter == 0) {
            $key = [
                'auth_key' => $candidate,
                'user_id' => $user_id,
                'created_on' => $now,
                'duration' => $conf->auth_key_duration,
                'expired_on' => $expiration,
            ];

            functions_mysqli::single_insert('user_auth_keys', $key);

            $key['auth_key_id'] = functions_mysqli::pwg_db_insert_id();

            return $key;
        }

        return self::create_user_auth_key($user_id, $user_status);
    }

    /**
     * Deactivates authentication keys
     */
    public static function deactivate_user_auth_keys(
        int $user_id
    ): void {
        $query = <<<SQL
            UPDATE user_auth_keys
            SET expired_on = NOW()
            WHERE user_id = {$user_id}
                AND expired_on > NOW();
            SQL;
        functions_mysqli::pwg_query($query);
    }

    /**
     * Deactivates password reset key
     */
    public static function deactivate_password_reset_key(
        int $user_id
    ): void {
        functions_mysqli::single_update(
            'user_infos',
            [
                'activation_key' => null,
                'activation_key_expire' => null,
            ],
            [
                'user_id' => $user_id,
            ]
        );
    }

    /**
     * Gets the last visit (datetime) of a user, based on history table
     *
     * @param bool $save_in_user_infos to store result in user_infos.last_visit
     * @return string|null date & time of last visit
     */
    public static function get_user_last_visit_from_history(
        int $user_id,
        bool $save_in_user_infos = false
    ): ?string {
        $last_visit = null;

        $query = <<<SQL
            SELECT date, time
            FROM history
            WHERE user_id = {$user_id}
            ORDER BY id DESC
            LIMIT 1;
            SQL;
        $result = functions_mysqli::pwg_query($query);

        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            $last_visit = $row['date'] . ' ' . $row['time'];
        }

        if ($save_in_user_infos) {
            $last_visit_ = ($last_visit === null ? 'NULL' : "'{$last_visit}'");
            $query = <<<SQL
                UPDATE user_infos
                SET last_visit = {$last_visit_}, last_visit_from_history = 'true', lastmodified = lastmodified
                WHERE user_id = {$user_id};
                SQL;
            functions_mysqli::pwg_query($query);
        }

        return $last_visit;
    }

    /**
     * Save user preferences in database
     */
    public static function userprefs_save(): void
    {
        global $user;

        $dbValue = functions_mysqli::pwg_db_real_escape_string(serialize($user['preferences']));

        $query = <<<SQL
            UPDATE user_infos
            SET preferences = '{$dbValue}'
            WHERE user_id = {$user['id']};
            SQL;
        functions_mysqli::pwg_query($query);
    }

    /**
     * Add or update a user preferences parameter
     */
    public static function userprefs_update_param(
        string $param,
        string|array|bool $value
    ): void {
        global $user;

        // If the field is true or false, the variable is transformed into a boolean value.
        if ($value == 'true') {
            $value = true;
        } elseif ($value == 'false') {
            $value = false;
        }

        $user['preferences'][$param] = $value;

        self::userprefs_save();
    }

    /**
     * Delete one or more user preferences parameters
     *
     * @param string|array<string> $params
     */
    public static function userprefs_delete_param(
        array|string $params
    ): void {
        global $user;

        if (! is_array($params)) {
            $params = [$params];
        }

        if ($params === []) {
            return;
        }

        foreach ($params as $param) {
            if (isset($user['preferences'][$param])) {
                unset($user['preferences'][$param]);
            }
        }

        self::userprefs_save();
    }

    /**
     * Return a default value for a user preferences parameter.
     *
     * @param string $param the configuration value to be extracted (if it exists)
     * @param array|bool|int|string|null $default_value the default value if it does not exist yet.
     * @return array|bool|int|string|null The configuration value if the variable exists, otherwise the default.
     */
    public static function userprefs_get_param(
        string $param,
        array|bool|int|string|null $default_value = null
    ): array|bool|int|string|null {
        global $user;

        return $user['preferences'][$param] ?? $default_value;
    }
}
