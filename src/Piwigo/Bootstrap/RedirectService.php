<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
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
 * `PageTail::renderToString()`, itself L4Integration -- see
 * `Piwigo\Core\RedirectServiceInterface`'s own docblock. Needs zero
 * constructor args -- every dependency is constructed inline, exactly as
 * the free functions already did.
 *
 * Legacy Coupling Retirement Phase 8, 8a: userService() below resolves via
 * Kernel::container() instead of manually repeating UserService's 6-arg
 * construction chain 3 times in redirectHtml() -- safe because
 * RequestBootstrap::configure() (Phase 8, 8a) now calls Kernel::boot() as
 * its own first statement, ahead of every real call site of this class
 * reachable from connect()/finalize() (e.g. the early-crash fallback path
 * below), so the container is guaranteed available at every one of them.
 *
 * Workstream C3: redirectHttp()/redirectHtml() now throw
 * Piwigo\Http\ResponseReadyException instead of calling header()/echo/
 * exit() directly -- see that exception class's own docblock for why
 * (the real Sentry-transaction/Server-Timing bug this fixes) and where
 * it's caught. redirectHtml() keeps every line up through
 * $template->parse('redirect') unchanged: neither PageHeaderRenderer::
 * render() nor Template::parse($handle, false) (the default) echo --
 * both accumulate into Template's own internal buffer (see
 * Template::fetchOutput()'s docblock), same mechanism
 * Controller\AboutController already relies on. Only the former
 * PageTail::render(); exit(); tail changes, to PageTail::renderToString()
 * (the same buffer, drained as a string instead of echoed) feeding the
 * thrown exception's Response body.
 */
final class RedirectService implements RedirectServiceInterface
{
    /**
     * DRY-extracted (Legacy Coupling Retirement Phase 8, 8a) -- was 3
     * identical `new UserService(new UserRepository(DbConnection::build()),
     * new GroupRepository(DbConnection::build()), new MailService(), new
     * ActivityService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Activity\ActivityEntity::class)), new
     * HtmlService(), DbConnection::build())` chains inline in
     * redirectHtml() below.
     */
    private static function userService(): UserService
    {
        $userService = Kernel::container()->get(UserService::class);
        if (! $userService instanceof UserService) {
            throw new \LogicException('Container returned an unexpected type for ' . UserService::class);
        }
        return $userService;
    }

    #[\Override]
    public function redirectHttp(string $url): never
    {
        // default url is on html format
        $url = html_entity_decode($url);
        throw new \Piwigo\Http\ResponseReadyException(\Piwigo\Http\ResponseFactory::redirect($url));
    }

    #[\Override]
    public function redirectHtml(string $url, string $msg = '', int $refresh_time = 0, int $status = 200): never
    {
        // $template/lang_info are genuinely not always set here: this method
        // can be called very early (e.g. a fatal before common.inc.php finishes
        // bootstrapping), which is exactly what this check detects. Lang::
        // isLangInfoInitialized() (Legacy Coupling Retirement Track A
        // gap-fill batch G5) preserves the former raw global's isset()
        // semantics for lang_info; CurrentTemplate::isInitialized() does
        // the same for $template (Phase 2 global-residual sweep -- do not
        // simplify the null-coalesce below, it's what makes the real
        // early-crash fallback path reachable). $user below is a plain
        // local -- written once, immediately consumed by the adjacent
        // CurrentUser::set(), never read again (Legacy Coupling Retirement
        // Phase 8, 8h).
        $template = CurrentTemplate::isInitialized() ? CurrentTemplate::get() : null;

        if (! Lang::isLangInfoInitialized() || ! isset($template)) {
            $paths = CurrentPaths::get();
            $guest_id = CurrentConfig::guestId();
            $user = self::userService()->buildUser($guest_id);
            CurrentUser::set(User::fromUserArray($user));
            Lang::load('common.lang');
            EventDispatcher::get()->triggerNotify('loading_lang');
            Lang::load('lang', $paths->siteLocal, [
                'no_fallback' => true,
                'local' => true,
            ]);
            $template = new Template($paths->root . 'themes', self::userService()->getDefaultTheme());
            CurrentTemplate::set($template);
        } elseif (\Piwigo\Core\AdminContext::isActive()) {
            $template = new Template(CurrentPaths::get()->root . 'themes', self::userService()->getDefaultTheme());
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
            $msg = nl2br(Lang::t('Redirection...'));
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

        $body = PageTail::renderToString();
        throw new \Piwigo\Http\ResponseReadyException(\Piwigo\Http\ResponseFactory::html($body, $status));
    }

    #[\Override]
    public function redirect(string $url, string $msg = '', int $refresh_time = 0): never
    {
        // with RefeshTime <> 0, only html must be used
        if (CurrentConfig::defaultRedirectMethod() === 'http'
            and $refresh_time === 0
            and ! headers_sent()
        ) {
            $this->redirectHttp($url);
        } else {
            $this->redirectHtml($url, $msg, $refresh_time);
        }
    }
}
