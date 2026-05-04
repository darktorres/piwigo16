<?php

declare(strict_types=1);

use Piwigo\Auth\PwgTOTP;
use Piwigo\Users\CurrentUser;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\user
 */


/**
 * Checks if an email is well formed and not already in use.
 *
 * @param int|null $user_id
 * @param string $mail_address
 * @return string|void error message or nothing
 */
function validate_mail_address(?int $user_id, ?string $mail_address)
{
    if (empty($mail_address) and
        !(\Piwigo\Config\Config::obligatoryUserMailAddress() and
        in_array(script_basename(), ['register', 'profile']))) {
        return '';
    }

    if (!email_check_format($mail_address ?? '')) {
        return l10n('mail address must be like xxx@yyy.eee (example : jack@altern.org)');
    }

    if (\Piwigo\Core\InstallSentinel::isInstalled() and !empty($mail_address)) {
        $userFields = \Piwigo\Config\Config::userFields();
        $count = \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)
            ->countByEmail(
                $userFields['email'],
                $userFields['id'],
                USERS_TABLE,
                $mail_address,
                is_numeric($user_id) ? (int) $user_id : null
            );
        if ($count != 0) {
            return l10n('this email address is already in use');
        }
    }
}

/**
 * Checks if a login is not already in use.
 * Comparision is case insensitive.
 *
 * @param string $login
 * @return string|void error message or nothing
 */
function validate_login_case($login)
{
    if (\Piwigo\Core\InstallSentinel::isInstalled()) {
        $count = \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)
            ->countByUsernameInsensitive(
                \Piwigo\Config\Config::userFields()['username'],
                USERS_TABLE,
                (string) $login
            );

        if ($count > 0) {
            return l10n('this login is already used');
        }
    }
}
/**
 * Searches for user with the same username in different case.
 *
 * @param string $username typically typed in by user for identification
 * @return string $username found in database
 */
function search_case_username($username)
{
    $username_lo = strtolower($username);

    $allUsernames = \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)
        ->findAllUsernames(\Piwigo\Config\Config::userFields()['username'], USERS_TABLE);

    $SCU_users = [];
    foreach ($allUsernames as $u) {
        $SCU_users[$u] = strtolower($u);
    }
    // $SCU_users is now an associative table where the key is the account as
    // registered in the DB, and the value is this same account, in lower case

    $users_found = array_keys($SCU_users, $username_lo);
    // $users_found is now a table of which the values are all the accounts
    // which can be written in lowercase the same way as $username
    if (count($users_found) != 1) { // If ambiguous, don't allow lowercase writing
        return $username;
    } // but normal writing will work
    else {
        return (string) $users_found[0];
    }
}

/**
 * Creates a new user.
 *
 * @param string $login
 * @param string $password
 * @param bool $notify_admin
 * @param array &$errors populated with error messages
 * @param bool $notify_user
 * @return int|false user id or false
 */
/** @param string[] $errors */
function register_user(string $login, string $password, ?string $mail_address = null, bool $notify_admin = true, array &$errors = [], bool $notify_user = false): int|false
{
    if ($login == '') {
        $errors[] = l10n('Please, enter a login');
    }
    if (preg_match('/^.* $/', $login)) {
        $errors[] = l10n('login mustn\'t end with a space character');
    }
    if (preg_match('/^ .*$/', $login)) {
        $errors[] = l10n('login mustn\'t start with a space character');
    }
    if (get_userid($login)) {
        $errors[] = l10n('this login is already used');
    }
    if ($login != strip_tags($login)) {
        $errors[] = l10n('html tags are not allowed in login');
    }
    $mail_error = validate_mail_address(null, $mail_address);
    if ('' != $mail_error) {
        $errors[] = $mail_error;
    }

    if (\Piwigo\Config\Config::insensitiveCaseLogon() == true) {
        $login_error = validate_login_case($login);
        if ($login_error != '') {
            $errors[] = $login_error;
        }
    }

    $errors = trigger_change(
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
          \Piwigo\Config\Config::userFields()['username'] => $login,
          \Piwigo\Config\Config::userFields()['password'] => password_hash($password, PASSWORD_BCRYPT),
          \Piwigo\Config\Config::userFields()['email'] => $mail_address,
          ];

        single_insert(USERS_TABLE, $insert);
        $user_id = (int) get_dbal_connection()->lastInsertId();

        // Assign by default groups
        $inserts = [];
        foreach (\Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)->findDefaultGroupIds() as $groupId) {
            $inserts[] = ['user_id' => $user_id, 'group_id' => $groupId];
        }

        if (count($inserts) != 0) {
            mass_inserts(USER_GROUP_TABLE, ['user_id', 'group_id'], $inserts);
        }

        $override = [];
        if (\Piwigo\Config\Config::browserLanguage() and $language = get_browser_language()) {
            $override['language'] = $language;
        }

        create_user_infos((int) $user_id, $override);

        if ($notify_admin and 'none' != \Piwigo\Config\Config::emailAdminOnNewUser()) {
            include_once(PHPWG_ROOT_PATH.'include/functions_mail.inc.php');
            $admin_url = get_absolute_root_url().'admin.php?page=user_list&user_id='.(int) $user_id;

            $keyargs_content = [
              get_l10n_args('User: %s', stripslashes($login)),
              get_l10n_args('Email: %s', $mail_address),
              get_l10n_args(''),
              get_l10n_args('Admin: %s', $admin_url),
              ];

            $group_id = null;
            if (preg_match('/^group:(\d+)$/', \Piwigo\Config\Config::emailAdminOnNewUser(), $matches)) {
                $group_id = $matches[1];
            }

            pwg_mail_notification_admins(
                get_l10n_args('Registration of %s', stripslashes($login)),
                $keyargs_content,
                true, // $send_technical_details
                (int) $group_id
            );
        }

        if ($notify_user and email_check_format($mail_address ?? '')) {
            include_once(PHPWG_ROOT_PATH.'include/functions_mail.inc.php');

            $length = random_int(10, 15);
            $keyargs_content = [
              get_l10n_args('Hello %s,', stripslashes($login)),
              get_l10n_args('Thank you for registering at %s!', \Piwigo\Config\Config::galleryTitle()),
              get_l10n_args('', ''),
              get_l10n_args('Here are your connection settings', ''),
              get_l10n_args('', ''),
              get_l10n_args('Link: %s', get_absolute_root_url()),
              get_l10n_args('Username: %s', stripslashes($login)),
              get_l10n_args('Password: %s', str_repeat('*', $length)),
              get_l10n_args('Email: %s', $mail_address),
              get_l10n_args('', ''),
              get_l10n_args('If you think you\'ve received this email in error, please contact us at %s', get_webmaster_mail_address()),
              ];

            pwg_mail(
                $mail_address ?? '',
                [
                'subject' => '['.\Piwigo\Config\Config::galleryTitle().'] '.l10n('Registration'),
                'content' => l10n_args($keyargs_content),
                'content_format' => 'text/plain',
                ]
            );
        }

        trigger_notify(
            'register_user',
            [
            'id' => $user_id,
            'username' => $login,
            'email' => $mail_address,
            ]
        );

        pwg_activity('user', $user_id, 'add');

        return (int) $user_id;
    } else {
        return false;
    }
}

/**
 * Fetches user data from database.
 * Same that getuserdata() but with additional tests for guest.
 *
 * @param int $user_id
 * @return array{id:int,username:string,email:string,language:string,theme:string,status:string,enabled_high:bool,internal_status:array<string,mixed>,cache_update_time:int,last_visit:string,...}
 */
/** @return array<string,mixed> */
function build_user(int $user_id, bool $use_cache = true): array
{
    $user['id'] = $user_id;
    $user = array_merge($user, getuserdata($user_id, $use_cache) ?: []);

    if ($user['id'] == \Piwigo\Config\Config::guestId() and $user['status'] <> 'guest') {
        $user['status'] = 'guest';
        if (!is_array($user['internal_status'])) {
            $user['internal_status'] = [];
        }
        $user['internal_status']['guest_must_be_guest'] = true;
    }

    // Check user theme. 2 possible problems:
    // 1. the user_infos.theme was not found in the themes table, thus themes.name is null
    // 2. the theme is not really installed on the filesystem
    if (!isset($user['theme_name']) or !check_theme_installed(is_scalar($user['theme']) ? (string) $user['theme'] : '')) {
        $user['theme'] = get_default_theme();
        $user['theme_name'] = $user['theme'];
    }

    return $user;
}

/**
 * Finds informations related to the user identifier.
 *
 * @param int $user_id
 * @param boolean $use_cache
 * @return array
 */
