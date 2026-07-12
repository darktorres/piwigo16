<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Auth\PwgTOTP;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Logger;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Ws\PwgError;

/**
 * Checks if an email is well formed and not already in use.
 *
 * @param int|null $user_id null when checking an address with no user yet
 *   (e.g. install.php's admin account, function_user.inc.php's own
 *   register_user())
 * @return string error message, or '' when the address is fine / not
 *   required
 */
function validate_mail_address($user_id, ?string $mail_address)
{
    return new UserService(new UserRepository(DbConnection::build()), new GroupRepository(DbConnection::build()))
        ->validateMailAddress($user_id, $mail_address);
}

/**
 * Checks if a login is not already in use.
 * Comparision is case insensitive.
 *
 * @param string $login
 * @return string error message, or '' when it's free
 */
function validate_login_case($login)
{
    return new UserService(new UserRepository(DbConnection::build()), new GroupRepository(DbConnection::build()))
        ->validateLoginCase($login);
}
/**
 * Searches for user with the same username in different case.
 *
 * @param string $username typically typed in by user for identification
 * @return string found in database
 */
function search_case_username($username)
{
    return new UserService(new UserRepository(DbConnection::build()), new GroupRepository(DbConnection::build()))
        ->searchCaseUsername($username);
}

/**
 * Creates a new user.
 *
 * @param string $login
 * @param string $password
 * @param ?string $mail_address optional: pwg.users.add's own WS contract
 *   defaults this to null when the caller omits it
 * @param bool $notify_admin
 * @param array<int, string> $errors populated with error messages
 * @param bool $notify_user
 * @return int|false user id or false
 */
function register_user($login, #[\SensitiveParameter] $password, ?string $mail_address, $notify_admin = true, &$errors = [], $notify_user = false): int|false
{
    // Preserves the pre-SEC-31 behavior for this free function's own
    // callers (e.g. the admin-authenticated ws.users.add, which legitimately
    // needs the real "already used" message to let an operator pick a
    // different username) -- UserService::registerUser() itself never puts
    // that message in $errors (it would let an attacker enumerate accounts
    // through the public self-registration form); the caller-facing
    // distinction lives here, in the shape of the two return channels, not
    // in the service.
    $result = new UserService(new UserRepository(DbConnection::build()), new GroupRepository(DbConnection::build()))
        ->registerUser($login, $password, $mail_address, $notify_admin, $notify_user);

    $errors = $result['errors'];
    if ($result['duplicateUsername']) {
        array_unshift($errors, l10n('this login is already used'));
    }

    return $result['userId'] ?? false;
}

/**
 * Fetches user data from database.
 * Same that getuserdata() but with additional tests for guest.
 *
 * @param int $user_id
 * @return array<string, mixed>
 */
function build_user($user_id, bool $use_cache = true): array
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $user = [];
    $user['id'] = $user_id;
    $user = array_merge($user, getuserdata($user_id, $use_cache));

    if ($user['id'] == $conf['guest_id'] and $user['status'] != 'guest') {
        $user['status'] = 'guest';
        $internal_status = $user['internal_status'] ?? [];
        if (! is_array($internal_status)) {
            $internal_status = [];
        }
        $internal_status['guest_must_be_guest'] = true;
        $user['internal_status'] = $internal_status;
    }

    // Check user theme. 2 possible problems:
    // 1. the user_infos.theme was not found in the themes table, thus themes.name is null
    // 2. the theme is not really installed on the filesystem
    $theme = $user['theme'] ?? null;
    if (! isset($user['theme_name']) or ! is_string($theme) or ! check_theme_installed($theme)) {
        $user['theme'] = get_default_theme();
        $user['theme_name'] = $user['theme'];
    }

    return $user;
}

/**
 * Finds informations related to the user identifier.
 *
 * @param int $user_id
 * @param bool $use_cache
 * @return array<string, mixed>
 */
