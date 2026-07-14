<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\ValidationPattern;
use Piwigo\Template\Template;

/**
 * Ported from admin/profile.php (page slug "profile"). Still calls
 * build_user()/save_profile_from_post()/load_profile_in_template()
 * (include/functions_user.inc.php, include/profile_functions.inc.php) as
 * free functions -- those plus getuserdata()/check_and_save_user_infos()/
 * auth_key_login() are the still-deferred task #343 scope (800+ lines of
 * security-critical auth/registration logic, out of proportion for this
 * batch's real scope). include/profile_functions.inc.php itself is NOT
 * touched here -- real callers outside this page too (admin/configuration.php's
 * "default" tab, still batch 6j, and the already-shipped front-end
 * Piwigo\Controller\ProfileController).
 *
 * FIXED (real bug, in scope): a missing $_GET['user_id'] used to default to
 * 0 and crash with an uncaught `getuserdata(): no such user_id 0` exception
 * (a raw, blank HTTP 500 -- confirmed live) instead of a graceful error.
 * Every sibling page in this batch (UserPermPageRenderer/
 * GroupPermPageRenderer) already guards its own required id param this way;
 * this page alone never got it. Deliberately narrow: only covers the
 * parameter-absent case, not a present-but-nonexistent user_id feeding the
 * same exception from inside getuserdata() itself -- that's build_user()'s
 * own robustness, inside task #343's already-deferred scope, not touched
 * here.
 *
 * KNOWN PRE-EXISTING BUG, NOT FIXED (re-diagnosed, not a regression): even
 * with a valid user_id, this page still crashes -- Apache's error log shows
 * `Uncaught --> Smarty: Unable to load 'file:profile.tpl' <--`. No
 * admin/themes/default/template/profile.tpl file has ever existed (only the
 * front-end themes/*\/template/profile.tpl do) -- a missing admin-theme
 * template, not a wrong include (a prior docblock here blamed a stale
 * `include_once PHPWG_ROOT_PATH . 'profile.php';` line that P22 already
 * removed). Authoring a new admin-theme template is building new UI
 * content, not porting existing logic -- out of proportion for this batch.
 * Grepped every admin template/JS file for `page=profile`: zero real links
 * to this admin page slug anywhere in the current UI, so this is likely
 * unreachable through normal navigation today.
 */
final class ProfilePageRenderer
{
    public function render(): void
    {
        /**
         * @var array<string, mixed> $page
         * @var Template $template
         */
        global $page, $template;

        check_input_parameter('user_id', $_GET, false, ValidationPattern::ID);

        if (! isset($_GET['user_id']) || ! is_numeric($_GET['user_id'])) {
            fatal_error('user_id URL parameter is missing');
        }

        $requested_user_id = (int) $_GET['user_id'];

        $edit_user = build_user($requested_user_id, false);

        if ($_POST !== []) {
            check_pwg_token();
        }

        // P22: profile.php's own save_profile_from_post()/
        // load_profile_in_template() moved to this shared include (root
        // profile.php is now pure bootstrap + dispatch, no free function
        // definitions left in it).
        include_once PHPWG_ROOT_PATH . 'include/profile_functions.inc.php';

        $errors = [];
        save_profile_from_post($edit_user, $errors);

        // $edit_user['id'] comes back from build_user()/getuserdata() as a raw DB
        // row value (string|null per this driver), not necessarily the int we
        // originally requested -- narrow it for the URL concatenation below.
        $edit_user_id = $edit_user['id'] ?? null;
        $edit_user_id = is_scalar($edit_user_id) ? (string) $edit_user_id : '';

        load_profile_in_template(
            get_root_url() . 'admin.php?page=profile&amp;user_id=' . $edit_user_id,
            get_root_url() . 'admin.php?page=user_list',
            $edit_user
        );
        // $page['errors'] is always initialized to [] by include/common.inc.php,
        // but that isn't visible across the include() boundary -- narrow before
        // merging.
        if (! is_array($page['errors'] ?? null)) {
            $page['errors'] = [];
        }
        $page['errors'] = array_merge($page['errors'], $errors);

        $template->set_filename('profile', 'profile.tpl');
        $template->assign_var_from_handle('ADMIN_CONTENT', 'profile');
    }
}