/** @return array<string,mixed>|false */
function getuserdata(int $user_id, bool $use_cache = false): array|false
{
    $logger = \Piwigo\Core\LoggerRegistry::current();

    // retrieve basic user data
    $query = '
SELECT ';
    $is_first = true;
    foreach (\Piwigo\Config\Config::userFields() as $pwgfield => $dbfield) {
        if ($is_first) {
            $is_first = false;
        } else {
            $query .= '
     , ';
        }
        $query .= $dbfield.' AS '.$pwgfield;
    }
    $query .= '
  FROM '.USERS_TABLE.'
  WHERE '.\Piwigo\Config\Config::userFields()['id'].' = \''.$user_id.'\'';

    $conn = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class);
    $row = $conn->executeQuery($query)->fetchAssociative() ?: null;

    // retrieve additional user data ?
    if (\Piwigo\Config\Config::externalAuthentification()) {
        $counter = $conn->executeQuery(
            'SELECT COUNT(1) AS counter FROM ' . USER_INFOS_TABLE . ' AS ui
             LEFT JOIN ' . USER_CACHE_TABLE . ' AS uc ON ui.user_id = uc.user_id
             LEFT JOIN ' . THEMES_TABLE . ' AS t ON t.id = ui.theme
             WHERE ui.user_id = ? GROUP BY ui.user_id',
            [$user_id]
        )->fetchOne();
        if ((is_numeric($counter) ? (int) $counter : 0) !== 1) {
            create_user_infos($user_id);
        }
    }

    // retrieve user info
    $user_infos_row = $conn->executeQuery(
        'SELECT ui.*, uc.*, t.name AS theme_name
         FROM ' . USER_INFOS_TABLE . ' AS ui
         LEFT JOIN ' . USER_CACHE_TABLE . ' AS uc ON ui.user_id = uc.user_id
         LEFT JOIN ' . THEMES_TABLE . ' AS t ON t.id = ui.theme
         WHERE ui.user_id = ?',
        [$user_id]
    )->fetchAssociative() ?: null;

    // then merge basic + additional user data
    $userdata = array_merge($row ?? [], $user_infos_row ?? []);

    foreach ($userdata as &$value) {
        // If the field is true or false, the variable is transformed into a boolean value.
        if ($value == 'true') {
            $value = true;
        } elseif ($value == 'false') {
            $value = false;
        }
    }
    unset($value);

    $userdata['preferences'] = [];

    if ($use_cache) {
        $generate_user_cache = false;
        $ud_id = is_numeric($userdata['id']) ? (int) $userdata['id'] : 0;
        $cache_generation_token_name = 'generate_user_cache-u'.$ud_id;
        $exec_code = substr(sha1(random_bytes(1000)), 0, 4);
        $logger_msg_prefix = '['.__FUNCTION__.'][exec_code='.$exec_code.'][user_id='.$ud_id.'] ';

        if (!isset($userdata['need_update'])
            or !is_bool($userdata['need_update'])
            or $userdata['need_update'] == true) {
            $logger->info($logger_msg_prefix.'needs user_cache to be rebuilt');

            $exec_id = pwg_unique_exec_begins($cache_generation_token_name);
            if (false === $exec_id) {
                $logger->info($logger_msg_prefix.'starts to wait for another request to build user_cache');
                $user_cache_waiting_start_time = get_moment();
                for ($k = 0; $k < 20; $k++) {
                    sleep(1);

                    $query = '
SELECT
   COUNT(*)
  FROM '.USER_CACHE_TABLE.'
  WHERE user_id='.$ud_id.'
;';
                    $nb_cache_lines = $conn->executeQuery($query)->fetchOne();

                    $logger_msg = $logger_msg_prefix.'user_cache generation waiting k='.$k.' ';
                    $waiting_time = get_elapsed_time($user_cache_waiting_start_time, get_moment());

                    if ($nb_cache_lines > 0) {
                        $logger->info($logger_msg.'user_cache rebuilt, after waiting '.$waiting_time);
                        return getuserdata($user_id, false);
                    } elseif (!pwg_unique_exec_is_running($cache_generation_token_name)) {
                        $logger->info($logger_msg.'user_cache rebuilt but has been reset since, give it another try, after waiting '.$waiting_time);
                        return getuserdata($user_id, true);
                    } else {
                        $logger->info($logger_msg.'user_cache not ready yet, after waiting '.$waiting_time);
                    }
                }

                $logger->info($logger_msg_prefix.'user_cache generation waiting has timed out after '.get_elapsed_time($user_cache_waiting_start_time, get_moment()));
                set_status_header(503, 'Service Unavailable');
                if (!headers_sent()) {
                    header('Retry-After: 900');
                }
                header('Content-Type: text/html; charset='.get_pwg_charset());
                echo l10n('Rebuilding user cache takes long. Please, come back later.');
                echo str_repeat(' ', 512); //IE6 doesn't error output if below a size
                exit();
            } else {
                $generate_user_cache = true;
            }
        }

        if ($generate_user_cache) {
            $user_cache_generation_start_time = get_moment();
            $userdata['cache_update_time'] = time();

            // Set need update are done
            $userdata['need_update'] = false;

            $ud_status = is_scalar($userdata['status']) ? (string) $userdata['status'] : '';
            $ud_level = is_numeric($userdata['level']) ? (int) $userdata['level'] : 0;
            $ud_forbidden_cats = calculate_permissions($ud_id, $ud_status);
            $userdata['forbidden_categories'] = $ud_forbidden_cats;

            /* now we build the list of forbidden images (this list does not contain
            images that are not in at least an authorized category)*/
            $query = '
SELECT DISTINCT(id)
  FROM '.IMAGES_TABLE.' INNER JOIN '.IMAGE_CATEGORY_TABLE.' ON id=image_id
  WHERE category_id NOT IN ('.$ud_forbidden_cats.')
    AND level>'.$ud_level;
            $forbidden_ids = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');

            if (empty($forbidden_ids)) {
                $forbidden_ids[] = 0;
            }
            $userdata['image_access_type'] = 'NOT IN';
            $userdata['image_access_list'] = implode(',', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $forbidden_ids));


            $query = '
SELECT COUNT(DISTINCT(image_id)) as total
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE category_id NOT IN ('.$ud_forbidden_cats.')
    AND image_id '.$userdata['image_access_type'].' ('.$userdata['image_access_list'].')';
            $userdata['nb_total_images'] = $conn->executeQuery($query)->fetchOne();


            // now we update user cache categories
            $user_cache_cats = get_computed_categories($userdata, null);
            if (!is_admin($ud_status)) { // for non admins we forbid categories with no image (feature 1053)
                $forbidden_ids = [];
                foreach ($user_cache_cats as $cat) {
                    if ($cat['count_images'] == 0) {
                        $forbidden_ids[] = is_numeric($cat['cat_id']) ? (int) $cat['cat_id'] : 0;
                        remove_computed_category($user_cache_cats, $cat);
                    }
                }
                if (!empty($forbidden_ids)) {
                    $ud_forbidden_cats = is_string($userdata['forbidden_categories'])
                        ? $userdata['forbidden_categories']
                        : '';
                    $forbidden_ids_str = implode(',', array_map(static fn (int $v): string => (string) $v, $forbidden_ids));
                    if (empty($ud_forbidden_cats)) {
                        $userdata['forbidden_categories'] = $forbidden_ids_str;
                    } else {
                        $userdata['forbidden_categories'] = $ud_forbidden_cats.','.$forbidden_ids_str;
                    }
                }
            }

            // delete user cache
            $conn->executeStatement('DELETE FROM ' . USER_CACHE_CATEGORIES_TABLE . ' WHERE user_id = ?', [$ud_id]);

            // Due to concurrency issues, we ask MySQL to ignore errors on
            // insert. This may happen when cache needs refresh and that Piwigo is
            // called "very simultaneously".
            mass_inserts(
                USER_CACHE_CATEGORIES_TABLE,
                [
                'user_id', 'cat_id',
                'date_last', 'max_date_last', 'nb_images', 'count_images', 'nb_categories', 'count_categories',
                ],
                $user_cache_cats,
                ['ignore' => true]
            );


            // update user cache
            $conn->executeStatement('DELETE FROM ' . USER_CACHE_TABLE . ' WHERE user_id = ?', [$ud_id]);

            // for the same reason as user_cache_categories, we ignore error on
            // this insert
            $ud_need_update = ($userdata['need_update'] ?? '') === 'true';
            $ud_cache_update_time = is_numeric($userdata['cache_update_time']) ? (int) $userdata['cache_update_time'] : 0;
            $ud_forbidden_cats_str = is_scalar($userdata['forbidden_categories']) ? (string) $userdata['forbidden_categories'] : '';
            $ud_nb_total_images = is_numeric($userdata['nb_total_images']) ? (int) $userdata['nb_total_images'] : 0;
            $ud_last_photo_date = is_scalar($userdata['last_photo_date']) ? (string) $userdata['last_photo_date'] : '';
            $ud_image_access_type = is_scalar($userdata['image_access_type']) ? (string) $userdata['image_access_type'] : '';
            $ud_image_access_list = is_scalar($userdata['image_access_list']) ? (string) $userdata['image_access_list'] : '';
            $last_photo_placeholder = empty($ud_last_photo_date) ? 'NULL' : '?';
            $cacheParams = [
                $ud_id, $ud_need_update ? 'true' : 'false', $ud_cache_update_time,
                $ud_forbidden_cats_str, $ud_nb_total_images,
                $ud_image_access_type, $ud_image_access_list,
            ];
            if (!empty($ud_last_photo_date)) {
                array_splice($cacheParams, 5, 0, [$ud_last_photo_date]);
            }
            $conn->executeStatement(
                'INSERT IGNORE INTO ' . USER_CACHE_TABLE .
                ' (user_id, need_update, cache_update_time, forbidden_categories, nb_total_images,' .
                '  last_photo_date, image_access_type, image_access_list)' .
                ' VALUES (?, ?, ?, ?, ?, ' . $last_photo_placeholder . ', ?, ?)',
                $cacheParams
            );

            pwg_unique_exec_ends($cache_generation_token_name);
            $logger->info($logger_msg_prefix.'user_cache generated, executed in '.get_elapsed_time($user_cache_generation_start_time, get_moment()));
        }
    }

    return $userdata;
}

/**
 * Deletes favorites of the current user if he's not allowed to see them.
 */
function check_user_favorites(): void
{
    $currentUser = CurrentUser::get();
    $user = $currentUser->rawAttributes;

    if (($user['forbidden_categories'] ?? '') == '') {
        return;
    }

    // $filter['visible_categories'] and $filter['visible_images']
    // must be not used because filter <> restriction
    // retrieving images allowed : belonging to at least one authorized
    // category
    $query = '
SELECT DISTINCT f.image_id
  FROM '.FAVORITES_TABLE.' AS f INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic
    ON f.image_id = ic.image_id
  WHERE f.user_id = '.$currentUser->id.'
  '.get_sql_condition_FandF(
        [
          'forbidden_categories' => 'ic.category_id',
          ],
        'AND'
    ).'
