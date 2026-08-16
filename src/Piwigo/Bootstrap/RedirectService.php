<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use LogicException;
use Override;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Bootstrap\Projection\RedirectHtmlPageContext;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\AdminContext;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Lang\Event\LoadingLang;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserService;

/**
 * Lives in `Bootstrap` (L4Integration): `redirectHtml()`'s body calls
 * `PageTail::renderToString()`, itself L4Integration -- see
 * `Piwigo\Core\RedirectServiceInterface`'s own docblock.
 *
 * `UserService` is a constructor-injected dependency. `currentUser()`/
 * `currentTemplate()` below stay as Kernel-container-resolving static
 * helpers, not constructor-injected -- both are container-shared wrapper
 * types this class mutates via `set()` from a genuinely-static
 * (no-`$this`-needed) call shape, matching every other
 * Bootstrap/-internal resolver of this kind.
 *
 * redirectHttp()/redirectHtml() throw `Piwigo\Http\ResponseReadyException`
 * instead of calling header()/echo/exit() directly -- see that exception
 * class's own docblock for why and where it's caught. Neither
 * `PageHeaderRenderer::render()` nor `Template::parse($handle, false)`
 * (the default) echoes -- both accumulate into Template's own internal
 * buffer (see `Template::fetchOutput()`'s docblock), the same mechanism
 * `Controller\AboutController` relies on. `PageTail::renderToString()`
 * drains that same buffer as a string instead of echoing it, feeding the
 * thrown exception's Response body.
 */
