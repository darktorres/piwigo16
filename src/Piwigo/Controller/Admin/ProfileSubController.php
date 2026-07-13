<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/profile.php (page slug "profile") -- a flat page, pure
 * delegate. Still calls build_user()/save_profile_from_post()/
 * load_profile_in_template() (include/functions_user.inc.php,
 * root profile.php) as free functions -- those 3 functions plus
 * getuserdata()/check_and_save_user_infos()/auth_key_login() are the
 * still-deferred task #343 scope. Investigated during this batch: those
 * functions total 800+ lines of security-critical auth/registration logic
 * (getuserdata() 286L, check_and_save_user_infos() 361L, auth_key_login()
 * 127L) -- a substantial, high-stakes undertaking on its own, correctly
 * out of proportion for this batch's real scope (6 admin pages). Task
 * #343 stays deferred with this note; the free functions keep working
 * exactly as before, no regression.
 *
 * KNOWN PRE-EXISTING BUG (not a P21 regression -- confirmed by
 * temporarily removing this page's config/admin_pages.php entry and
 * re-testing the exact same legacy AdminDispatcher fallback path, same
 * 500): `admin.php?page=profile` fatals with "Smarty: Unable to load
 * 'file:profile.tpl'". admin/profile.php's own `include_once
 * PHPWG_ROOT_PATH . 'profile.php';` pulls in the FRONTEND root
 * profile.php, which is a full standalone entry point (its own
 * $template->set_filename() + $themeconf reads), not designed to be
 * embedded mid-render inside an already-running admin page -- likely a
 * Smarty template-search-path mismatch between the admin and frontend
 * theme directories. Investigated, not fixed: root-causing Smarty's
 * theme-based template resolution is a genuine side quest orthogonal to
 * this phase's admin-controller-migration scope.
 */
final class ProfileSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/profile.php';
    }
}