;';
    $authorizeds = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'image_id');

    $query = '
SELECT image_id
  FROM '.FAVORITES_TABLE.'
  WHERE user_id = '.$currentUser->id.'
;';
    $favorites = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'image_id');

    $to_deletes = array_diff(array_map(fn(mixed $v): string => is_scalar($v) ? (string) $v : '0', $favorites), array_map(fn(mixed $v): string => is_scalar($v) ? (string) $v : '0', $authorizeds));
    if (count($to_deletes) > 0) {
        \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)
            ->deleteFavoritesByImageIds(array_map(fn(mixed $v): int => is_numeric($v) ? (int) $v : 0, array_values($to_deletes)));
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
 * @param int $user_id
 * @param string $user_status
 * @return string comma separated ids
 */
function calculate_permissions($user_id, $user_status): string
{
    $query = '
SELECT id
  FROM '.CATEGORIES_TABLE.'
  WHERE status = \'private\'
;';
    $private_array = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');

    // retrieve category ids directly authorized to the user
    $query = '
SELECT cat_id
  FROM '.USER_ACCESS_TABLE.'
  WHERE user_id = '.$user_id.'
;';
    $authorized_array = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'cat_id');

    // retrieve category ids authorized to the groups the user belongs to
    $query = '
SELECT cat_id
  FROM '.USER_GROUP_TABLE.' AS ug INNER JOIN '.GROUP_ACCESS_TABLE.' AS ga
    ON ug.group_id = ga.group_id
  WHERE ug.user_id = '.$user_id.'
;';
    $authorized_array =
      array_merge(
          $authorized_array,
          array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'cat_id')
      );

    // uniquify ids : some private categories might be authorized for the
    // groups and for the user
    $authorized_array = array_unique(array_map(fn(mixed $v): string => is_scalar($v) ? (string) $v : '0', $authorized_array));

    // only unauthorized private categories are forbidden
    $forbidden_array = array_diff(array_map(fn(mixed $v): string => is_scalar($v) ? (string) $v : '0', $private_array), $authorized_array);

    // if user is not an admin, locked categories are forbidden
    if (!is_admin($user_status)) {
        $query = '
SELECT id
  FROM '.CATEGORIES_TABLE.'
  WHERE visible = \'false\'
;';
        $forbidden_array = array_merge($forbidden_array, array_map(fn(mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id')));
        $forbidden_array = array_unique($forbidden_array);
    }

    if (empty($forbidden_array)) {// at least, the list contains 0 value. This category does not exists so
        // where clauses such as "WHERE category_id NOT IN(0)" will always be
        // true.
        $forbidden_array[] = 0;
    }

    return implode(',', $forbidden_array);
}

/**
 * Returns user identifier thanks to his name.
 *
 * @param string $username
 */
function get_userid(string $username): int|false
{
    $userFields = \Piwigo\Config\Config::userFields();
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)
        ->findIdByUsername($userFields['username'], $userFields['id'], USERS_TABLE, $username);
}

/**
 * Returns user identifier thanks to his email.
 *
 * @param string $email
 */
function get_userid_by_email(string $email): int|false
{
    $userFields = \Piwigo\Config\Config::userFields();
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)
        ->findIdByEmail($userFields['email'], $userFields['id'], USERS_TABLE, $email);
}

/**
 * Returns a array with default user valuees.
 *
 *  bool  convert true/false strings to booleans
 * @return array
 */
/** @return array<mixed,mixed>|null */
function get_default_user_info(bool $convert_str = true): ?array
{
    $cache = &$GLOBALS['cache'];
    if (!is_array($cache)) {
        $cache = [];
    }

    if (!isset($cache['default_user'])) {
        $row = \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)
            ->getDefaultUserInfo(\Piwigo\Config\Config::defaultUserId());

        if ($row !== null) {
            unset($row['user_id'], $row['status'], $row['registration_date'], $row['last_visit'], $row['last_visit_from_history']);
            $cache['default_user'] = $row;
        } else {
            $cache['default_user'] = false; // sentinel: queried, no row found
        }
    }

    if (is_array($cache['default_user']) and $convert_str) {
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
    } else {
        return is_array($cache['default_user']) ? $cache['default_user'] : null;
    }
}

/**
 * Returns a default user value.
 *
 * @param string $value_name
 * @param mixed $default
 * @return mixed
 */
function get_default_user_value($value_name, $default)
{
    $default_user = get_default_user_info(true);
    if ($default_user === null or empty($default_user[$value_name])) {
        return $default;
    } else {
        return $default_user[$value_name];
    }
}

/**
 * Returns the default theme.
 * If the default theme is not available it returns the first available one.
 *
 * @return string
 */
function get_default_theme(): string
{
    $theme_raw = get_default_user_value('theme', PHPWG_DEFAULT_TEMPLATE);
    $theme = is_scalar($theme_raw) ? (string) $theme_raw : PHPWG_DEFAULT_TEMPLATE;
    if (check_theme_installed($theme)) {
        return $theme;
    }

    // let's find the first available theme
    $active_themes = array_keys(get_pwg_themes());
    return $active_themes[0] ?? 'default';
}

/**
 * Returns the default language.
 *
 * @return string
 */
function get_default_language(): string
{
    $lang_raw = get_default_user_value('language', PHPWG_DEFAULT_LANGUAGE);
    return is_scalar($lang_raw) ? (string) $lang_raw : PHPWG_DEFAULT_LANGUAGE;
}

/**
 * Tries to find the browser language among available languages.
 *
 * @return string
 */
