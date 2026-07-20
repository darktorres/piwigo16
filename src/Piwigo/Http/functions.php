<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

use Piwigo\Html\HtmlService;
use Piwigo\Template\Template;

// P23 batch 8d: relocated unchanged from the deleted include/functions.inc.php
// -- redirect() alone has ~72 real call sites across ~34 files (on finding
// 4's original high-traffic table), too widely used to retarget every
// caller onto a class method, same "relocate ubiquitous utilities as
// unchanged free functions" two-track precedent as l10n()
// (src/Piwigo/Lang/functions.php) and get_root_url() (P23 batch 8c,
// src/Piwigo/Url/functions.php). Unlike those two, there's no natural
// existing class these delegate to: redirect_http() is pure header
// manipulation with zero dependencies, and redirect_html() is a real,
// self-contained page-render routine (PageHeaderRenderer/Bootstrap\PageTail
// calls since P23 sub-batch 8f-5 deleted the page_header.php/page_tail.php
// seams, matching an already-established pattern -- ~12 already-migrated
// src/Piwigo/ controllers do the same calls from inside a method) with no
// natural domain home; forcing a PSR-7 Response-object redesign to fit
// Piwigo\Http\ResponseFactory/ResponseEmitter's modern pipeline would be
// a behavioral rewrite, not a relocation, and is explicitly out of scope
// here. Every real caller keeps calling the exact same free-function
// names unchanged -- zero call sites retargeted.
//
// Every declaration is guarded with function_exists() for the same reason
// as PluginConfig/functions.php: composer's autoload.files loads this file
// once at process start, but a class_exists()/interface_exists() probe for
// a plausible-but-nonexistent FQCN under this namespace (e.g.
// tests/Arch/StructuralTest.php's "every Piwigo\ class ... has #[\Override]"
// test, which computes Piwigo\Http\functions from this file's own basename
// while walking every .php file under src/Piwigo/) makes composer's PSR-4
// resolver try to autoload Piwigo\Http\functions, resolve it to this exact
// file via the PSR-4 basename match, and `include` it a second time. The
// guard makes the second pass a safe no-op instead of a fatal.

/**
 * Redirects to the given URL (HTTP method).
 */
if (! function_exists('redirect_http')) {
    function redirect_http(string $url): never
    {
        if (ob_get_length() !== false) {
            ob_clean();
        }
        // default url is on html format
        $url = html_entity_decode($url);
        header('Request-URI: ' . $url);
        header('Content-Location: ' . $url);
        header('Location: ' . $url);
        exit();
    }
}

/**
 * Redirects to the given URL (HTML method).
 *
 * @param string $url
 * @param string $msg
 * @param int $refresh_time
 */
if (! function_exists('redirect_html')) {
    function redirect_html($url, $msg = '', $refresh_time = 0): never
    {
        global $user;

        // $template/lang_info are genuinely not always set here: this function
        // can be called very early (e.g. a fatal before common.inc.php finishes
        // bootstrapping), which is exactly what this check detects. Lang::
        // isLangInfoInitialized() (Legacy Coupling Retirement Track A
        // gap-fill batch G5) preserves the former raw global's isset()
        // semantics for $user/lang_info; CurrentTemplate::isInitialized()
        // does the same for $template (Phase 2 global-residual sweep --
        // do not simplify the null-coalesce below, it's what makes the
        // real early-crash fallback path reachable).
        $template = \Piwigo\Template\CurrentTemplate::isInitialized() ? \Piwigo\Template\CurrentTemplate::get() : null;

        if (! \Piwigo\Core\Lang::isLangInfoInitialized() || ! isset($template)) {
            $guest_id = \Piwigo\Config\Config::guestId();
            $user = new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService(), \Piwigo\Db\DbConnection::build())->buildUser($guest_id, true);
            \Piwigo\Users\CurrentUser::set(\Piwigo\Users\User::fromUserArray($user));
            \Piwigo\Core\Lang::load('common.lang');
            \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loading_lang');
            \Piwigo\Core\Lang::load('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, [
                'no_fallback' => true,
                'local' => true,
            ]);
            $template = new Template(PHPWG_ROOT_PATH . 'themes', new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService(), \Piwigo\Db\DbConnection::build())->getDefaultTheme());
            \Piwigo\Template\CurrentTemplate::set($template);
        } elseif (defined('IN_ADMIN') and IN_ADMIN) {
            $template = new Template(PHPWG_ROOT_PATH . 'themes', new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService(), \Piwigo\Db\DbConnection::build())->getDefaultTheme());
            \Piwigo\Template\CurrentTemplate::set($template);
        }

        // Neither branch above runs when $template was already set and
        // we're not in admin -- it's the pre-existing bootstrap Template
        // in that case. Phase 2 global-residual sweep: now that $template
        // is a real locally-typed variable (via CurrentTemplate::get())
        // instead of an untyped global, PHPStan proves this is always a
        // real Template by this point -- the old defensive re-check (kept
        // because it "isn't provable statically" under the untyped
        // global) is provably dead code now, not just redundant.

        if (empty($msg)) {
            $msg = nl2br(l10n('Redirection...'));
        }

        $refresh = $refresh_time;
        $url_link = $url;
        $title = 'redirection';

        $template->set_filenames([
            'redirect' => 'redirect.tpl',
        ]);

        // Same null-unless-numeric narrowing the deleted
        // include/page_header.php seam applied before calling the renderer.
        $refresh_str = is_numeric($refresh) ? (string) $refresh : null;
        new \Piwigo\Page\PageHeaderRenderer()
            ->render($title, $refresh_str, $url_link);

        $template->set_filenames([
            'redirect' => 'redirect.tpl',
        ]);
        $template->assign('REDIRECT_MSG', $msg);

        $template->parse('redirect');

        \Piwigo\Bootstrap\PageTail::render();

        exit();
    }
}

/**
 * Redirects to the given URL (automatically choose HTTP or HTML method).
 */
if (! function_exists('redirect')) {
    function redirect(string $url, string $msg = '', int $refresh_time = 0): never
    {

        // with RefeshTime <> 0, only html must be used
        if (\Piwigo\Config\Config::defaultRedirectMethod() === 'http'
            and $refresh_time === 0
            and ! headers_sent()
        ) {
            redirect_http($url);
        } else {
            redirect_html($url, $msg, $refresh_time);
        }
    }
}
