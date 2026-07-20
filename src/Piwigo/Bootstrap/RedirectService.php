<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Config\Config;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Html\HtmlService;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserService;

/**
 * Legacy Coupling Retirement Phase 4b: the former `redirect()`/
 * `redirect_html()`/`redirect_http()` free functions
 * (`Piwigo\Http\functions.php`, now deleted), ported verbatim. Lives in
 * `Bootstrap` (L4Integration) for the same reason the deleted
 * include/page_tail.php seam did: `redirectHtml()`'s real body calls
 * `PageTail::render()`, itself L4Integration -- see
 * `Piwigo\Core\RedirectServiceInterface`'s own docblock. Needs zero
 * constructor args -- every dependency is constructed inline, exactly as
 * the free functions already did.
 */
final class RedirectService implements RedirectServiceInterface
{
    #[\Override]
    public function redirectHttp(string $url): never
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

    #[\Override]
    public function redirectHtml(string $url, string $msg = '', int $refresh_time = 0): never
    {
        global $user;

        // $template/lang_info are genuinely not always set here: this method
        // can be called very early (e.g. a fatal before common.inc.php finishes
        // bootstrapping), which is exactly what this check detects. Lang::
        // isLangInfoInitialized() (Legacy Coupling Retirement Track A
        // gap-fill batch G5) preserves the former raw global's isset()
        // semantics for $user/lang_info; CurrentTemplate::isInitialized()
        // does the same for $template (Phase 2 global-residual sweep --
        // do not simplify the null-coalesce below, it's what makes the
        // real early-crash fallback path reachable).
        $template = CurrentTemplate::isInitialized() ? CurrentTemplate::get() : null;

        if (! Lang::isLangInfoInitialized() || ! isset($template)) {
            $guest_id = Config::guestId();
            $user = new UserService(new \Piwigo\Users\UserRepository(DbConnection::build()), new \Piwigo\Group\GroupRepository(DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(DbConnection::build())), new HtmlService(), DbConnection::build())->buildUser($guest_id, true);
            CurrentUser::set(User::fromUserArray($user));
            Lang::load('common.lang');
            EventDispatcher::get()->triggerNotify('loading_lang');
            Lang::load('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, [
                'no_fallback' => true,
                'local' => true,
            ]);
            $template = new Template(PHPWG_ROOT_PATH . 'themes', new UserService(new \Piwigo\Users\UserRepository(DbConnection::build()), new \Piwigo\Group\GroupRepository(DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(DbConnection::build())), new HtmlService(), DbConnection::build())->getDefaultTheme());
            CurrentTemplate::set($template);
        } elseif (defined('IN_ADMIN') and IN_ADMIN) {
            $template = new Template(PHPWG_ROOT_PATH . 'themes', new UserService(new \Piwigo\Users\UserRepository(DbConnection::build()), new \Piwigo\Group\GroupRepository(DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(DbConnection::build())), new HtmlService(), DbConnection::build())->getDefaultTheme());
            CurrentTemplate::set($template);
        }

        // Neither branch above runs when $template was already set and
        // we're not in admin -- it's the pre-existing bootstrap Template
        // in that case. Phase 2 global-residual sweep: now that $template
        // is a real locally-typed variable (via CurrentTemplate::get())
        // instead of an untyped global, PHPStan proves this is always a
        // real Template by this point -- the old defensive re-check (kept
        // because it "isn't provable statically" under the untyped
        // global) is provably dead code now, not just redundant.

        if ($msg === '' || $msg === '0') {
            $msg = nl2br(l10n('Redirection...'));
        }

        $url_link = $url;
        $title = 'redirection';

        $template->set_filenames([
            'redirect' => 'redirect.tpl',
        ]);

        $refresh_str = (string) $refresh_time;
        new PageHeaderRenderer()
            ->render($title, $refresh_str, $url_link);

        $template->set_filenames([
            'redirect' => 'redirect.tpl',
        ]);
        $template->assign('REDIRECT_MSG', $msg);

        $template->parse('redirect');

        PageTail::render();

        exit();
    }

    #[\Override]
    public function redirect(string $url, string $msg = '', int $refresh_time = 0): never
    {
        // with RefeshTime <> 0, only html must be used
        if (Config::defaultRedirectMethod() === 'http'
            and $refresh_time === 0
            and ! headers_sent()
        ) {
            $this->redirectHttp($url);
        } else {
            $this->redirectHtml($url, $msg, $refresh_time);
        }
    }
}