function get_browser_language(): false|int|string
{
    $language_header_raw = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
    $language_header = is_scalar($language_header_raw) ? (string) $language_header_raw : '';
    if ($language_header == '') {
        return false;
    }

    // case insensitive match
    // 'en-US;q=0.9, fr-CH, kok-IN;q=0.7' => 'en_us;q=0.9, fr_ch, kok_in;q=0.7'
    $language_header = strtolower(str_replace('-', '_', $language_header));
    $match_pattern = '/(([a-z]{1,8})(?:_[a-z0-9]{1,8})*)\s*(?:;\s*q\s*=\s*([01](?:\.[0-9]{0,3})?))?/';
    $matches = null;
    preg_match_all($match_pattern, $language_header, $matches);
    $accept_languages_full = $matches[1];  // ['en-us', 'fr-ch', 'kok-in']
    $accept_languages_short = $matches[2];  // ['en', 'fr', 'kok']
    if (!count($accept_languages_full)) {
        return false;
    }

    // if the quality value is absent for an language, use 1 as the default
    $q_values = $matches[3];  // ['0.9', '', '0.7']
    foreach ($q_values as $i => $q_value) {
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
    foreach (get_languages() as $language_code => $language_name) {
        $lowercase_full = strtolower((string) $language_code);
        $lowercase_parts = explode('_', $lowercase_full, 2);
        $lowercase_prefix = $lowercase_parts[0];
        $languages_available[$lowercase_full] = $language_code;
        $languages_available[$lowercase_prefix] = $language_code;
    }

    foreach ($q_values as $i => $q_value) {
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
 * Creates user informations based on default values.
 *
 * @param int|int[] $user_ids
 * @param array $override_values values used to override default user values
 */
/**
 * @param int[]|int $user_ids
 * @param array<mixed>|null $override_values
 */
function create_user_infos(array|int $user_ids, ?array $override_values = null): void
{
    if (!is_array($user_ids)) {
        $user_ids = [$user_ids];
    }

    if (!empty($user_ids)) {
        $inserts = [];
        $dbnow = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $default_user = get_default_user_info(false);
        if ($default_user === null) {
            // Default on structure are used
            $default_user = [];
        }

        if (!is_null($override_values)) {
            $default_user = array_merge($default_user, $override_values);
        }

        foreach ($user_ids as $user_id) {
            $level = $default_user['level'] ?? 0;
            if ($user_id == \Piwigo\Config\Config::webmasterId()) {
                $status = 'webmaster';
                $level = max(\Piwigo\Config\Config::availablePermissionLevels());
            } elseif (($user_id == \Piwigo\Config\Config::guestId()) or
                     ($user_id == \Piwigo\Config\Config::defaultUserId())) {
                $status = 'guest';
            } else {
                $status = 'normal';
            }

            $insert = array_merge(
                array_map(static function (mixed $v): string {
                    return is_scalar($v) ? (string) $v : '';
                }, $default_user),
                [
                'user_id' => $user_id,
                'status' => $status,
                'registration_date' => $dbnow,
                'level' => $level,
                ]
            );

            $inserts[] = $insert;
        }

        mass_inserts(USER_INFOS_TABLE, array_keys($inserts[0]), $inserts);
    }
}

/**
 * Returns the auto login key for an user or false if the user is not found.
 *
 * @param int $user_id
 * @param int $time
 * @param string &$username fille with corresponding username
 * @return string|false
 */
function calculate_auto_login_key($user_id, $time, &$username): string|false
{
    $userFields = \Piwigo\Config\Config::userFields();
    $row = \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)
        ->findAuthFieldsById(
            $userFields['username'],
            $userFields['password'],
            $userFields['id'],
            USERS_TABLE,
            (int) $user_id
        );
    if ($row !== null) {
        $username = stripslashes($row['username']);
        $data = $time.$user_id.$username;
        return base64_encode(hash_hmac('sha1', $data, \Piwigo\Config\Config::secretKey().$row['password'], true));
    }
    return false;
}

/**
 * Performs all required actions for user login.
 *
 * @param int $user_id
 * @param bool $remember_me
 */
function log_user($user_id, $remember_me): void
{
    // If the user changed language on the login page, sync the stored preference.
    $cookie_lang = isset($_COOKIE['lang']) && is_scalar($_COOKIE['lang']) ? (string) $_COOKIE['lang'] : '';
    if ($cookie_lang !== '' and CurrentUser::get()->language != $cookie_lang) {
        if (!array_key_exists($cookie_lang, get_languages())) {
            fatal_error('[Hacking attempt] the input parameter "'.$cookie_lang.'" is not valid');
        }

        single_update(
            USER_INFOS_TABLE,
            ['language' => $cookie_lang],
            ['user_id' => $user_id]
        );

        // We unset the lang cookie, if user has changed their language using interface we don't want to keep setting it back
        // to what was chosen using standard pages lang switch
        setcookie('lang', '', ['expires' => time() - 3600, 'samesite' => 'Strict']);
    }

    if ($remember_me and \Piwigo\Config\Config::authorizeRemembering()) {
        $now = time();
        $key = calculate_auto_login_key($user_id, $now, $username);
        if ($key !== false) {
            $cookie = $user_id.'-'.$now.'-'.$key;
            setcookie(
                \Piwigo\Config\Config::rememberMeName(),
                $cookie,
                ['expires' => time() + \Piwigo\Config\Config::rememberMeLength(), 'path' => (string) cookie_path(), 'domain' => (string) ini_get('session.cookie_domain'), 'secure' => (bool) ini_get('session.cookie_secure'), 'httponly' => (bool) ini_get('session.cookie_httponly'), 'samesite' => 'Strict']
            );
        }
    } else { // make sure we clean any remember me ...
        setcookie(\Piwigo\Config\Config::rememberMeName(), '', ['expires' => 0, 'path' => (string) cookie_path(), 'domain' => (string) ini_get('session.cookie_domain'), 'samesite' => 'Strict']);
    }
    if (session_id() != '') { // we regenerate the session for security reasons
        // see http://www.acros.si/papers/session_fixation.pdf
        session_regenerate_id(true);
    } else {
        session_start();
    }
    $_SESSION['pwg_uid'] = (int)$user_id;

    $user['id'] = $_SESSION['pwg_uid'];
    trigger_notify('user_login', $user['id']);
    pwg_activity('user', $user['id'], 'login');
}

/**
 * Performs auto-connection when cookie remember_me exists.
 */
function auto_login(): bool
{
    $remember_cookie_raw = $_COOKIE[\Piwigo\Config\Config::rememberMeName()] ?? null;
    if (isset($remember_cookie_raw)) {
        $cookie = explode('-', stripslashes(is_scalar($remember_cookie_raw) ? (string) $remember_cookie_raw : ''));
        if (count($cookie) === 3
            and is_numeric($cookie[0]) /*user id*/
            and is_numeric($cookie[1]) /*time*/
            and time() - \Piwigo\Config\Config::rememberMeLength() <= $cookie[1]
            and time() >= $cookie[1] /*cookie generated in the past*/) {
            $key = calculate_auto_login_key((int)$cookie[0], (int)$cookie[1], $username);
            if ($key !== false and $key === $cookie[2]) {
                // Since Piwigo 16, 'connected_with' in the session defines the authentication context (UI, API, etc).
                // Auto-login via remember-me may miss this, so we set it to 'pwg_ui' for UI logins (not API).
                if (script_basename() != 'ws') {
                    $_SESSION['connected_with'] = 'pwg_ui';
                }
                log_user((int)$cookie[0], true);
                trigger_notify('login_success', stripslashes($username));
                return true;
            }
        }
        setcookie(\Piwigo\Config\Config::rememberMeName(), '', ['expires' => 0, 'path' => (string) cookie_path(), 'domain' => (string) ini_get('session.cookie_domain'), 'samesite' => 'Strict']);
    }
    return false;
}

/**
 * Tries to login a user given username and password (must be MySql escaped).
 *
 * @param string $username
 * @param string $password
 * @param bool $remember_me
 * @return bool
 */
function try_log_user($username, $password, $remember_me): bool
{
    return (bool) trigger_change('try_log_user', false, $username, $password, $remember_me);
}

add_event_handler('try_log_user', 'pwg_login');

/**
 * Default method for user login, can be overwritten with 'try_log_user' trigger.
 * @see try_log_user()
 *
 * @param string $username
 * @param string $password
 * @param bool $remember_me
 */
function pwg_login(bool $success, string $username, string $password, bool $remember_me): bool
{
    if ($success === true) {
        return true;
    }

    // we force the session table to be clean
    pwg_session_gc();

    // Find user by username or email (if it exists)
    $user_found = find_user_by_username_or_email($username);

    // SECURITY: Constant-time authentication to prevent timing attacks
    //
    // We always perform password verification, even when the user doesn't exist,
    // to prevent attackers from distinguishing between:
    //  - "user exists, wrong password" (slow: runs password_verify)
    //  - "user doesn't exist" (fast: would skip verification)
    //
    // This timing difference could allow user enumeration. By using a fake user
    // with a pre-generated hash, we ensure consistent execution time regardless
    // of whether the account exists or not.
    $fake_user = generate_fake_user();

    // Verify password with fallback to fake user
    $hash = $user_found['password'] ?? $fake_user['password'];
    $password_verify = password_verify($password, is_string($hash) ? $hash : '');

    $uf_id = is_numeric($user_found['id'] ?? null) ? (int) $user_found['id'] : 0;

    // If the user was not found, is a guest, or the password is incorrect
    if (empty($user_found) || 'guest' === $user_found['status'] || !$password_verify) {
        if (!empty($user_found) && !$password_verify) {
            pwg_activity('user', $uf_id, 'login_failure_wrong_password');
        }
        trigger_notify('login_failure', stripslashes($username));
        return false;
    }

    // PLUGIN HOOK: Allow plugins to intercept authentication before log_user()
    //
    // Expected $state array structure:
    //  - 'can_login' (bool): Set to false to block login
    //  - 'reason' (string|null): Custom activity log reason if login blocked
    //  - 'authenticated' (bool): Set to true if plugin handles log_user() itself
    //
    // Example plugin implementation:
    //   add_event_handler('finalize_login', 'my_2fa_check');
    //   function my_2fa_check($state, $user, $remember_me) {
    //     if (!verify_2fa_code()) {
    //       $state['can_login'] = false;
    //       $state['reason'] = '2fa_failed';
    //     }
    //     return $state;
    //   }
    $state_init = [
      'can_login' => true,
      'reason' => null,
      'authenticated' => false,
    ];
    $state = trigger_change('finalize_login', $state_init, $user_found, $remember_me);

    if (!$state['can_login']) {
        $state_reason = is_scalar($state['reason']) ? (string) $state['reason'] : 'login_failure_before_log_user';
        pwg_activity('user', $uf_id, $state_reason);
        trigger_notify('login_failure_before_log_user', stripslashes($username));
        return false;
    }

    // If plugin handled authentication, skip log_user()
    if (!$state['authenticated']) {
        log_user($uf_id, $remember_me);
    }

    clear_fake_user_cache();
    trigger_notify('login_success', stripslashes($username));
    return true;
}


/**
 * Find user by username or email
 * search by username first then email
 *
 * @since 16
 * @param string $username_or_email
 * @return array|null
 */
/** @return array<string,mixed>|null */
function find_user_by_username_or_email(string $username_or_email): ?array
{
    $userFields = \Piwigo\Config\Config::userFields();
    $user = \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)
        ->findByUsernameOrEmail(
            $userFields['username'],
            $userFields['email'],
            $userFields['id'],
            $userFields['password'],
            USERS_TABLE,
            $username_or_email
        );

    if (!empty($user)) {
        // The user may not exist in the user_infos table, so we consider it's a "normal" user by default
        $user['status'] ??= 'normal';
        return $user;
    }

    return null;
}

/**
 * Generate a fake user with hashed password (with the current algo)
 *
 * SECURITY: This function is used for timing attack mitigation in pwg_login().
 * The fake user hash is cached per session to avoid repeated hashing overhead
 * while maintaining constant-time authentication behavior.
 *
 * @since 16
 * @return array id and password
 */
/** @return array<string,mixed> */
function generate_fake_user(): array
{
    // Generate once per session to avoid repeated hashing overhead.
    // Uses bcrypt to match real user verification timing.
    if (!isset($_SESSION['fake_user_cache'])) {
        $fake_password = bin2hex(random_bytes(10));
        $_SESSION['fake_user_cache'] = [
          'id' => null,
          'password' => password_hash($fake_password, PASSWORD_BCRYPT),
        ];
    }

    $fake_cache = $_SESSION['fake_user_cache'];
    return is_array($fake_cache) ? $fake_cache : ['id' => null, 'password' => ''];
}

/**
 * Clear current session fake user cache
 *
 * @since 16
 */
function clear_fake_user_cache(): void
{
    unset($_SESSION['fake_user_cache']);
}

/**
 * Performs all the cleanup on user logout.
 */
function logout_user(): void
{
    $logout_uid = isset($_SESSION['pwg_uid']) && is_numeric($_SESSION['pwg_uid']) ? (int) $_SESSION['pwg_uid'] : 0;
    trigger_notify('user_logout', $logout_uid);
    pwg_activity('user', $logout_uid, 'logout');

    $_SESSION = [];
    session_unset();
    session_destroy();
    setcookie(
        (string) session_name(),
        '',
        ['expires' => 0, 'path' => (string) ini_get('session.cookie_path'), 'domain' => (string) ini_get('session.cookie_domain'), 'samesite' => 'Strict']
    );
    setcookie(\Piwigo\Config\Config::rememberMeName(), '', ['expires' => 0, 'path' => (string) cookie_path(), 'domain' => (string) ini_get('session.cookie_domain'), 'samesite' => 'Strict']);
}

/**
 * Return user status.
 *
 * @param string $user_status used if $user not initialized
 * @return string
 */
function get_user_status($user_status = '')
{
    if (empty($user_status)) {
        if (CurrentUser::isInitialized()) {
            $user_status = CurrentUser::get()->status;
        } else {
            // swicth to default value
            $user_status = '';
        }
    }
    return $user_status;
}

/**
 * Return ACCESS_* value for a given $status.
 *
 * @param string $user_status used if $user not initialized
 * @return int one of ACCESS_* constants
 */
function get_access_type_status($user_status = ''): int
{
    switch (get_user_status($user_status)) {
        case 'guest':
            {
                $access_type_status =
                  (\Piwigo\Config\Config::guestAccess() ? ACCESS_GUEST : ACCESS_FREE);
                break;
            }
        case 'generic':
            {
                $access_type_status = ACCESS_GUEST;
                break;
            }
        case 'normal':
            {
                $access_type_status = ACCESS_CLASSIC;
                break;
            }
        case 'admin':
            {
                $access_type_status = ACCESS_ADMINISTRATOR;
                break;
            }
        case 'webmaster':
            {
                $access_type_status = ACCESS_WEBMASTER;
                break;
            }
        default:
            {
                $access_type_status = ACCESS_FREE;
                break;
            }
    }

    return $access_type_status;
}

/**
 * Returns if user has access to a particular ACCESS_*
 *
 *  int one of ACCESS_* constants
 * @param string $user_status used if $user not initialized
 * @return bool
 */
function is_autorize_status(int $access_type, string $user_status = ''): bool
{
    return (get_access_type_status($user_status) >= $access_type);
}

/**
 * Abord script if user has no access to a particular ACCESS_*
 *
 *  int one of ACCESS_* constants
 * @param string $user_status used if $user not initialized
 */
function check_status(int $access_type, string $user_status = ''): void
{
    if (!is_autorize_status($access_type, $user_status)) {
        access_denied();
    }
}

/**
 * Returns if user is generic.
 *
 * @param string $user_status used if $user not initialized
 */
function is_generic($user_status = ''): bool
{
    return get_user_status($user_status) == 'generic';
}

/**
 * Returns if user is a guest.
 *
 * @param string $user_status used if $user not initialized
 */
function is_a_guest($user_status = ''): bool
{
    return get_user_status($user_status) == 'guest';
}

/**
 * Returns if user is, at least, a classic user.
 *
 * @param string $user_status used if $user not initialized
 */
function is_classic_user($user_status = ''): bool
{
    return is_autorize_status(ACCESS_CLASSIC, $user_status);
}

/**
 * Returns if user is, at least, an administrator.
 *
 * @param string $user_status used if $user not initialized
 */
function is_admin($user_status = ''): bool
{
    return is_autorize_status(ACCESS_ADMINISTRATOR, $user_status);
}

/**
 * Returns if user is a webmaster.
 *
 * @param string $user_status used if $user not initialized
 */
function is_webmaster($user_status = ''): bool
{
    return is_autorize_status(ACCESS_WEBMASTER, $user_status);
}

/**
 * Returns if current user can edit/delete/validate a comment.
 *
 * @param string $action edit/delete/validate
 * @param int $comment_author_id
 */
function can_manage_comment($action, $comment_author_id): bool
{
    if (is_a_guest()) {
        return false;
    }

    if (!in_array($action, ['delete','edit', 'validate'])) {
        return false;
    }

    if (is_admin()) {
        return true;
    }

    $currentUserId = CurrentUser::get()->id;

    if ('edit' == $action and \Piwigo\Config\Config::userCanEditComment()) {
        if ($comment_author_id == $currentUserId) {
            return true;
        }
    }

    if ('delete' == $action and \Piwigo\Config\Config::userCanDeleteComment()) {
        if ($comment_author_id == $currentUserId) {
            return true;
        }
    }

    return false;
}

/**
 * Compute sql WHERE condition with restrict and filter data.
 * "FandF" means Forbidden and Filters.
 *
 * @param array $condition_fields one witch fields apply each filter
 *    - forbidden_categories
 *    - visible_categories
 *    - forbidden_images
 *    - visible_images
 * @param string $prefix_condition prefixes query if condition is not empty
 * @param boolean $force_one_condition use at least "1 = 1"
 */
/** @param array<string,string> $condition_fields */
function get_sql_condition_FandF(
    array $condition_fields,
    ?string $prefix_condition = null,
    bool $force_one_condition = false
): string {
    $filter = is_array($GLOBALS['filter'] ?? null) ? $GLOBALS['filter'] : [];

    $user = CurrentUser::get()->rawAttributes;

    $sql_list = [];

    foreach ($condition_fields as $condition => $field_name) {
        switch ($condition) {
            case 'forbidden_categories':
                {
                    if (!empty($user['forbidden_categories'])) {
                        $sql_list[] =
                          $field_name.' NOT IN ('.(is_scalar($user['forbidden_categories']) ? (string) $user['forbidden_categories'] : '').')';
                    }
                    break;
                }
            case 'visible_categories':
                {
                    $visibleCategories = is_scalar($filter['visible_categories'] ?? null) ? (string) $filter['visible_categories'] : '';
                    if ($visibleCategories !== '') {
                        $sql_list[] = $field_name.' IN ('.$visibleCategories.')';
                    }
                    break;
                }
            case 'visible_images':
                $visibleImages = is_scalar($filter['visible_images'] ?? null) ? (string) $filter['visible_images'] : '';
                if ($visibleImages !== '') {
                    $sql_list[] = $field_name.' IN ('.$visibleImages.')';
                }
                // note there is no break - visible include forbidden
                // no break
            case 'forbidden_images':
                if (
                    !empty($user['image_access_list'])
                    or ($user['image_access_type'] ?? null) != 'NOT IN'
                ) {
                    $table_prefix = null;
                    if ($field_name == 'id') {
                        $table_prefix = '';
                    } elseif ($field_name == 'i.id') {
                        $table_prefix = 'i.';
                    }
                    if (isset($table_prefix)) {
                        $sql_list[] = $table_prefix.'level<='.(is_scalar($user['level'] ?? null) ? (string) $user['level'] : '0');
                    } elseif (!empty($user['image_access_list']) and !empty($user['image_access_type'])) {
                        $sql_list[] = $field_name.' '.(is_scalar($user['image_access_type']) ? (string) $user['image_access_type'] : '')
                            .' ('.(is_scalar($user['image_access_list']) ? (string) $user['image_access_list'] : '').')';
                    }
                }
                break;
            default:
                {
                    throw new \LogicException('Unknown condition');
                }
        }
    }

    if (count($sql_list) > 0) {
        $sql = '('.implode(' AND ', $sql_list).')';
    } else {
        $sql = $force_one_condition ? '1 = 1' : '';
    }

    if (isset($prefix_condition) and !empty($sql)) {
        $sql = $prefix_condition.' '.$sql;
    }

    return $sql;
}

/**
 * Returns sql WHERE condition for recent photos/albums for current user.
 */
function get_recent_photos_sql(string $db_field): string
{
    $user = CurrentUser::get()->rawAttributes;
    if (!isset($user['last_photo_date'])) {
        return '0=1';
    }
    $recentPeriod = is_numeric($user['recent_period'] ?? null) ? (int) $user['recent_period'] : 0;
    $lastPhotoDate = is_scalar($user['last_photo_date']) ? (string) $user['last_photo_date'] : '';
    return $db_field.'>=LEAST('
      .\Piwigo\Db\SqlExpr::recentPeriodExpr($recentPeriod)
      .','.\Piwigo\Db\SqlExpr::recentPeriodExpr(1, $lastPhotoDate).')';
}

/**
 * Performs auto-connection if authentication key is valid.
 *
 * @since 2.8
 */
function auth_key_login(string $auth_key, bool $connection_by_header = false): bool
{
    $page = &$GLOBALS['page'];
    if (!is_array($page)) {
        $page = [];
    }
    $user = &$GLOBALS['user'];
    if (!is_array($user)) {
        $user = [];
    }
    $valid_key = false;
    $secret_key = null;
    if (preg_match('/^[a-z0-9]{30}$/i', (string) $auth_key)) {
        $valid_key = 'auth_key';
    } elseif (preg_match('/^pkid-\d{8}-[a-z0-9]{20}:[a-z0-9]{40}$/i', (string) $auth_key)) {
        $valid_key = 'api_key';
        $tmp_key = explode(':', (string) $auth_key);
        $auth_key = $tmp_key[0];
        $secret_key = $tmp_key[1];
    }

    if (!$valid_key) {
        return false;
    }

    $query = '
SELECT
    *,
    '.\Piwigo\Config\Config::userFields()['username'].' AS username,
    '.\Piwigo\Config\Config::userFields()['email'].' AS email,
    NOW() AS dbnow,
    DATEDIFF(uak.expired_on, NOW()) AS days_left,
    SUBDATE(NOW(), INTERVAL 48 HOUR) AS 48h_ago
  FROM '.USER_AUTH_KEYS_TABLE.' AS uak
    JOIN '.USER_INFOS_TABLE.' AS ui ON uak.user_id = ui.user_id
    JOIN '.USERS_TABLE.' AS u ON u.'.\Piwigo\Config\Config::userFields()['id'].' = ui.user_id
  WHERE auth_key = '.get_dbal_connection()->quote((string) $auth_key).'
;';
    $keys = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();

    if (count($keys) == 0) {
        return false;
    }

    $key = $keys[0];

    // is the key still valid?
    if (strtotime(is_scalar($key['expired_on']) ? (string) $key['expired_on'] : '') < strtotime(is_scalar($key['dbnow']) ? (string) $key['dbnow'] : '')) {
        $page['auth_key_invalid'] = true;
        return false;
    }

    // admin/webmaster/guest can't get connected with authentication keys
    if ('auth_key' === $valid_key and !in_array($key['status'], ['normal','generic'])) {
        return false;
    }

    // the key is an api_key
    if ('api_key' === $valid_key) {
        // check secret
        $apikey_secret = is_scalar($key['apikey_secret']) ? (string) $key['apikey_secret'] : '';
        if (!password_verify((string) $secret_key, $apikey_secret)) {
            return false;
        }

        // is the key is revoked?
        if (null != $key['revoked_on']) {
            return false;
        }

        // check if we need to notificate the user
        $days_left = is_numeric($key['days_left']) ? (int) $key['days_left'] : 0;
        if (
            $days_left <= 7 // the key expire in max 7 days
            and !empty($key['email']) // the user have an email
            and (
                null === $key['last_notified_on'] // we never send an email for this key
                or strtotime(is_scalar($key['last_notified_on']) ? (string) $key['last_notified_on'] : '') < strtotime(is_scalar($key['48h_ago']) ? (string) $key['48h_ago'] : '') // OR when the last email was sent more than 48 hours ago
            )
        ) {
            $page['notify_api_key_expiration'] = [
              'days_left' => $days_left,
              'dbnow' => $key['dbnow'],
              'auth_key' => $key['auth_key'],
            ];
        }
    }

    $user['id'] = $key['user_id'];
    CurrentUser::setRawAttributes($user);

    // update last used key
    single_update(
        USER_AUTH_KEYS_TABLE,
        ['last_used_on' => $key['dbnow']],
        [
        'user_id' => $user['id'],
        'auth_key' => $key['auth_key'],
    ],
    );

    // set the type of connection
    $_SESSION['connected_with'] = $valid_key;

    // if the connection is made via an API key in the header,
    // access is authenticated without creating a persistent user session
    // this enables stateless authentication for API calls
    if ($connection_by_header) {
        return true;
    }

    log_user(is_numeric($user['id']) ? (int) $user['id'] : 0, false);
    trigger_notify('login_success', $key['username']);

    // to be registered in history table by pwg_log function
    $page['auth_key_id'] = $key['auth_key_id'];

    return true;
}

/**
 * Creates an authentication key.
 *
 * @since 2.8
 * @param int $user_id
 * @return array|false
 */
/** @return array<string,mixed>|false */
function create_user_auth_key(int $user_id, ?string $user_status = null): array|false
{
    if (0 == \Piwigo\Config\Config::authKeyDuration()) {
        return false;
    }

    if (!isset($user_status)) {
        // we have to find the user status
        $query = '
SELECT
    status
  FROM '.USER_INFOS_TABLE.'
  WHERE user_id = '.$user_id.'
;';
        $user_infos = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();

        if (count($user_infos) == 0) {
            return false;
        }

        $user_status = is_scalar($user_infos[0]['status']) ? (string) $user_infos[0]['status'] : null;
    }

    if (!in_array($user_status, ['normal','generic'])) {
        return false;
    }

    $candidate = generate_key(30);

    $authKeyRepo = \Piwigo\Core\ServiceLocator::get(\Piwigo\Auth\AuthKeyRepository::class);
    if (!$authKeyRepo->existsByKey($candidate)) {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $duration = \Piwigo\Config\Config::authKeyDuration();
        $expiration = (new \DateTimeImmutable())->modify('+' . $duration . ' seconds')->format('Y-m-d H:i:s');
        $key = [
          'auth_key' => $candidate,
          'user_id' => $user_id,
          'created_on' => $now,
          'duration' => $duration,
          'expired_on' => $expiration,
          'key_type' => 'auth_key',
          ];

        single_insert(USER_AUTH_KEYS_TABLE, $key);

        $key['auth_key_id'] = (int) get_dbal_connection()->lastInsertId();

        return $key;
    } else {
        return create_user_auth_key($user_id, $user_status);
    }
}

/**
 * Deactivates authentication keys
 *
 * @since 2.8
 * @param int $user_id
 */
function deactivate_user_auth_keys($user_id): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Auth\AuthKeyRepository::class)
        ->deactivateForUser((int) $user_id);
}