final readonly class RedirectService implements RedirectServiceInterface
{
    public function __construct(
        private Lang $lang,
        private UserService $userService,
        private EventDispatcher $eventDispatcher,
        private PageState $pageState,
    ) {}

    private static function currentUser(): CurrentUser
    {
        $currentUser = Kernel::container()->get(CurrentUser::class);
        if (! $currentUser instanceof CurrentUser) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentUser::class);
        }
        return $currentUser;
    }

    /**
     * Resolves the container-shared instance -- this class already has
     * direct Kernel::container() access (arch-tested to Bootstrap/ only).
     */
    private static function paths(): Paths
    {
        $paths = Kernel::container()->get(Paths::class);
        if (! $paths instanceof Paths) {
            throw new LogicException('Container returned an unexpected type for ' . Paths::class);
        }
        return $paths;
    }

    private static function currentTemplate(): CurrentTemplate
    {
        $currentTemplate = Kernel::container()->get(CurrentTemplate::class);
        if (! $currentTemplate instanceof CurrentTemplate) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentTemplate::class);
        }
        return $currentTemplate;
    }

    private static function currentConfig(): CurrentConfig
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        return $currentConfig;
    }

    /**
     * Resolve helper matching the ones above -- needed for Template's own
     * required collaborators, same "Kernel-container-resolving static
     * helper" shape, not constructor-injected, since this class's own
     * `new Template(...)` construction sites are the only real consumer.
     */
    private static function adminContext(): AdminContext
    {
        $adminContext = Kernel::container()->get(AdminContext::class);
        if (! $adminContext instanceof AdminContext) {
            throw new LogicException('Container returned an unexpected type for ' . AdminContext::class);
        }
        return $adminContext;
    }

    private static function errorCollector(): ErrorCollector
    {
        $errorCollector = Kernel::container()->get(ErrorCollector::class);
        if (! $errorCollector instanceof ErrorCollector) {
            throw new LogicException('Container returned an unexpected type for ' . ErrorCollector::class);
        }
        return $errorCollector;
    }

    private static function processCache(): ProcessCache
    {
        $processCache = Kernel::container()->get(ProcessCache::class);
        if (! $processCache instanceof ProcessCache) {
            throw new LogicException('Container returned an unexpected type for ' . ProcessCache::class);
        }
        return $processCache;
    }

    private static function currentConfigService(): CurrentConfigService
    {
        $currentConfigService = Kernel::container()->get(CurrentConfigService::class);
        if (! $currentConfigService instanceof CurrentConfigService) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfigService::class);
        }
        return $currentConfigService;
    }

    private static function sessionService(): SessionService
    {
        $sessionService = Kernel::container()->get(SessionService::class);
        if (! $sessionService instanceof SessionService) {
            throw new LogicException('Container returned an unexpected type for ' . SessionService::class);
        }
        return $sessionService;
    }

    #[Override]
    public function redirectHttp(string $url, int $status = 302): never
    {
        // default url is on html format
        $url = html_entity_decode($url);
        throw new ResponseReadyException(ResponseFactory::redirect($url, $status));
    }

    #[Override]
    public function redirectHtml(string $url, string $msg = '', int $refresh_time = 0, int $status = 200): never
    {
        // $template/lang_info are genuinely not always set here: this method
        // can be called very early (e.g. a fatal before the per-request
        // bootstrap finishes), which is exactly what this check detects.
        // Lang::isLangInfoInitialized() provides isset()-like semantics for
        // lang_info; CurrentTemplate::isInitialized() does the same for
        // $template -- do not simplify the null-coalesce below, it's what
        // makes the real early-crash fallback path reachable. $user below
        // is a plain local -- written once, immediately consumed by the
        // adjacent CurrentUser::set(), never read again.
        $template = self::currentTemplate()->isInitialized() ? self::currentTemplate()->get() : null;

        if (! $this->lang->isLangInfoInitialized() || ! isset($template)) {
            $paths = self::paths();
            $guest_id = self::currentConfig()->guestId;
            $user = $this->userService->buildUser(UserId::from($guest_id));
            self::currentUser()->set(User::fromUserArray($user));
            $this->lang->load('common.lang');
            $this->eventDispatcher->dispatch(new LoadingLang());
            $this->lang->load('lang', $paths->siteLocal, [
                'no_fallback' => true,
                'local' => true,
            ]);
            $template = new Template(self::currentConfig(), $this->lang, self::adminContext(), $this->eventDispatcher, self::errorCollector(), self::processCache(), self::currentConfigService(), $paths, new AccessLevelChecker(self::currentUser(), self::currentConfig()), self::sessionService(), $paths->root . 'themes', ThemeId::from($this->userService->getDefaultTheme()));
            self::currentTemplate()->set($template);
        } elseif (self::adminContext()->isActive()) {
            $template = new Template(self::currentConfig(), $this->lang, self::adminContext(), $this->eventDispatcher, self::errorCollector(), self::processCache(), self::currentConfigService(), self::paths(), new AccessLevelChecker(self::currentUser(), self::currentConfig()), self::sessionService(), self::paths()->root . 'themes', ThemeId::from($this->userService->getDefaultTheme()));
            self::currentTemplate()->set($template);
        }

        // Neither branch above runs when $template was already set and
        // we're not in admin -- it's the pre-existing bootstrap Template
        // in that case. $template is a real, locally-typed variable (via
        // CurrentTemplate::get()), so PHPStan proves it's always a real
        // Template by this point.

        if ($msg === '' || $msg === '0') {
            $msg = nl2br($this->lang->t('Redirection...'));
        }

        $url_link = $url;
        $title = 'redirection';

        $refresh_str = (string) $refresh_time;
        new PageHeaderRenderer()
            ->render($title, $this->eventDispatcher, $this->pageState, self::currentTemplate(), self::currentConfig(), $refresh_str, $url_link);

        $template->assignContext(new RedirectHtmlPageContext(redirectMsg: $msg));

        $template->parse('redirect.latte');

        $body = PageTail::renderToString();
        throw new ResponseReadyException(ResponseFactory::html($body, $status));
    }

    #[Override]
    public function redirect(string $url, string $msg = '', int $refresh_time = 0): never
    {
        // with RefeshTime <> 0, only html must be used
        if (self::currentConfig()->defaultRedirectMethod === 'http'
            and $refresh_time === 0
            and ! headers_sent()
        ) {
            $this->redirectHttp($url);
        } else {
            $this->redirectHtml($url, $msg, $refresh_time);
        }
    }
}
