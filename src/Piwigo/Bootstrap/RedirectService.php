<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Event\Lifecycle\LoadingLang;
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
 * `Piwigo\Core\RedirectServiceInterface`'s own docblock.
 *
 * Singleton/service-locator elimination campaign, Phase 6: `UserService` is
 * now a real constructor-injected dependency (was: a `userService()` private
 * static resolver reaching `Kernel::container()` on every call, the exact
 * shape the Bootstrap Accessor classes this phase converts already target --
 * this class's own docblock was the literal precedent cited for including it
 * in that phase). `currentUser()`/`currentTemplate()` below stay as
 * Kernel-container-resolving static helpers, not constructor-injected --
 * both are container-shared wrapper types this class mutates via `set()`
 * from a genuinely-static (no-`$this`-needed) call shape, matching every
 * other Bootstrap/-internal resolver of this kind; only `userService()`,
 * the actual Bootstrap-Accessor-shaped locator, was in scope here.
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
    public function __construct(
        private readonly Lang $lang,
        private readonly UserService $userService,
    ) {}

    private static function currentUser(): CurrentUser
    {
        $currentUser = Kernel::container()->get(CurrentUser::class);
        if (! $currentUser instanceof CurrentUser) {
            throw new \LogicException('Container returned an unexpected type for ' . CurrentUser::class);
        }
        return $currentUser;
    }

    private static function currentTemplate(): CurrentTemplate
    {
        $currentTemplate = Kernel::container()->get(CurrentTemplate::class);
        if (! $currentTemplate instanceof CurrentTemplate) {
            throw new \LogicException('Container returned an unexpected type for ' . CurrentTemplate::class);
        }
        return $currentTemplate;
    }

    private static function currentConfig(): CurrentConfig
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new \LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        return $currentConfig;
    }

    /**
     * Resolve helpers matching the 3 above -- added for Template's own new
     * required collaborators (singleton/service-locator elimination
     * campaign, Phase 11 sub-phase 11E), same "Kernel-container-resolving
     * static helper" shape, not constructor-injected, since this class's
     * own `new Template(...)` construction sites are the only real
     * consumer.
     */
    private static function adminContext(): \Piwigo\Core\AdminContext
    {
        $adminContext = Kernel::container()->get(\Piwigo\Core\AdminContext::class);
        if (! $adminContext instanceof \Piwigo\Core\AdminContext) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Core\AdminContext::class);
        }
        return $adminContext;
    }

    private static function errorCollector(): \Piwigo\Core\ErrorCollector
    {
        $errorCollector = Kernel::container()->get(\Piwigo\Core\ErrorCollector::class);
        if (! $errorCollector instanceof \Piwigo\Core\ErrorCollector) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Core\ErrorCollector::class);
        }
        return $errorCollector;
    }

    private static function processCache(): \Piwigo\Core\ProcessCache
    {
        $processCache = Kernel::container()->get(\Piwigo\Core\ProcessCache::class);
        if (! $processCache instanceof \Piwigo\Core\ProcessCache) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Core\ProcessCache::class);
        }
        return $processCache;
    }

    private static function currentConfigService(): \Piwigo\Config\CurrentConfigService
    {
        $currentConfigService = Kernel::container()->get(\Piwigo\Config\CurrentConfigService::class);
        if (! $currentConfigService instanceof \Piwigo\Config\CurrentConfigService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\CurrentConfigService::class);
        }
        return $currentConfigService;
    }

    #[\Override]
    public function redirectHttp(string $url, int $status = 302): never
    {
        // default url is on html format
        $url = html_entity_decode($url);
        throw new \Piwigo\Http\ResponseReadyException(\Piwigo\Http\ResponseFactory::redirect($url, $status));
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
        $template = self::currentTemplate()->isInitialized() ? self::currentTemplate()->get() : null;

        if (! $this->lang->isLangInfoInitialized() || ! isset($template)) {
            $paths = CurrentPaths::get();
            $guest_id = self::currentConfig()->guestId();
            $user = $this->userService->buildUser(\Piwigo\Common\ValueObject\UserId::from($guest_id));
            self::currentUser()->set(User::fromUserArray($user));
            $this->lang->load('common.lang');
            EventDispatcher::get()->dispatchNotify(new LoadingLang());
            $this->lang->load('lang', $paths->siteLocal, [
                'no_fallback' => true,
                'local' => true,
            ]);
            $template = new Template(self::currentConfig(), $this->lang, self::adminContext(), EventDispatcher::get(), \Piwigo\Core\PageState::current(), self::errorCollector(), self::processCache(), self::currentConfigService(), $paths->root . 'themes', $this->userService->getDefaultTheme());
            self::currentTemplate()->set($template);
        } elseif (\Piwigo\Core\AdminContext::isActiveStatic()) {
            $template = new Template(self::currentConfig(), $this->lang, self::adminContext(), EventDispatcher::get(), \Piwigo\Core\PageState::current(), self::errorCollector(), self::processCache(), self::currentConfigService(), CurrentPaths::get()->root . 'themes', $this->userService->getDefaultTheme());
            self::currentTemplate()->set($template);
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
            $msg = nl2br($this->lang->t('Redirection...'));
        }

        $url_link = $url;
        $title = 'redirection';

        $template->set_filenames([
            'redirect' => 'redirect.tpl',
        ]);

        $refresh_str = (string) $refresh_time;
        new PageHeaderRenderer()
            ->render($title, \Piwigo\PluginConfig\EventDispatcher::get(), \Piwigo\Core\PageState::current(), self::currentTemplate(), self::currentConfig(), $refresh_str, $url_link);

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
        if (self::currentConfig()->defaultRedirectMethod() === 'http'
            and $refresh_time === 0
            and ! headers_sent()
        ) {
            $this->redirectHttp($url);
        } else {
            $this->redirectHtml($url, $msg, $refresh_time);
        }
    }
}