/**
 * Deactivates password reset key
 *
 * @since 11
 * @param int $user_id
 */
function deactivate_password_reset_key($user_id): void
{
    single_update(
        USER_INFOS_TABLE,
        [
        'activation_key' => null,
        'activation_key_expire' => null,
        ],
        ['user_id' => $user_id]
    );
}

/**
 * Generate reset password link
 *
 * @since 15
 * @param int $user_id
 * @param boolean $first_login
 * @return array time_validation and password link
 */
/** @return array<string,mixed> */
function generate_password_link(int $user_id, bool $first_login = false): array
{
    $activation_key = generate_key(20);

    $duration = $first_login
    ? \Piwigo\Config\Config::passwordActivationDuration()
    : \Piwigo\Config\Config::passwordResetDuration();
    $expire = (new \DateTimeImmutable())->modify('+' . $duration . ' seconds')->format('Y-m-d H:i:s');

    single_update(
        USER_INFOS_TABLE,
        [
        'activation_key' => password_hash($activation_key, PASSWORD_BCRYPT),
        'activation_key_expire' => $expire,
        ],
        ['user_id' => $user_id]
    );

    set_make_full_url();

    $password_link = get_root_url().'password.php?key='.$activation_key;

    unset_make_full_url();

    $time_validation = time_since(
        strtotime('now -'.$duration.' second') ?: null,
        'second',
        null,
        false
    );

    return [
      'time_validation' => $time_validation,
      'password_link' => $password_link,
    ];
}