function getuserdata($user_id, $use_cache = false)
{
    /**
     * @var array<string, mixed> $conf
     * @var Logger $logger
     */
    global $conf, $logger;

    // see validate_mail_address() for why this is string=>string
    /** @var array<string, string> $user_fields */
    $user_fields = $conf['user_fields'];

    // retrieve basic user data
    $query = '
SELECT ';
    $is_first = true;
    foreach ($user_fields as $pwgfield => $dbfield) {
        if ($is_first) {
            $is_first = false;
        } else {
            $query .= '
     , ';
        }
        $query .= $dbfield . ' AS ' . $pwgfield;
    }
    $query .= '
  FROM ' . Tables::users() . '
  WHERE ' . $user_fields['id'] . ' = \'' . $user_id . '\'';

    $row = pwg_db_fetch_assoc(pwg_query($query));
    if ($row === false || $row === null) {
        throw new Exception('getuserdata(): no such user_id ' . $user_id);
    }

    // retrieve additional user data ?
    if ((bool) $conf['external_authentification']) {
        $query = '
SELECT
    COUNT(1) AS counter
  FROM ' . Tables::userInfos() . ' AS ui
    LEFT JOIN ' . Tables::userCache() . ' AS uc ON ui.user_id = uc.user_id
    LEFT JOIN ' . Tables::themes() . ' AS t ON t.id = ui.theme
  WHERE ui.user_id = ' . $user_id . '
  GROUP BY ui.user_id
;';
        $counter_row = pwg_db_fetch_row(pwg_query($query));
        $counter = $counter_row !== null ? $counter_row[0] : 0;
        if ($counter != 1) {
            create_user_infos($user_id);
        }
    }

    // retrieve user info
    $query = '
SELECT
    ui.*,
    uc.*,
    t.name AS theme_name
  FROM ' . Tables::userInfos() . ' AS ui
    LEFT JOIN ' . Tables::userCache() . ' AS uc ON ui.user_id = uc.user_id
    LEFT JOIN ' . Tables::themes() . ' AS t ON t.id = ui.theme
  WHERE ui.user_id = ' . $user_id . '
;';

    $result = pwg_query($query);
    $user_infos_row = pwg_db_fetch_assoc($result);
    if ($user_infos_row === false || $user_infos_row === null) {
        throw new Exception('getuserdata(): user_infos fetch failed for user_id ' . $user_id);
    }

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

    // Kept out of $userdata: unserialize()'s own return type is native
    // mixed, and merging a mixed value into $userdata here would widen
    // every other key's inferred type to mixed for the remainder of this
    // function. Merged back in just before the final return instead.
    $preferences_raw = $userdata['preferences'];
    $preferences = ! empty($preferences_raw) && is_string($preferences_raw)
        ? unserialize($preferences_raw)
        : [];

    if ($use_cache) {
        $generate_user_cache = false;
        $cache_generation_token_name = 'generate_user_cache-u' . $user_id;
        $exec_code = substr(sha1(random_bytes(1000)), 0, 4);
        $logger_msg_prefix = '[' . __FUNCTION__ . '][exec_code=' . $exec_code . '][user_id=' . $user_id . '] ';

        if (! isset($userdata['need_update'])
            or ! is_bool($userdata['need_update'])
            or $userdata['need_update'] == true) {
            $logger->info($logger_msg_prefix . 'needs user_cache to be rebuilt');

            $exec_id = pwg_unique_exec_begins($cache_generation_token_name);
            if ($exec_id === false) {
                $logger->info($logger_msg_prefix . 'starts to wait for another request to build user_cache');
                $user_cache_waiting_start_time = get_moment();
                for ($k = 0; $k < 20; $k++) {
                    sleep(1);

                    $query = '
SELECT
   COUNT(*)
  FROM ' . Tables::userCache() . '
  WHERE user_id=' . $user_id . '
;';
                    $row = pwg_db_fetch_row(pwg_query($query));
                    assert($row !== null);
                    [$nb_cache_lines] = $row;

                    $logger_msg = $logger_msg_prefix . 'user_cache generation waiting k=' . $k . ' ';
                    $waiting_time = get_elapsed_time($user_cache_waiting_start_time, get_moment());

                    if ($nb_cache_lines > 0) {
                        $logger->info($logger_msg . 'user_cache rebuilt, after waiting ' . $waiting_time);
                        return getuserdata($user_id, false);
                    }
                    if (! pwg_unique_exec_is_running($cache_generation_token_name)) {
                        $logger->info($logger_msg . 'user_cache rebuilt but has been reset since, give it another try, after waiting ' . $waiting_time);
                        return getuserdata($user_id, true);
                    } else {
                        $logger->info($logger_msg . 'user_cache not ready yet, after waiting ' . $waiting_time);
                    }
                }

                $logger->info($logger_msg_prefix . 'user_cache generation waiting has timed out after ' . get_elapsed_time($user_cache_waiting_start_time, get_moment()));
                set_status_header(503, 'Service Unavailable');
                @header('Retry-After: 900');
                header('Content-Type: text/html; charset=' . get_pwg_charset());
                echo l10n('Rebuilding user cache takes long. Please, come back later.');
                echo str_repeat(' ', 512); // IE6 doesn't error output if below a size
                exit();
            } else {
                $generate_user_cache = true;
            }
        }

        if ($generate_user_cache) {
            $user_cache_generation_start_time = get_moment();
            $cache_update_time = time();
            $userdata['cache_update_time'] = $cache_update_time;

            // Set need update are done
            $need_update = false;
            $userdata['need_update'] = $need_update;

            $status = $userdata['status'];
            assert(is_string($status));

            $forbidden_categories = calculate_permissions($user_id, $status);
            $userdata['forbidden_categories'] = $forbidden_categories;

            $level = $userdata['level'] ?? '0';
            assert(is_string($level));

            /* now we build the list of forbidden images (this list does not contain
            images that are not in at least an authorized category)*/
            $query = '
SELECT DISTINCT(id)
  FROM ' . Tables::images() . ' INNER JOIN ' . Tables::imageCategory() . ' ON id=image_id
  WHERE category_id NOT IN (' . $forbidden_categories . ')
    AND level>' . $level;
            $forbidden_ids = query2array($query, null, 'id');

            if (empty($forbidden_ids)) {
                $forbidden_ids[] = 0;
            }
            $image_access_type = 'NOT IN'; // TODO maybe later
            $userdata['image_access_type'] = $image_access_type;
            $image_access_list = implode(',', $forbidden_ids);
            $userdata['image_access_list'] = $image_access_list;

            $query = '
SELECT COUNT(DISTINCT(image_id)) as total
  FROM ' . Tables::imageCategory() . '
  WHERE category_id NOT IN (' . $forbidden_categories . ')
    AND image_id ' . $image_access_type . ' (' . $image_access_list . ')';
            $row = pwg_db_fetch_row(pwg_query($query));
            assert($row !== null);
            [$nb_total_images] = $row;
            assert($nb_total_images !== null);
            $userdata['nb_total_images'] = $nb_total_images;

            // now we update user cache categories
            // get_computed_categories() takes $userdata by reference and is
            // declared with a generic array<string, mixed> shape, so
            // PHPStan can no longer track any of $userdata's per-key types
            // after this call -- every subsequent read below goes through
            // a freshly-narrowed local variable instead of re-reading
            // $userdata.
            $user_cache_cats = get_computed_categories($userdata, null);
            if (! is_admin($status)) { // for non admins we forbid categories with no image (feature 1053)
                $forbidden_ids = [];
                foreach ($user_cache_cats as $cat) {
                    if ($cat['count_images'] == 0) {
                        $cat_id = $cat['cat_id'];
                        assert(is_string($cat_id));
                        $forbidden_ids[] = $cat_id;
                        remove_computed_category($user_cache_cats, $cat);
                    }
                }
                if (! empty($forbidden_ids)) {
                    if (empty($forbidden_categories)) {
                        $forbidden_categories = implode(',', $forbidden_ids);
                    } else {
                        $forbidden_categories .= ',' . implode(',', $forbidden_ids);
                    }
                    $userdata['forbidden_categories'] = $forbidden_categories;
                }
            }

            $last_photo_date = $userdata['last_photo_date'];
            assert($last_photo_date === null || is_string($last_photo_date));

            // delete user cache
            $query = '
DELETE FROM ' . Tables::userCacheCategories() . '
  WHERE user_id = ' . $user_id;
            pwg_query($query);

            // Due to concurrency issues, we ask MySQL to ignore errors on
            // insert. This may happen when cache needs refresh and that Piwigo is
            // called "very simultaneously".
            mass_inserts(
                Tables::userCacheCategories(),
                [
                    'user_id', 'cat_id',
                    'date_last', 'max_date_last', 'nb_images', 'count_images', 'nb_categories', 'count_categories',
                ],
                // mass_inserts() only reads values (row shape/data), never
                // this array's own keys — get_computed_categories() keys by
                // cat_id (int|string, a raw DB fetch value) for the
                // remove_computed_category() lookups above, not relevant here
                array_values($user_cache_cats),
                [
                    'ignore' => true,
                ]
            );

            // update user cache
            $query = '
DELETE FROM ' . Tables::userCache() . '
  WHERE user_id = ' . $user_id;
            pwg_query($query);

            // boolean_to_string() only returns non-string when its input
            // isn't a bool (@return mixed in
            // dblayer/functions_mysqli.inc.php); $need_update is always a
            // real bool here, so the result is guaranteed to be a string.
            $need_update_str = boolean_to_string($need_update);
            assert(is_string($need_update_str));

            // for the same reason as user_cache_categories, we ignore error on
            // this insert
            $query = '
INSERT IGNORE INTO ' . Tables::userCache() . '
  (user_id, need_update, cache_update_time, forbidden_categories, nb_total_images,
    last_photo_date,
    image_access_type, image_access_list)
  VALUES
  (' . $user_id . ',\'' . $need_update_str . '\','
  . $cache_update_time . ',\''
  . $forbidden_categories . '\',' . $nb_total_images . ',' .
  (empty($last_photo_date) ? 'NULL' : '\'' . $last_photo_date . '\'') .
  ',\'' . $image_access_type . '\',\'' . $image_access_list . '\')';
            pwg_query($query);

            pwg_unique_exec_ends($cache_generation_token_name);
            $logger->info($logger_msg_prefix . 'user_cache generated, executed in ' . get_elapsed_time($user_cache_generation_start_time, get_moment()));
        }
    }

    $userdata['preferences'] = $preferences;

    return $userdata;
}

/**
 * Deletes favorites of the current user if he's not allowed to see them.
 */
function check_user_favorites(): void
{
    /** @var array<string, mixed> $user */
    global $user;

    if ($user['forbidden_categories'] == '') {
        return;
    }

    // user_infos.id (primary key, NOT NULL): a raw DB fetch value is a
    // numeric string, build_user() may also set it as int -- either way
    // it's always scalar and safe to interpolate into SQL below.
    $user_id_val = $user['id'];
    $user_id_str = is_scalar($user_id_val) ? (string) $user_id_val : '0';

    // $filter['visible_categories'] and $filter['visible_images']
    // must be not used because filter <> restriction
    // retrieving images allowed : belonging to at least one authorized
    // category
    $query = '
SELECT DISTINCT f.image_id
  FROM ' . Tables::favorites() . ' AS f INNER JOIN ' . Tables::imageCategory() . ' AS ic
    ON f.image_id = ic.image_id
  WHERE f.user_id = ' . $user_id_str . '
  ' . get_sql_condition_FandF(
        [
            'forbidden_categories' => 'ic.category_id',
        ],
        'AND'
    ) . '
;';
    $authorizeds = query2array($query, null, 'image_id');

    $query = '
SELECT image_id
  FROM ' . Tables::favorites() . '
  WHERE user_id = ' . $user_id_str . '
;';
    $favorites = query2array($query, null, 'image_id');

    $to_deletes = array_diff($favorites, $authorizeds);
    if (count($to_deletes) > 0) {
        $query = '
DELETE FROM ' . Tables::favorites() . '
  WHERE image_id IN (' . implode(',', $to_deletes) . ')
    AND user_id = ' . $user_id_str . '
;';
        pwg_query($query);
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
    return new PermissionService(
        new PermissionRepository(DbConnection::build()),
        new GroupRepository(DbConnection::build())
    )->getForbiddenCategories($user_id, $user_status);
}

/**
 * Returns user identifier thanks to his name.
 *
 * @param string $username
 */
function get_userid($username): false|int
{
    return new UserService(new UserRepository(DbConnection::build()), new GroupRepository(DbConnection::build()))
        ->getUserId($username);
}

/**
 * Returns user identifier thanks to his email.
 *
 * @param string $email
 */
function get_userid_by_email($email): false|int
{
    return new UserService(new UserRepository(DbConnection::build()), new GroupRepository(DbConnection::build()))
        ->getUserIdByEmail($email);
}

/**
 * Returns a array with default user valuees.
 *
 * @param bool $convert_str ceonferts 'true' and 'false' into booleans
 * @return array<string, mixed>|false false if the default user row doesn't exist
 */
function get_default_user_info(bool $convert_str = true)
{
    return new UserService(new UserRepository(DbConnection::build()), new GroupRepository(DbConnection::build()))
        ->getDefaultUserInfo($convert_str);
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
    return new UserService(new UserRepository(DbConnection::build()), new GroupRepository(DbConnection::build()))
        ->getDefaultUserValue($value_name, $default);
}

/**
 * Returns the default theme.
 * If the default theme is not available it returns the first available one.
 */
function get_default_theme(): string
{
    $theme = get_default_user_value('theme', AppInfo::DEFAULT_TEMPLATE);
    if (! is_string($theme)) {
        $theme = AppInfo::DEFAULT_TEMPLATE;
    }
    if (check_theme_installed($theme)) {
        return $theme;
    }

    // let's find the first available theme
    $active_themes = array_keys(get_pwg_themes());
    return isset($active_themes[0]) ? (string) $active_themes[0] : 'default';
}

/**
 * Returns the default language.
 */
function get_default_language(): string
{
    $language = get_default_user_value('language', AppInfo::DEFAULT_LANGUAGE);
    return is_string($language) ? $language : AppInfo::DEFAULT_LANGUAGE;
}

/**
 * Tries to find the browser language among available languages.
 *
 * @return string
 */
function get_browser_language(): false|int|string
{
    $language_header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
    if (! is_string($language_header) || $language_header === '') {
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
    if (! (bool) count($accept_languages_full)) {
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
        if (array_key_exists($accept_languages_short[$i], $languages_available)) {
            return $languages_available[$accept_languages_short[$i]];
        }
    }

    return false;
}

/**
 * Creates user informations based on default values.
 *
 * @param int|int[] $user_ids
 * @param array<string, mixed>|null $override_values values used to override default user values
 */
function create_user_infos($user_ids, $override_values = null): void
{
    $userIdList = is_array($user_ids) ? $user_ids : [$user_ids];

    new UserService(new UserRepository(DbConnection::build()), new GroupRepository(DbConnection::build()))
        ->createUserInfos($userIdList, $override_values);
}

/**
 * Returns the auto login key for an user or false if the user is not found.
 *
 * @param int|numeric-string $user_id auto_login() passes the raw
 *   remember-me cookie's exploded, is_numeric()-checked string parts
 * @param int|numeric-string $time ditto — only ever used in string
 *   concatenation (the HMAC input) or SQL interpolation, never arithmetic
 * @param string $username fille with corresponding username
 */
function calculate_auto_login_key($user_id, $time, &$username): string|false
{
    $result = new AuthService(new AuthRepository(DbConnection::build()))
        ->calculateAutoLoginKey($user_id, $time);
    $username = $result['username'];

    return $result['key'];
}

/**
 * Performs all required actions for user login.
 *
 * @param int|numeric-string|false $user_id auto_login() passes a raw
 *   remember-me cookie's numeric-string user id; register.php passes
 *   get_userid()'s result directly, which is typed int|false (false is not
 *   reachable there in practice since it looks up the user just created by
 *   the immediately-preceding successful register_user() call)
 * @param bool $remember_me
 */
function log_user($user_id, $remember_me): void
{
    new AuthService(new AuthRepository(DbConnection::build()))
        ->logUser($user_id, $remember_me);
}

/**
 * Performs auto-connection when cookie remember_me exists.
 */
function auto_login(): bool
{
    return new AuthService(new AuthRepository(DbConnection::build()))
        ->autoLogin();
}

/**
 * Verifies a password against a legacy phpass ($P$/$H$-prefixed) hash.
 * Minimal extraction of the vendored phpass library's core check algorithm
 * (include/passwordhash.class.php, removed in this commit) -- kept only
 * long enough to rehash existing installs' passwords forward to bcrypt in
 * pwg_password_verify(); never used to generate new hashes.
 * @see http://www.openwall.com/phpass/ (public domain, Solar Designer)
 *
 * @param string $password plain text
 * @param string $hash phpass $P$/$H$-prefixed hash
 * @return bool
 */
function pwg_phpass_verify_legacy(
    #[\SensitiveParameter]
    $password,
    #[\SensitiveParameter]
    $hash
) {
    return new PasswordService(new PasswordRepository(DbConnection::build()))
        ->verifyLegacyPhpass($password, $hash);
}

/**
 * Hashes a password with native password_hash() (bcrypt).
 * @since 17
 *
 * @param string $password plain text
 */
function pwg_password_hash(
    #[\SensitiveParameter]
    $password
): string {
    return new PasswordService(new PasswordRepository(DbConnection::build()))
        ->hash($password);
}

/**
 * Verifies a password against a stored hash using native password_verify()
 * (bcrypt). Also accepts a legacy phpass ($P$/$H$-prefixed) hash produced
 * by this codebase's own pre-P5 pwg_password_hash(), rehashing it to
 * bcrypt on successful verify -- the same forward-migration pattern
 * SEC-41/P28 reuses later for bcrypt->Argon2id. The old MD5/
 * $conf['pass_convert'] tier (bridging from *upstream* Piwigo's own
 * pre-2.5 format) is removed outright: this fork has no in-place upgrade
 * from upstream (docs/adr/0002-clean-fork-no-inplace-upgrade.md) -- that
 * bridging is the one-shot import:legacy tool's job (P15/P23), not a live
 * runtime path here.
 * @since 17
 *
 * @param string $password plain text
 * @param string $hash bcrypt or legacy phpass hash
 * @param int $user_id only useful to update the hash format in database
 * @return bool
 */
function pwg_password_verify(
    #[\SensitiveParameter]
    $password,
    #[\SensitiveParameter]
    $hash,
    $user_id = null
) {
    return new PasswordService(new PasswordRepository(DbConnection::build()))
        ->verify($password, $hash, $user_id);
}

/**
 * Tries to login a user given username and password (must be MySql escaped).
 *
 * @param string $username
 * @param string|null $password both real callers (identification.php's
 *   $_POST, ws_session_login()'s optional WS param) can genuinely pass
 *   null when the field is omitted
 * @param bool $remember_me
 */
function try_log_user($username, $password, $remember_me): bool
{
    return new AuthService(new AuthRepository(DbConnection::build()))
        ->tryLogUser($username, $password, $remember_me);
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
function pwg_login(bool $success, $username, $password, $remember_me): bool
{
    if ($success === true) {
        return true;
    }

    // we force the session table to be clean
    pwg_session_gc();

    global $conf;

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
    assert(is_string($hash));
    $verify_user_id = $user_found['id'] ?? $fake_user['id'];
    if ($verify_user_id !== null) {
        assert(is_numeric($verify_user_id));
        $verify_user_id = (int) $verify_user_id;
    }
    $password_verify = pwg_password_verify(
        $password,
        $hash,
        $verify_user_id
    );

    // If the user was not found, is a guest, or the password is incorrect
    if (empty($user_found) || $user_found['status'] === 'guest' || ! $password_verify) {
        if (! empty($user_found) && ! $password_verify) {
            $found_user_id = $user_found['id'];
            assert(is_string($found_user_id));
            pwg_activity('user', $found_user_id, 'login_failure_wrong_password');
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
    $state = [
        'can_login' => true,
        'reason' => null,
        'authenticated' => false,
    ];
    $state = trigger_change('finalize_login', $state, $user_found, $remember_me);

    // trigger_change()'s own return type is mixed; `&&` always yields a
    // real bool regardless of operand types, which is what narrows
    // $can_login/$authenticated below without needing an assert().
    $can_login = is_array($state) && (bool) ($state['can_login'] ?? null);
    $reason = is_array($state) ? ($state['reason'] ?? null) : null;
    $authenticated = is_array($state) && (bool) ($state['authenticated'] ?? null);

    if (! $can_login) {
        $found_user_id = $user_found['id'];
        assert(is_string($found_user_id));
        pwg_activity('user', $found_user_id, is_string($reason) ? $reason : 'login_failure_before_log_user');
        trigger_notify('login_failure_before_log_user', stripslashes($username));
        return false;
    }

    // If plugin handled authentication, skip log_user()
    if (! $authenticated) {
        $found_user_id = $user_found['id'];
        assert(is_string($found_user_id) && is_numeric($found_user_id));
        log_user($found_user_id, $remember_me);
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
 * @return array<string, mixed>|null
 */
function find_user_by_username_or_email($username_or_email): ?array
{
    /** @var array<string, mixed> $conf */
    global $conf;

    // see validate_mail_address() for why this is string=>string
    /** @var array<string, string> $user_fields */
    $user_fields = $conf['user_fields'];

    $username_or_email = pwg_db_real_escape_string($username_or_email);

    $query = '
SELECT
  ' . $user_fields['id'] . ' AS id,
  ' . $user_fields['username'] . ' AS username,
  ' . $user_fields['email'] . ' AS email,
  ' . $user_fields['password'] . ' AS password,
  status
FROM ' . Tables::users() . ' AS u
  LEFT JOIN ' . Tables::userInfos() . ' AS i
    ON u.' . $user_fields['id'] . ' = i.user_id
  WHERE ';

    $where_username = $user_fields['username'] . ' = \'' . $username_or_email . '\'';
    $where_email = $user_fields['email'] . ' = \'' . $username_or_email . '\'';

    $user_by_username = pwg_db_fetch_assoc(pwg_query($query . $where_username));
    $user = (bool) $user_by_username ? $user_by_username : pwg_db_fetch_assoc(pwg_query($query . $where_email));

    if (! empty($user)) {
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
 * @return array{id: null, password: string} id and password
 */
function generate_fake_user(): array
{
    // Generate once per session to avoid repeated hashing overhead.
    // Uses current password_hash algorithm to match real user verification costs.
    if (! isset($_SESSION['fake_user_cache'])) {
        $fake_password = bin2hex(random_bytes(10));
        $_SESSION['fake_user_cache'] = [
            'id' => null,
            'password' => pwg_password_hash($fake_password),
        ];
    }

    $fake_user = $_SESSION['fake_user_cache'];
    assert(is_array($fake_user));
    assert(array_key_exists('id', $fake_user) && $fake_user['id'] === null);
    assert(isset($fake_user['password']) && is_string($fake_user['password']));

    return [
        'id' => null,
        'password' => $fake_user['password'],
    ];
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
    new AuthService(new AuthRepository(DbConnection::build()))
        ->logoutUser();
}

/**
 * Return user status.
 *
 * @param string $user_status used if $user not initialized
 * @return string
 */
function get_user_status($user_status = '')
{
    /** @var array<string, mixed> $user */
    global $user;

    if (empty($user_status)) {
        if (isset($user['status']) and is_string($user['status'])) {
            $user_status = $user['status'];
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
    /** @var array<string, mixed> $conf */
    global $conf;

    $access_type_status = match (get_user_status($user_status)) {
        'guest' => (bool) $conf['guest_access'] ? AccessLevel::Guest : AccessLevel::Free,
        'generic' => AccessLevel::Guest,
        'normal' => AccessLevel::Classic,
        'admin' => AccessLevel::Administrator,
        'webmaster' => AccessLevel::Webmaster,
        default => AccessLevel::Free,
    };

    return $access_type_status;
}

/**
 * Returns if user has access to a particular ACCESS_*
 *
 * @param string $user_status used if $user not initialized
 */
function is_autorize_status(int $access_type, $user_status = ''): bool
{
    return get_access_type_status($user_status) >= $access_type;
}

/**
 * Abord script if user has no access to a particular ACCESS_*
 *
 * @param string $user_status used if $user not initialized
 */
function check_status(int $access_type, $user_status = ''): void
{
    if (! is_autorize_status($access_type, $user_status)) {
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
    return is_autorize_status(AccessLevel::Classic, $user_status);
}

/**
 * Returns if user is, at least, an administrator.
 *
 * @param string $user_status used if $user not initialized
 */
function is_admin($user_status = ''): bool
{
    return is_autorize_status(AccessLevel::Administrator, $user_status);
}

/**
 * Returns if user is a webmaster.
 *
 * @param string $user_status used if $user not initialized
 */
function is_webmaster($user_status = ''): bool
{
    return is_autorize_status(AccessLevel::Webmaster, $user_status);
}

/**
 * Returns if current user can edit/delete/validate a comment.
 *
 * @param string $action edit/delete/validate
 * @param int $comment_author_id
 */
function can_manage_comment($action, $comment_author_id): bool
{
    /**
     * @var array<string, mixed> $user
     * @var array<string, mixed> $conf
     */
    global $user, $conf;

    if (is_a_guest()) {
        return false;
    }

    if (! in_array($action, ['delete', 'edit', 'validate'])) {
        return false;
    }

    if (is_admin()) {
        return true;
    }

    if ($action == 'edit' and (bool) $conf['user_can_edit_comment']) {
        if ($comment_author_id == $user['id']) {
            return true;
        }
    }

    if ($action == 'delete' and (bool) $conf['user_can_delete_comment']) {
        if ($comment_author_id == $user['id']) {
            return true;
        }
    }

    return false;
}

/**
 * Compute sql WHERE condition with restrict and filter data.
 * "FandF" means Forbidden and Filters.
 *
 * @param array<string, string> $condition_fields one witch fields apply each filter
 *    - forbidden_categories
 *    - visible_categories
 *    - forbidden_images
 *    - visible_images
 * @param string $prefix_condition prefixes query if condition is not empty
 * @param bool $force_one_condition use at least "1 = 1"
 */
function get_sql_condition_FandF(
    $condition_fields,
    $prefix_condition = null,
    $force_one_condition = false
): string {
    return new PermissionService(new PermissionRepository(DbConnection::build()), new GroupRepository(DbConnection::build()))
        ->getSqlConditionFandF($condition_fields, $prefix_condition, $force_one_condition);
}

/**
 * Returns sql WHERE condition for recent photos/albums for current user.
 *
 * @param string $db_field
 */
function get_recent_photos_sql($db_field): string
{
    /** @var array<string, mixed> $user */
    global $user;
    if (! isset($user['last_photo_date'])) {
        return '0=1';
    }

    // same narrowing as get_icon()'s $recent_period handling in
    // functions.inc.php: a raw user_infos DB value, numeric string or int
    $recent_period = $user['recent_period'] ?? null;
    $recent_period = is_numeric($recent_period) ? (int) $recent_period : (is_string($recent_period) ? $recent_period : 0);

    $last_photo_date = $user['last_photo_date'];
    $last_photo_date = is_string($last_photo_date) ? $last_photo_date : '';

    return $db_field . '>=LEAST('
      . pwg_db_get_recent_period_expression($recent_period)
      . ',' . pwg_db_get_recent_period_expression(1, $last_photo_date) . ')';
}

/**
 * Performs auto-connection if authentication key is valid.
 *
 * @since 2.8
 * @param mixed $auth_key raw, unvalidated request input ($_GET['auth'], an
 *   Authorization header value, or a ws param) — normalized to '' when not
 *   already a string (a malicious/malformed request can hand this an
 *   array), which safely fails every format check below
 */
function auth_key_login($auth_key, bool $connection_by_header = false): bool
{
    /**
     * @var array<string, mixed> $conf
     * @var array<string, mixed> $user
     * @var array<string, mixed> $page
     */
    global $conf, $user, $page;

    // see validate_mail_address() for why this is string=>string
    /** @var array<string, string> $user_fields */
    $user_fields = $conf['user_fields'];

    $auth_key = is_string($auth_key) ? $auth_key : '';

    $valid_key = false;
    $secret_key = null;
    if ((bool) preg_match('/^[a-z0-9]{30}$/i', $auth_key)) {
        $valid_key = 'auth_key';
    } elseif ((bool) preg_match('/^pkid-\d{8}-[a-z0-9]{20}:[a-z0-9]{40}$/i', $auth_key)) {
        $valid_key = 'api_key';
        $tmp_key = explode(':', $auth_key);
        $auth_key = $tmp_key[0];
        $secret_key = $tmp_key[1];
    }

    if (! (bool) $valid_key) {
        return false;
    }

    $query = '
SELECT
    *,
    ' . $user_fields['username'] . ' AS username,
    ' . $user_fields['email'] . ' AS email,
    NOW() AS dbnow,
    DATEDIFF(uak.expired_on, NOW()) AS days_left,
    SUBDATE(NOW(), INTERVAL 48 HOUR) AS 48h_ago
  FROM ' . Tables::userAuthKeys() . ' AS uak
    JOIN ' . Tables::userInfos() . ' AS ui ON uak.user_id = ui.user_id
    JOIN ' . Tables::users() . ' AS u ON u.' . $user_fields['id'] . ' = ui.user_id
  WHERE auth_key = \'' . $auth_key . '\'
;';
    $keys = query2array($query);

    if (count($keys) == 0) {
        return false;
    }

    $key = $keys[0];

    // is the key still valid?
    if (strtotime((string) $key['expired_on']) < strtotime((string) $key['dbnow'])) {
        $page['auth_key_invalid'] = true;
        return false;
    }

    // admin/webmaster/guest can't get connected with authentication keys
    if ($valid_key === 'auth_key' and ! in_array($key['status'], ['normal', 'generic'])) {
        return false;
    }

    // the key is an api_key
    if ($valid_key === 'api_key') {
        // check secret
        $apikey_secret = $key['apikey_secret'];
        if (! is_string($apikey_secret) || ! pwg_password_verify($secret_key, $apikey_secret)) {
            return false;
        }

        // is the key is revoked?
        if ($key['revoked_on'] != null) {
            return false;
        }

        // check if we need to notificate the user
        $days_left = intval($key['days_left']);
        if (
            $days_left <= 7 // the key expire in max 7 days
            and ! empty($key['email']) // the user have an email
            and (
                $key['last_notified_on'] === null // we never send an email for this key
                or strtotime($key['last_notified_on']) < strtotime((string) $key['48h_ago']) // OR when the last email was sent more than 48 hours ago
            )
        ) {
            $page['notify_api_key_expiration'] = [
                'days_left' => $days_left,
                'dbnow' => $key['dbnow'],
                'auth_key' => $key['auth_key'],
            ];
        }
    }

    $key_user_id = $key['user_id'];
    assert(is_numeric($key_user_id));
    $user['id'] = $key_user_id;

    // update last used key
    single_update(
        Tables::userAuthKeys(),
        [
            'last_used_on' => $key['dbnow'],
        ],
        [
            'user_id' => $key_user_id,
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

    log_user($key_user_id, false);
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
 * @return array<string, mixed>|false false if auth keys are disabled or the user status is ineligible
 */
function create_user_auth_key($user_id, ?string $user_status = null)
{
    /** @var array<string, mixed> $conf */
    global $conf;

    // auth_key_duration defaults to 3*24*60*60 (int) in
    // include/config_default.inc.php, but once persisted to the config DB
    // table it comes back as a raw string (see load_conf_from_db())
    $auth_key_duration = $conf['auth_key_duration'];
    $auth_key_duration = is_numeric($auth_key_duration) ? (int) $auth_key_duration : 0;

    if ($auth_key_duration == 0) {
        return false;
    }

    if (! isset($user_status)) {
        // we have to find the user status
        $query = '
SELECT
    status
  FROM ' . Tables::userInfos() . '
  WHERE user_id = ' . $user_id . '
;';
        $user_infos = query2array($query);

        if (count($user_infos) == 0) {
            return false;
        }

        $user_status = $user_infos[0]['status'];
    }

    if (! in_array($user_status, ['normal', 'generic'])) {
        return false;
    }

    $candidate = generate_key(30);

    $query = '
SELECT
    COUNT(*),
    NOW(),
    ADDDATE(NOW(), INTERVAL ' . $auth_key_duration . ' SECOND)
  FROM ' . Tables::userAuthKeys() . '
  WHERE auth_key = \'' . $candidate . '\'
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$counter, $now, $expiration] = $row;
    if ($counter == 0) {
        $key = [
            'auth_key' => $candidate,
            'user_id' => $user_id,
            'created_on' => $now,
            'duration' => $auth_key_duration,
            'expired_on' => $expiration,
            'key_type' => 'auth_key',
        ];

        single_insert(Tables::userAuthKeys(), $key);

        $key['auth_key_id'] = pwg_db_insert_id();

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
    $query = '
UPDATE ' . Tables::userAuthKeys() . '
  SET expired_on = NOW()
  WHERE user_id = ' . $user_id . '
    AND expired_on > NOW()
    AND key_type = \'auth_key\'
;';
    pwg_query($query);
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
        Tables::userInfos(),
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
 * Generate reset password link
 *
 * @since 15
 * @param int $user_id
 * @param bool $first_login
 * @return array{time_validation: string, password_link: string}
 */
function generate_password_link($user_id, $first_login = false): array
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $activation_key = generate_key(20);

    // password_activation_duration/password_reset_duration default to ints
    // in include/config_default.inc.php, but once persisted to the config
    // DB table they come back as raw strings (see load_conf_from_db())
    $duration = $first_login
    ? $conf['password_activation_duration']
    : $conf['password_reset_duration'];
    $duration = is_numeric($duration) ? (int) $duration : 0;
    $row = pwg_db_fetch_row(pwg_query('SELECT ADDDATE(NOW(), INTERVAL ' . $duration . ' SECOND)'));
    assert($row !== null);
    [$expire] = $row;

    single_update(
        Tables::userInfos(),
        [
            'activation_key' => pwg_password_hash($activation_key),
            'activation_key_expire' => $expire,
        ],
        [
            'user_id' => $user_id,
        ]
    );

    set_make_full_url();

    $password_link = get_root_url() . 'password.php?key=' . $activation_key;

    unset_make_full_url();

    $validation_timestamp = strtotime('now -' . $duration . ' second');
    if ($validation_timestamp === false) {
        throw new Exception('strtotime() failed for duration ' . $duration);
    }
    $time_validation = time_since(
        $validation_timestamp,
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
 * @param bool $save_in_user_infos to store result in user_infos.last_visit
 * @return string date & time of last visit
 */
function get_user_last_visit_from_history($user_id, $save_in_user_infos = false): ?string
{
    $last_visit = null;

    $query = '
SELECT
    date,
    time
FROM ' . Tables::history() . '
  WHERE user_id = ' . $user_id . '
  ORDER BY id DESC
  LIMIT 1
;';
    $result = pwg_query($query);
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $last_visit = $row['date'] . ' ' . $row['time'];
    }

    if ($save_in_user_infos) {
        $query = '
UPDATE ' . Tables::userInfos() . '
  SET last_visit = ' . ($last_visit === null ? 'NULL' : "'" . $last_visit . "'") . ',
      last_visit_from_history = \'true\',
      lastmodified = lastmodified
  WHERE user_id = ' . $user_id . '
';
        pwg_query($query);
    }

    return $last_visit;
}

/**
 * Save user preferences in database
 * @since 13
 */
function userprefs_save(): void
{
    new PreferencesService(new UserRepository(DbConnection::build()))
        ->save();
}

/**
 * Add or update a user preferences parameter
 * @since 13
 *
 * @param string $param
 * @param mixed $value userprefs_save() serialize()s the whole preferences
 *   array, so this isn't limited to strings — real callers pass bool
 *   (admin.php), int (password.php's timestamp), and array
 *   (functions_search.inc.php's filter list) too
 */
function userprefs_update_param($param, $value): void
{
    new PreferencesService(new UserRepository(DbConnection::build()))
        ->updateParam($param, $value);
}

/**
 * Delete one or more user preferences parameters
 * @since 13
 *
 * @param string|string[] $params
 */
function userprefs_delete_param($params): void
{
    new PreferencesService(new UserRepository(DbConnection::build()))
        ->deleteParam($params);
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
    return new PreferencesService(new UserRepository(DbConnection::build()))
        ->getParam($param, $default_value);
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
    $query = '
SELECT COUNT(*)
  FROM ' . Tables::activity() . '
  WHERE action = \'login\' and performed_by = ' . $user_id . '';

    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$logged_in] = $row;
    if ($logged_in > 0) {
        return false;
    }
    return true;
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
    if (isset($params['username'])) {
        $username_check = $params['username'];
        assert(is_string($username_check));
        if (strlen(str_replace(' ', '', $username_check)) == 0) {
            // return new PwgError(WS_ERR_INVALID_PARAM, 'Name field must not be empty');
            return [
                'error' => [
                    'code' => WS_ERR_INVALID_PARAM,
                    'message' => 'Name field must not be empty',
                ],
            ];
        }
    }

    /**
     * @var array<string, mixed> $conf
     * @var array<string, mixed> $user
     */
    global $conf, $user, $service;

    // see validate_mail_address() for why this is string=>string
    /** @var array<string, string> $user_fields */
    $user_fields = $conf['user_fields'];

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    $updates = $updates_infos = [];
    $update_status = null;
    $user_ids_for_status = [];

    // real callers (ws_users_setInfo/ws_users_setPreferences) always pass
    // 'user_id' as a list of ints (WS_TYPE_ID-coerced) or numeric strings
    // (the global $user['id'] raw DB value); normalize once here so every
    // usage below is a well-typed int.
    assert(is_array($params['user_id']));
    $user_ids = [];
    foreach ($params['user_id'] as $raw_user_id) {
        assert(is_int($raw_user_id) || (is_string($raw_user_id) && is_numeric($raw_user_id)));
        $user_ids[] = (int) $raw_user_id;
    }

    if (count($user_ids) == 1) {
        if (get_username($user_ids[0]) === false) {
            // return new PwgError(WS_ERR_INVALID_PARAM, 'This user does not exist.');
            return [
                'error' => [
                    'code' => WS_ERR_INVALID_PARAM,
                    'message' => 'This user does not exist.',
                ],
            ];
        }

        if (! empty($params['username'])) {
            $username_param = $params['username'];
            assert(is_string($username_param));
            $user_id = get_userid($username_param);
            if ((bool) $user_id and $user_id != $user_ids[0]) {
                // return new PwgError(WS_ERR_INVALID_PARAM, l10n('this login is already used'));
                return [
                    'error' => [
                        'code' => WS_ERR_INVALID_PARAM,
                        'message' => l10n('this login is already used'),
                    ],
                ];
            }
            if ($username_param != strip_tags($username_param)) {
                // return new PwgError(WS_ERR_INVALID_PARAM, l10n('html tags are not allowed in login'));
                return [
                    'error' => [
                        'code' => WS_ERR_INVALID_PARAM,
                        'message' => l10n('html tags are not allowed in login'),
                    ],
                ];
            }
            $updates[$user_fields['username']] = $username_param;
        }

        if (! empty($params['email'])) {
            $email_param = $params['email'];
            assert(is_string($email_param));
            if (($error = validate_mail_address($user_ids[0], $email_param)) != '') {
                // return new PwgError(WS_ERR_INVALID_PARAM, $error);
                return [
                    'error' => [
                        'code' => WS_ERR_INVALID_PARAM,
                        'message' => $error,
                    ],
                ];
            }
            $updates[$user_fields['email']] = $email_param;
        }

        if (! empty($params['password'])) {
            if (! is_webmaster()) {
                $password_protected_users = [$conf['guest_id']];

                $query = '
SELECT
    user_id
  FROM ' . Tables::userInfos() . '
  WHERE status IN (\'webmaster\', \'admin\')
;';
                $admin_ids = query2array($query, null, 'user_id');

                // user_infos.id (primary key, NOT NULL): a raw DB fetch
                // value is a numeric string, build_user() may also set it as
                // int -- either way it's always scalar and string-castable.
                $current_user_id_val = $user['id'];
                $current_user_id_str = is_scalar($current_user_id_val) ? (string) $current_user_id_val : '0';

                // we add all admin+webmaster users BUT the user herself
                $password_protected_users = array_merge($password_protected_users, array_diff($admin_ids, [$current_user_id_str]));

                if (in_array($user_ids[0], $password_protected_users)) {
                    // return new PwgError(403, 'Only webmasters can change password of other "webmaster/admin" users');
                    return [
                        'error' => [
                            'code' => 403,
                            'message' => 'Only webmasters can change password of other "webmaster/admin" users',
                        ],
                    ];
                }
            }

            $password_param = $params['password'];
            assert(is_string($password_param));
            $updates[$user_fields['password']] = pwg_password_hash($password_param);
        }
    }

    if (! empty($params['status'])) {
        if (in_array($params['status'], ['webmaster', 'admin']) and ! is_webmaster()) {
            // return new PwgError(403, 'Only webmasters can grant "webmaster/admin" status');
            return [
                'error' => [
                    'code ' => 403,
                    'message' => 'Only webmasters can grant "webmaster/admin" status',
                ],
            ];
        }

        if (! in_array($params['status'], ['guest', 'generic', 'normal', 'admin', 'webmaster'])) {
            // return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid status');
            return [
                'error' => [
                    'code' => WS_ERR_INVALID_PARAM,
                    'message' => 'Invalid status',
                ],
            ];
        }

        // user['id']/conf's guest_id/webmaster_id are always scalar (raw DB
        // fetch value / int config values) and string-castable.
        $protected_users = array_filter(
            [
                $user['id'],
                $conf['guest_id'],
                $conf['webmaster_id'],
            ],
            is_scalar(...)
        );

        // an admin can't change status of other admin/webmaster
        if ($user['status'] == 'admin') {
            $query = '
SELECT
    user_id
  FROM ' . Tables::userInfos() . '
  WHERE status IN (\'webmaster\', \'admin\')
;';
            $protected_users = array_merge($protected_users, query2array($query, null, 'user_id'));
        }

        // status update query is separated from the rest as not applying to the same
        // set of users (current, guest and webmaster can't be changed)
        $user_ids_for_status = array_diff($user_ids, array_filter($protected_users, is_scalar(...)));

        $status_param = $params['status'];
        assert(is_string($status_param));
        $update_status = $status_param;
    }

    if (! empty($params['level']) or @$params['level'] === 0) {
        // $conf['available_permission_levels'] defaults to [0, 1, 2, 4, 8]
        // (see include/config_default.inc.php), always an array
        $available_permission_levels = $conf['available_permission_levels'];
        $available_permission_levels = is_array($available_permission_levels) ? $available_permission_levels : [];
        if (! in_array($params['level'], $available_permission_levels)) {
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

    if (! empty($params['language'])) {
        if (! in_array($params['language'], array_keys(get_languages()))) {
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

    if (! empty($params['theme'])) {
        if (! in_array($params['theme'], array_keys(get_pwg_themes()))) {
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

    if (! empty($params['nb_image_page'])) {
        $updates_infos['nb_image_page'] = $params['nb_image_page'];
    }

    if (! empty($params['recent_period']) or @$params['recent_period'] === 0) {
        $updates_infos['recent_period'] = $params['recent_period'];
    }

    if (! empty($params['expand']) or @$params['expand'] === false) {
        $updates_infos['expand'] = boolean_to_string($params['expand']);
    }

    if (! empty($params['show_nb_comments']) or @$params['show_nb_comments'] === false) {
        $updates_infos['show_nb_comments'] = boolean_to_string($params['show_nb_comments']);
    }

    if (! empty($params['show_nb_hits']) or @$params['show_nb_hits'] === false) {
        $updates_infos['show_nb_hits'] = boolean_to_string($params['show_nb_hits']);
    }

    if (! empty($params['enabled_high']) or @$params['enabled_high'] === false) {
        $updates_infos['enabled_high'] = boolean_to_string($params['enabled_high']);
    }

    // perform updates
    single_update(
        Tables::users(),
        $updates,
        [
            $user_fields['id'] => $user_ids[0],
        ]
    );

    if (isset($updates[$user_fields['password']])) {
        deactivate_user_auth_keys($user_ids[0]);
    }

    if (isset($updates[$user_fields['email']])) {
        deactivate_password_reset_key($user_ids[0]);
    }

    if (isset($update_status) and count($user_ids_for_status) > 0) {
        $query = '
UPDATE ' . Tables::userInfos() . ' SET
    status = "' . $update_status . '"
  WHERE user_id IN(' . implode(',', array_map(strval(...), $user_ids_for_status)) . ')
;';
        pwg_query($query);

        // we delete sessions, ie disconnect, for users if status becomes "guest".
        // It's like deactivating the user.
        if ($update_status == 'guest') {
            foreach ($user_ids_for_status as $user_id_for_status) {
                delete_user_sessions($user_id_for_status);
            }
        }
    }

    if (count($updates_infos) > 0) {
        $query = '
UPDATE ' . Tables::userInfos() . ' SET ';

        $first = true;
        foreach ($updates_infos as $field => $value) {
            if (! $first) {
                $query .= ', ';
            } else {
                $first = false;
            }
            assert(is_scalar($value));
            $query .= $field . ' = "' . $value . '"';
        }

        $query .= '
  WHERE user_id IN(' . implode(',', array_map(strval(...), $user_ids)) . ')
;';
        pwg_query($query);
    }

    // manage association to groups
    if (! empty($params['group_id'])) {
        $group_id_param = $params['group_id'];
        assert(is_array($group_id_param));
        $group_ids_param = [];
        foreach ($group_id_param as $raw_group_id) {
            assert(is_int($raw_group_id) || (is_string($raw_group_id) && is_numeric($raw_group_id)));
            $group_ids_param[] = (int) $raw_group_id;
        }

        $query = '
DELETE
  FROM ' . Tables::userGroup() . '
  WHERE user_id IN (' . implode(',', array_map(strval(...), $user_ids)) . ')
;';
        pwg_query($query);

        // we remove all provided groups that do not really exist
        $query = '
SELECT
    id
  FROM `' . Tables::groups() . '`
  WHERE id IN (' . implode(',', array_map(strval(...), $group_ids_param)) . ')
;';
        $group_ids = array_from_query($query, 'id');

        // if only -1 (a group id that can't exist) is in the list, then no
        // group is associated

        if (count($group_ids) > 0) {
            $inserts = [];

            foreach ($group_ids as $group_id) {
                foreach ($user_ids as $user_id) {
                    $inserts[] = [
                        'user_id' => $user_id,
                        'group_id' => $group_id,
                    ];
                }
            }

            mass_inserts(Tables::userGroup(), array_keys($inserts[0]), $inserts);
        }
    }

    invalidate_user_cache();

    pwg_activity('user', $user_ids, 'edit');

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
 * @return array<string, mixed> auth_key / apikey_secret / apikey_name /
 * user_id / created_on / duration / expired_on / key_type
 */
function create_api_key($user_id, $duration, $key_name): array
{
    $key_id = 'pkid-' . date('Ymd') . '-' . generate_key(20);
    $key_secret = generate_key(40);

    $row = pwg_db_fetch_row(pwg_query('SELECT NOW();'));
    assert($row !== null);
    [$dbnow] = $row;

    $key = [
        'auth_key' => $key_id,
        'apikey_secret' => pwg_password_hash($key_secret),
        'apikey_name' => $key_name,
        'user_id' => $user_id,
        'created_on' => $dbnow,
        'key_type' => 'api_key',
    ];

    $expiration = null;
    if (! empty($duration)) {
        $query = '
SELECT
  ADDDATE(NOW(), INTERVAL ' . ($duration * 60 * 60 * 24) . ' SECOND)
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$expiration] = $row;
        $key['duration'] = $duration;
    }
    $key['expired_on'] = $expiration;

    single_insert(Tables::userAuthKeys(), $key);

    $key['apikey_secret'] = $key_secret;
    return $key;
}

/**
 * Revoke a api_key
 *
 * @since 16
 * @param int $user_id
 * @param string $pkid
 * @return string|true
 */
function revoke_api_key($user_id, $pkid)
{
    $query = '
SELECT
  COUNT(*),
  NOW()
  FROM `' . Tables::userAuthKeys() . '`
  WHERE auth_key = "' . $pkid . '"
  AND user_id = ' . $user_id . '
;';

    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$key, $now] = $row;
    if ($key == 0) {
        return l10n('API Key not found');
    }

    single_update(
        Tables::userAuthKeys(),
        [
            'revoked_on' => $now,
        ],
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
 * @param string $pkid
 * @return string|true
 */
function edit_api_key($user_id, $pkid, ?string $api_name)
{
    $query = '
SELECT
  COUNT(*)
  FROM `' . Tables::userAuthKeys() . '`
  WHERE auth_key = "' . $pkid . '"
  AND user_id = ' . $user_id . '
;';

    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$key] = $row;
    if ($key == 0) {
        return l10n('API Key not found');
    }

    single_update(
        Tables::userAuthKeys(),
        [
            'apikey_name' => $api_name,
        ],
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
 * @param string $user_id
 * @return false|array<int, array<string, mixed>>
 */
function get_api_key($user_id): false|array
{
    $query = '
SELECT *
  FROM `' . Tables::userAuthKeys() . '`
  WHERE user_id = ' . $user_id . '
  AND key_type = "api_key"
;';

    // query2array() with no key_name/value_name always returns a
    // sequential list (array<int, mixed>) — see qsearch_get_images()'s
    // comment in functions_search.inc.php for the general pattern; it's
    // already a list, so no array_values() wrapper is needed.
    $api_keys = query2array($query);
    if (! (bool) $api_keys) {
        return false;
    }

    $query = '
SELECT
  NOW()
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$now] = $row;
    assert($now !== null);

    foreach ($api_keys as $i => $api_key) {
        $api_key['apikey_secret'] = str_repeat('*', 40);
        unset($api_key['auth_key_id'], $api_key['user_id'], $api_key['key_type']);

        $api_key['apikey_name'] = stripslashes((string) $api_key['apikey_name']);

        // extracted before any bool value is assigned into $api_key below
        // (e.g. 'is_expired'), which would otherwise widen every sibling
        // key's inferred type for the rest of this loop iteration
        $created_on = $api_key['created_on'];
        assert(is_string($created_on));
        $api_key['created_on_format'] = format_date($created_on, ['day', 'month', 'year']);

        $expired_on_raw = $api_key['expired_on'];
        assert(is_string($expired_on_raw));
        $api_key['expired_on_format'] = format_date($expired_on_raw, ['day', 'month', 'year']);

        // also extracted early, for the same reason -- read again below,
        // after 'is_expired' has already widened $api_key's value type
        $revoked_on = $api_key['revoked_on'];

        $api_key['last_used_on_since'] =
          (bool) $api_key['last_used_on']
          ? time_since($api_key['last_used_on'], 'day')
          : l10n('Never');

        $expired_on = str2DateTime($expired_on_raw);
        $now = str2DateTime($now);
        if ($expired_on === false || $now === false) {
            throw new Exception('get_api_key(): str2DateTime() failed on a DB-stored date');
        }

        $api_key['is_expired'] = $expired_on < $now;
        if ($api_key['is_expired']) {
            $api_key['expiration'] = l10n('Expired');
        } else {
            $diff = dateDiff($now, $expired_on);
            if ($diff->days > 0) {
                $api_key['expiration'] = l10n('%d days', $diff->days);
            } elseif ($diff->h > 0) {
                $api_key['expiration'] = l10n('%d hours', $diff->h);
            } else {
                $api_key['expiration'] = l10n('%d minutes', $diff->i);
            }
        }

        $api_key['expired_on_since'] = time_since($expired_on_raw, 'day');

        $api_key['revoked_on_since'] =
          (bool) $revoked_on
          ? time_since($revoked_on, 'day')
          : null;

        $api_key['revoked_on_message'] =
          (bool) $revoked_on
          ? l10n('This API key was manually revoked on %s', format_date($revoked_on, ['day', 'month', 'year']))
          : null;

        $api_keys[$i] = $api_key;
    }

    return $api_keys;
}

/**
 * Get all available api_key
 *
 * @since 16
 * @param string $user_id
 * @return array<int, array<string, mixed>>|false
 */
function get_available_api_key($user_id)
{
    $api_keys = get_api_key($user_id);

    if (! (bool) $api_keys) {
        return false;
    }

    $available = [];
    foreach ($api_keys as $api_key) {
        if (! (bool) $api_key['is_expired'] && empty($api_key['revoked_on'])) {
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
    if (isset($_SESSION['connected_with']) and $_SESSION['connected_with'] === 'pwg_ui') {
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
function notification_api_key_expiration(string $username, string $email, int $days_left)
{
    /** @var array<string, mixed> $conf */
    global $conf;

    include_once PHPWG_ROOT_PATH . 'include/functions_mail.inc.php';
    $days_left_str = $days_left <= 1 ?
      l10n('Your API key will expire in %d day.', $days_left)
      : l10n('Your API key will expire in %d days.', $days_left);

    $message = '<p style="margin: 20px 0">' . l10n('Hello %s,', $username) . '</p>';
    $message .= '<p style="margin: 20px 0">' . $days_left_str . '</p>';
    $message .= '<p style="margin: 20px 0">' . l10n('To continue using the API, please renew your key before it expires.') . '</p>';
    $message .= '<p style="margin: 20px 0">' . l10n('You can manage your API keys in your <a href="%s">account settings.</a>', get_absolute_root_url() . 'profile.php') . '</p>';

    $gallery_title = $conf['gallery_title'];
    $gallery_title = is_string($gallery_title) ? $gallery_title : '';

    $result = @pwg_mail(
        $email,
        [
            'subject' => '[' . $gallery_title . '] ' . l10n('Your API key will expire soon'),
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
 * @return array{secret: string, code: string}
 */
function generate_user_code(): array
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $secret = PwgTOTP::generateSecret();
    // password_reset_code_duration defaults to 5*60 (int) in
    // include/config_default.inc.php, but once persisted to the config DB
    // table it comes back as a raw string (see load_conf_from_db())
    $password_reset_code_duration = $conf['password_reset_code_duration'];
    $password_reset_code_duration = is_numeric($password_reset_code_duration) ? (int) $password_reset_code_duration : 300;
    $code = PwgTOTP::generateCode($secret, min($password_reset_code_duration, 900)); // max 15 minutes

    return [
        'secret' => $secret,
        'code' => $code,
    ];
}

/**
 * Verify user code
 *
 * @since 16
 * @param string $code
 */
function verify_user_code(string $secret, $code): bool
{
    /** @var array<string, mixed> $conf */
    global $conf;

    // see generate_user_code() for why this needs numeric narrowing
    $password_reset_code_duration = $conf['password_reset_code_duration'];
    $password_reset_code_duration = is_numeric($password_reset_code_duration) ? (int) $password_reset_code_duration : 300;
    return PwgTOTP::verifyCode($code, $secret, min($password_reset_code_duration, 900), 1);
}

/**
 * Register in the user session, the "context" of the last 10 viewed images.
 *
 * @since 16
 */
function save_edit_context(): void
{
    /** @var array<string, mixed> $page */
    global $page;

    if (! is_admin() or ! isset($page['section_url']) or ! isset($page['image_id'])) {
        return;
    }

    // $page['image_id'] is int|numeric-string (include/section_init.inc.php
    // sets it from a URL token via is_numeric(), or the literal int 0),
    // $page['section_url'] always a string.
    $image_id = $page['image_id'];
    if (! is_int($image_id) && ! (is_string($image_id) && is_numeric($image_id))) {
        return;
    }
    $image_id = (int) $image_id;
    $section_url = $page['section_url'];
    $section_url = is_string($section_url) ? $section_url : '';

    $edit_context = $_SESSION['edit_context'] ?? null;
    if (! is_array($edit_context)) {
        $edit_context = [];
    }

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
    $_SESSION['edit_context'] = array_slice([
        $image_id => $section_url,
    ] + $edit_context, 0, 10, true);
}

/**
 * Returns the "context" of the requested image.
 *
 * @since 16
 * @param int $image_id
 */
function get_edit_context($image_id): false|string|null
{
    $edit_context = $_SESSION['edit_context'] ?? null;
    if (! is_array($edit_context) || ! isset($edit_context[$image_id])) {
        return false;
    }

    $value = $edit_context[$image_id];
    if (! is_string($value)) {
        return false;
    }

    return preg_replace('/^\/' . $image_id . '\//', '', $value);
}