/**
 * Gets the last visit (datetime) of a user, based on history table
 *
 * @since 2.9
 * @param int $user_id
 * @param boolean $save_in_user_infos to store result in user_infos.last_visit
 * @return string date & time of last visit
 */
function get_user_last_visit_from_history($user_id, $save_in_user_infos = false): ?string
{
    $last_visit = null;

    $query = '
SELECT
    date,
    time
FROM '.HISTORY_TABLE.'
  WHERE user_id = '.$user_id.'
  ORDER BY id DESC
  LIMIT 1
;';
    $histRow = \Piwigo\Core\ServiceLocator::get(\Piwigo\History\HistoryRepository::class)
        ->findLastVisitByUserId((int) $user_id);
    if ($histRow !== null) {
        $last_visit = $histRow['date'] . ' ' . $histRow['time'];
    }

    if ($save_in_user_infos) {
        \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)
            ->updateLastVisitFromHistory((int) $user_id, $last_visit ?? null);
    }

    return $last_visit;
}

/**
 * Save user preferences in database
 * @since 13
 */
function userprefs_save(): void
{
    $user = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
    $preferences = $user['preferences'] ?? [];

    \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)
        ->updatePreferences(CurrentUser::get()->id, serialize($preferences));
}

/**
 * Add or update a user preferences parameter
 * @since 13
 *
 * @param string $param
 * @param mixed $value
 */
function userprefs_update_param($param, $value): void
{
    $user = &$GLOBALS['user'];
    if (!is_array($user)) {
        $user = [];
    }
    // If the field is true or false, the variable is transformed into a boolean value.
    if ('true' == $value) {
        $value = true;
    } elseif ('false' == $value) {
        $value = false;
    }

    if (!isset($user['preferences']) || !is_array($user['preferences'])) {
        $user['preferences'] = [];
    }
    $user['preferences'][$param] = $value;

    userprefs_save();
}

/**
 * Delete one or more user preferences parameters
 * @since 13
 *
 * @param string|string[] $params
 */
function userprefs_delete_param($params): void
{
    $user = &$GLOBALS['user'];
    if (!is_array($user)) {
        $user = [];
    }
    if (!is_array($params)) {
        $params = [$params];
    }
    if (empty($params)) {
        return;
    }

    if (!isset($user['preferences']) || !is_array($user['preferences'])) {
        $user['preferences'] = [];
    }
    foreach ($params as $param) {
        if (isset($user['preferences'][$param])) {
            unset($user['preferences'][$param]);
        }
    }

    userprefs_save();
}

/**
 * Return a default value for a user preferences parameter.
 * @since 13
 *
 * @param string $param the configuration value to be extracted (if it exists)
 * @param mixed $default_value the default value if it does not exist yet.
 *
 * @return mixed The configuration value if the variable exists, otherwise the default.
 */
function userprefs_get_param($param, $default_value = null)
{
    $user = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
    $preferences = is_array($user['preferences'] ?? null) ? $user['preferences'] : [];

    return $preferences[$param] ?? $default_value;
}

/**
 * See if this is the first time the user has logged on
 *
 * @since 15
 * @param int $user_id
 * @return bool true if first connexion else false
 */
function has_already_logged_in($user_id): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Activity\ActivityRepository::class)
        ->hasLoggedIn((int) $user_id);
}

/**
 * Check all user infos and save parameters
 *
 * @since 16
 * @param mixed[] $params
 *    @option string username (optional)
 *    @option string password (optional)
 *    @option string email (optional)
 *    @option string status (optional)
 *    @option int level (optional)
 *    @option string language (optional)
 *    @option string theme (optional)
 *    @option int nb_image_page (optional)
 *    @option int recent_period (optional)
 *    @option bool expand (optional)
 *    @option bool show_nb_comments (optional)
 *    @option bool show_nb_hits (optional)
 *    @option bool enabled_high (optional)
 * @return mixed[]
 */
function check_and_save_user_infos(array $params): array
{
    if (isset($params['username']) and strlen(str_replace(' ', '', is_scalar($params['username']) ? (string) $params['username'] : '')) == 0) {
        // return new PwgError(WS_ERR_INVALID_PARAM, 'Name field must not be empty');
        return [
          'error' => [
            'code' => WS_ERR_INVALID_PARAM,
            'message' => 'Name field must not be empty',
          ],
        ];
    }

    $currentUser = CurrentUser::get();

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    $updates = $updates_infos = [];
    $update_status = null;

    $param_user_id = is_array($params['user_id']) ? $params['user_id'] : [];
    if (count($param_user_id) == 1) {
        if (get_username(is_numeric($param_user_id[0]) ? (int) $param_user_id[0] : 0) === false) {
            // return new PwgError(WS_ERR_INVALID_PARAM, 'This user does not exist.');
            return [
              'error' => [
                'code' => WS_ERR_INVALID_PARAM,
                'message' => 'This user does not exist.',
              ],
            ];
        }

        if (!empty($params['username'])) {
            $user_id = get_userid(is_scalar($params['username']) ? (string) $params['username'] : '');
            if ($user_id and $user_id != $param_user_id[0]) {
                // return new PwgError(WS_ERR_INVALID_PARAM, l10n('this login is already used'));
                return [
                  'error' => [
                    'code' => WS_ERR_INVALID_PARAM,
                    'message' => l10n('this login is already used'),
                  ],
                ];
            }
            $username_str = is_scalar($params['username']) ? (string) $params['username'] : '';
            if ($username_str != strip_tags($username_str)) {
                // return new PwgError(WS_ERR_INVALID_PARAM, l10n('html tags are not allowed in login'));
                return [
                  'error' => [
                    'code' => WS_ERR_INVALID_PARAM,
                    'message' => l10n('html tags are not allowed in login'),
                  ],
                ];
            }
            $updates[ \Piwigo\Config\Config::userFields()['username'] ] = $params['username'];
        }

        if (!empty($params['email'])) {
            if (($error = validate_mail_address(is_numeric($param_user_id[0]) ? (int) $param_user_id[0] : null, is_scalar($params['email']) ? (string) $params['email'] : null)) != '') {
                // return new PwgError(WS_ERR_INVALID_PARAM, $error);
                return [
                  'error' => [
                    'code' => WS_ERR_INVALID_PARAM,
                    'message' => $error,
                  ],
                ];
            }
            $updates[ \Piwigo\Config\Config::userFields()['email'] ] = $params['email'];
        }

        if (!empty($params['password'])) {
            if (!is_webmaster()) {
                $password_protected_users = [\Piwigo\Config\Config::guestId()];

                $query = '
SELECT
    user_id
  FROM '.USER_INFOS_TABLE.'
  WHERE status IN (\'webmaster\', \'admin\')
;';
                $admin_ids = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'user_id');

                // we add all admin+webmaster users BUT the user herself
                $password_protected_users = array_merge($password_protected_users, array_diff(array_map(fn(mixed $v): string => is_scalar($v) ? (string) $v : '0', $admin_ids), [(string) $currentUser->id]));

                if (in_array($param_user_id[0], $password_protected_users)) {
                    // return new PwgError(403, 'Only webmasters can change password of other "webmaster/admin" users');
                    return [
                      'error' => [
                        'code' => 403,
                        'message' => 'Only webmasters can change password of other "webmaster/admin" users',
                      ],
                    ];
                }
            }

            $updates[ \Piwigo\Config\Config::userFields()['password'] ] = password_hash(is_scalar($params['password']) ? (string) $params['password'] : '', PASSWORD_BCRYPT);
        }
    }

    if (!empty($params['status'])) {
        if (in_array($params['status'], ['webmaster', 'admin']) and !is_webmaster()) {
            // return new PwgError(403, 'Only webmasters can grant "webmaster/admin" status');
            return [
              'error' => [
                'code ' => 403,
                'message' => 'Only webmasters can grant "webmaster/admin" status',
              ],
            ];
        }

        if (!in_array($params['status'], ['guest','generic','normal','admin','webmaster'])) {
            // return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid status');
            return [
              'error' => [
                'code' => WS_ERR_INVALID_PARAM,
                'message' => 'Invalid status',
              ],
            ];
        }

        $protected_users = [
          $currentUser->id,
          \Piwigo\Config\Config::guestId(),
          \Piwigo\Config\Config::webmasterId(),
          ];

        // an admin can't change status of other admin/webmaster
        if ('admin' == $currentUser->status) {
            $query = '
SELECT
    user_id
  FROM '.USER_INFOS_TABLE.'
  WHERE status IN (\'webmaster\', \'admin\')
;';
            $protected_users = array_merge($protected_users, array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'user_id')));
        }

        // status update query is separated from the rest as not applying to the same
        // set of users (current, guest and webmaster can't be changed)
        $params['user_id_for_status'] = array_values(array_diff(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $param_user_id), $protected_users));

        $update_status = $params['status'];
    }

    if (!empty($params['level']) or ($params['level'] ?? null) === 0) {
        if (!in_array($params['level'], \Piwigo\Config\Config::availablePermissionLevels())) {
            // return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid level');
            return [
              'error' => [
                'code' => WS_ERR_INVALID_PARAM,
                'message' => 'Invalid level',
              ],
            ];
        }
        $updates_infos['level'] = $params['level'];
    }

    if (!empty($params['language'])) {
        if (!in_array($params['language'], array_keys(get_languages()))) {
            // return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid language');
            return [
              'error' => [
                'code' => WS_ERR_INVALID_PARAM,
                'message' => 'Invalid language',
              ],
            ];
        }
        $updates_infos['language'] = $params['language'];
    }

    if (!empty($params['theme'])) {
        if (!in_array($params['theme'], array_keys(get_pwg_themes()))) {
            // return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid theme');
            return [
              'error' => [
                'code' => WS_ERR_INVALID_PARAM,
                'message' => 'Invalid theme',
              ],
            ];
        }
        $updates_infos['theme'] = $params['theme'];
    }

    if (!empty($params['nb_image_page'])) {
        $updates_infos['nb_image_page'] = $params['nb_image_page'];
    }

    if (!empty($params['recent_period']) or ($params['recent_period'] ?? null) === 0) {
        $updates_infos['recent_period'] = $params['recent_period'];
    }

    if (!empty($params['expand']) or ($params['expand'] ?? null) === false) {
        $v = $params['expand'];
        $updates_infos['expand'] = \Piwigo\Core\BoolUtil::toString(is_bool($v) ? $v : (is_string($v) ? $v : ''));
    }

    if (!empty($params['show_nb_comments']) or ($params['show_nb_comments'] ?? null) === false) {
        $v = $params['show_nb_comments'];
        $updates_infos['show_nb_comments'] = \Piwigo\Core\BoolUtil::toString(is_bool($v) ? $v : (is_string($v) ? $v : ''));
    }

    if (!empty($params['show_nb_hits']) or ($params['show_nb_hits'] ?? null) === false) {
        $v = $params['show_nb_hits'];
        $updates_infos['show_nb_hits'] = \Piwigo\Core\BoolUtil::toString(is_bool($v) ? $v : (is_string($v) ? $v : ''));
    }

    if (!empty($params['enabled_high']) or ($params['enabled_high'] ?? null) === false) {
        $v = $params['enabled_high'];
        $updates_infos['enabled_high'] = \Piwigo\Core\BoolUtil::toString(is_bool($v) ? $v : (is_string($v) ? $v : ''));
    }

    $param_uid_0 = is_numeric($param_user_id[0]) ? (int) $param_user_id[0] : 0;
    $param_group_id = is_array($params['group_id'] ?? null) ? $params['group_id'] : [];

    // perform updates
    single_update(
        USERS_TABLE,
        $updates,
        [\Piwigo\Config\Config::userFields()['id'] => $param_uid_0]
    );

    if (isset($updates[ \Piwigo\Config\Config::userFields()['password'] ])) {
        deactivate_user_auth_keys($param_uid_0);
    }

    if (isset($updates[ \Piwigo\Config\Config::userFields()['email'] ])) {
        deactivate_password_reset_key($param_uid_0);
    }

    $param_user_id_for_status = is_array($params['user_id_for_status'] ?? null) ? $params['user_id_for_status'] : [];
    if (isset($update_status) and count($param_user_id_for_status) > 0) {
        $update_status_str = is_scalar($update_status) ? (string) $update_status : '';
        \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)
            ->updateStatusForUsers(
                $update_status_str,
                array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $param_user_id_for_status)
            );

        // we delete sessions, ie disconnect, for users if status becomes "guest".
        // It's like deactivating the user.
        if ('guest' == $update_status) {
            foreach ($param_user_id_for_status as $user_id_for_status) {
                delete_user_sessions(is_numeric($user_id_for_status) ? (int) $user_id_for_status : 0);
            }
        }
    }

    if (count($updates_infos) > 0) {
        $query = '
UPDATE '. USER_INFOS_TABLE .' SET ';

        $first = true;
        foreach ($updates_infos as $field => $value) {
            if (!$first) {
                $query .= ', ';
            } else {
                $first = false;
            }
            $query .= $field .' = "'. (is_scalar($value) ? (string) $value : '') .'"';
        }

        $query .= '
  WHERE user_id IN('. implode(',', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $param_user_id)) .')';
        \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)->executeStatement($query);
    }

    // manage association to groups
    if (!empty($param_group_id)) {
        \Piwigo\Core\ServiceLocator::get(\Piwigo\Group\GroupRepository::class)
            ->deleteUserGroupByUserIds(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $param_user_id));

        // we remove all provided groups that do not really exist
        $query = '
SELECT
    id
  FROM `'.GROUPS_TABLE.'`
  WHERE id IN ('.implode(',', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $param_group_id)).')
;';
        $group_ids = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');

        // if only -1 (a group id that can't exist) is in the list, then no
        // group is associated

        if (count($group_ids) > 0) {
            $inserts = [];

            foreach ($group_ids as $group_id) {
                foreach ($param_user_id as $user_id) {
                    $inserts[] = ['user_id' => $user_id, 'group_id' => $group_id];
                }
            }

            mass_inserts(USER_GROUP_TABLE, array_keys($inserts[0]), $inserts);
        }
    }

    invalidate_user_cache();

    pwg_activity('user', array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $param_user_id), 'edit');

    return [
      'user_id' => $params['user_id'],
      'infos' => $updates_infos,
      'account' => $updates,
    ];
}

/**
 * Create a new api_key
 *
 * @since 16
 * @param int $user_id
 * @param int|null $duration
 * @param string $key_name
 * @return array auth_key / apikey_secret / apikey_name /
 * user_id / created_on / duration / expired_on / key_type
 */
/** @return array<string,mixed> */
function create_api_key(int $user_id, int $duration, string $key_name): array
{
    $key_id = 'pkid-'.date('Ymd').'-'.generate_key(20);
    $key_secret = generate_key(40);

    $dbnow = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

    $key = [
      'auth_key' => $key_id,
      'apikey_secret' => password_hash($key_secret, PASSWORD_BCRYPT),
      'apikey_name' => $key_name,
      'user_id' => $user_id,
      'created_on' => $dbnow,
      'key_type' => 'api_key',
    ];

    $expiration = null;
    if (!empty($duration)) {
        $expiration = (new \DateTimeImmutable())->modify('+' . ($duration * 86400) . ' seconds')->format('Y-m-d H:i:s');
        $key['duration'] = $duration;
    }
    $key['expired_on'] = $expiration;

    single_insert(USER_AUTH_KEYS_TABLE, $key);

    $key['apikey_secret'] = $key_secret;
    return $key;
}

/**
 * Revoke a api_key
 *
 * @since 16
 * @param int $user_id
 * @return string|bool
 */
function revoke_api_key($user_id, string $pkid)
{
    $authKeyRepo2 = \Piwigo\Core\ServiceLocator::get(\Piwigo\Auth\AuthKeyRepository::class);
    if (!$authKeyRepo2->existsByKeyAndUser($pkid, $user_id)) {
        return l10n('API Key not found');
    }

    single_update(
        USER_AUTH_KEYS_TABLE,
        ['revoked_on' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        [
        'auth_key' => $pkid,
        'user_id' => $user_id,
    ]
    );

    return true;
}

/**
 * Edit a api_key
 *
 * @since 16
 * @param int $user_id
 * @return string|true
 */
function edit_api_key(int $user_id, string $pkid, string $api_name): string|true
{
    if (!\Piwigo\Core\ServiceLocator::get(\Piwigo\Auth\AuthKeyRepository::class)->existsByKeyAndUser($pkid, $user_id)) {
        return l10n('API Key not found');
    }

    single_update(
        USER_AUTH_KEYS_TABLE,
        ['apikey_name' => $api_name],
        [
        'auth_key' => $pkid,
        'user_id' => $user_id,
    ]
    );

    return true;
}

/**
 * Get all api_key
 *
 * @since 16
 * @return array|false
 */
/** @return list<array<mixed>>|false */
function get_api_key(string $user_id): false|array
{
    $query = '
SELECT *
  FROM `'.USER_AUTH_KEYS_TABLE.'`
  WHERE user_id = '.$user_id.'
  AND key_type = "api_key"
;';

    $api_keys = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
    if (!$api_keys) {
        return false;
    }

    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

    foreach ($api_keys as $i => $api_key) {
        $api_key['apikey_secret'] = str_repeat('*', 40);
        unset($api_key['auth_key_id'], $api_key['user_id'], $api_key['key_type']);

        $api_key['apikey_name'] = stripslashes(is_scalar($api_key['apikey_name']) ? (string) $api_key['apikey_name'] : '');

        $ak_created_on = is_scalar($api_key['created_on']) ? (string) $api_key['created_on'] : null;
        $ak_expired_on = is_scalar($api_key['expired_on']) ? (string) $api_key['expired_on'] : null;
        $ak_last_used_on = is_scalar($api_key['last_used_on']) ? (string) $api_key['last_used_on'] : null;
        $ak_revoked_on = is_scalar($api_key['revoked_on']) ? (string) $api_key['revoked_on'] : null;
        $api_key['created_on_format'] = format_date($ak_created_on, ['day', 'month', 'year']);
        $api_key['expired_on_format'] = format_date($ak_expired_on, ['day', 'month', 'year']);
        $api_key['last_used_on_since'] =
          $ak_last_used_on
          ? time_since($ak_last_used_on, 'day')
          : l10n('Never');

        $expired_on = str2DateTime($ak_expired_on);
        $now_str = is_scalar($now) ? (string) $now : null;
        $now = str2DateTime($now_str);

        $api_key['is_expired'] = $expired_on < $now;
        if ($api_key['is_expired']) {
            $api_key['expiration'] = l10n('Expired');
        } elseif ($now !== false && $expired_on !== false) {
            $diff = dateDiff($now, $expired_on);
            if ($diff->days > 0) {
                $api_key['expiration'] = l10n('%d days', $diff->days);
            } elseif ($diff->h > 0) {
                $api_key['expiration'] = l10n('%d hours', $diff->h);
            } else {
                $api_key['expiration'] = l10n('%d minutes', $diff->i);
            }
        }

        $api_key['expired_on_since'] = time_since($ak_expired_on, 'day');

        $api_key['revoked_on_since'] =
          $ak_revoked_on
          ? time_since($ak_revoked_on, 'day')
          : null;

        $api_key['revoked_on_message'] =
          $ak_revoked_on
          ? l10n('This API key was manually revoked on %s', format_date($ak_revoked_on, ['day', 'month', 'year']))
          : null;

        $api_keys[$i] = $api_key;
    }

    return $api_keys;
}

/**
 * Get all available api_key
 *
 * @since 16
 * @return array|false
 */
/** @return array<mixed>|false */
function get_available_api_key(string $user_id): array|false
{
    $api_keys = get_api_key($user_id);

    if (!$api_keys) {
        return false;
    }

    $available = [];
    foreach ($api_keys as $api_key) {
        if (!$api_key['is_expired'] && empty($api_key['revoked_on'])) {
            $available[] = $api_key;
        }
    }

    return count($available) > 0 ? $available : false;
}

/**
 * Is connected with pwg_ui (identification.php)
 *
 * @since 16
 */
function connected_with_pwg_ui(): bool
{
    // You can manage your api key only if you are connected via identification.php
    if (isset($_SESSION['connected_with']) and 'pwg_ui' === $_SESSION['connected_with']) {
        return true;
    }
    return false;
}

/**
 * Notify an user when his api key is about to expire
 *
 * @since 16
 * @return bool
 */
function notification_api_key_expiration(string $username, string $email, int $days_left): bool
{
    include_once(PHPWG_ROOT_PATH . 'include/functions_mail.inc.php');
    $days_left_str = $days_left <= 1 ?
      l10n('Your API key will expire in %d day.', $days_left)
      : l10n('Your API key will expire in %d days.', $days_left);

    $message = '<p style="margin: 20px 0">' . l10n('Hello %s,', $username) . '</p>';
    $message .= '<p style="margin: 20px 0">' . $days_left_str . '</p>';
    $message .= '<p style="margin: 20px 0">' . l10n('To continue using the API, please renew your key before it expires.') . '</p>';
    $message .= '<p style="margin: 20px 0">' . l10n('You can manage your API keys in your <a href="%s">account settings.</a>', get_absolute_root_url().'profile.php') . '</p>';

    $result = pwg_mail(
        $email,
        [
        'subject' => '[' . \Piwigo\Config\Config::galleryTitle() . '] ' . l10n('Your API key will expire soon'),
        'content' => $message,
        'content_format' => 'text/html',
    ]
    );

    return $result;
}

/**
 * Generate an user code for verification
 *
 * @since 16
 * @return array [$secret, $code]
 */
/** @return array<string,mixed> */
function generate_user_code(): array
{
    $secret = PwgTOTP::generateSecret();
    $code = PwgTOTP::generateCode($secret, min(\Piwigo\Config\Config::passwordResetCodeDuration(), 900)); // max 15 minutes

    return [
      'secret' => $secret,
      'code' => $code,
    ];
}

/**
 * Verify user code
 *
 * @since 16
 * @param string $secret
 */
function verify_user_code(string $secret, string $code): bool
{
    return PwgTOTP::verifyCode($code, $secret, min(\Piwigo\Config\Config::passwordResetCodeDuration(), 900), 1);
}

/**
 * Register in the user session, the "context" of the last 10 viewed images.
 *
 * @since 16
 */
function save_edit_context(): void
{
    $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];

    if (!is_admin() or !isset($page['section_url']) or !isset($page['image_id'])) {
        return;
    }

    $_SESSION['edit_context'] ??= [];

    // the $page['section_url'] is set in the include/section_init script. It
    // contains the URL describing the "context" of the photo. Examples:
    //
    // * /198/list/2,69,198
    // * /198/category/18801-yes_man
    // * /198/tags/27-city_nantes/28-city_rennes
    // * /198/search/psk-20251103-lqCHHAFSZY/posted-monthly-list-2025-3
    //
    // same photo #198 in different context. We need it to propose the best
    // return page on the photo edit page in the administration.

    // let's add the item on top of previous registered values and keep only the last 10 values
    $existing_context = is_array($_SESSION['edit_context']) ? $_SESSION['edit_context'] : [];
    $imageId = is_scalar($page['image_id']) ? (string) $page['image_id'] : '';
    $sectionUrl = is_scalar($page['section_url']) ? (string) $page['section_url'] : '';
    $_SESSION['edit_context'] = array_slice([$imageId => $sectionUrl] + $existing_context, 0, 10, true);
}

/**
 * Returns the "context" of the requested image.
 *
 * @since 16
 * @param int $image_id
 * @return string|false|null
 */
function get_edit_context($image_id): false|string|null
{
    $edit_context = is_array($_SESSION['edit_context'] ?? null) ? $_SESSION['edit_context'] : [];
    if (!isset($edit_context[$image_id])) {
        return false;
    }

    return preg_replace('/^\/'.$image_id.'\//', '', is_scalar($edit_context[$image_id]) ? (string) $edit_context[$image_id] : '');
}
