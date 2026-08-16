<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Piwigo\Auth\AccessControl;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\PhotoSortOrder;
use Piwigo\Common\ValueObject\PluginId;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\NotificationConfig;
use Piwigo\Core\AdminContext;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Lang\LangService;
use Piwigo\Mail\MailService;
use Piwigo\PluginConfig\Facade\ImageReadFacade;
use Piwigo\PluginConfig\Facade\ThemeReadFacade;
use Piwigo\PluginConfig\Facade\UserReadFacade;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserService;

/**
 * The one object `ExtensionInterface::boot()` receives -- never the raw
 * DI container, matching this fork's own `Admin\PluginMaintain` precedent
 * (narrow, named collaborators, never the container). Composes existing
 * services; no new infrastructure.
 *
 * `PluginRegistry`/`ThemeRegistry` construct a **fresh** instance per
 * plugin/theme (P27.3), parameterized by that extension's own
 * `PluginId`/`ThemeId` -- not one shared instance across every plugin.
 * This is what makes `session()`'s namespacing real (see
 * `ExtensionSession`'s own docblock).
 *
 * **Two accessors carry a hard timing constraint plugin/theme authors
 * need to know about, both stemming from where `boot()` has to run.**
 * `PluginRegistry::bootActive()` runs at the same early position in
 * `Bootstrap\RequestBootstrap::connect()` that `Admin\PluginLoader::
 * loadPlugins()` holds today -- before `Bootstrap\UserBootstrap::
 * initialize()` (which resolves the real per-request `CurrentUser`) and
 * long before `RequestBootstrap::finalize()` (which constructs the
 * request's `Template`):
 * - `currentUser()` reflects pre-authentication state during `boot()` --
 *   not the real logged-in user. This isn't a new restriction; it's
 *   exactly why real legacy plugins already defer user-dependent logic to
 *   a later event (`init`, `user_init`) instead of running it at
 *   `main.inc.php`'s top level. User-dependent logic belongs in a
 *   `subscribedEvents()` handler for a later lifecycle event, never
 *   directly in `boot()`.
 * - `template()` is worse -- calling it from `boot()` doesn't return
 *   wrong data, it throws (see that method's own docblock).
 */
final readonly class ExtensionContext
{
    public function __construct(
        private PluginId|ThemeId $extensionId,
        private CurrentTemplate $currentTemplate,
        private CurrentConfig $currentConfig,
        private CurrentUser $currentUser,
        private UserService $userService,
        private Lang $lang,
        private UrlServiceInterface $urlService,
        private RedirectServiceInterface $redirectService,
        private AdminContext $adminContext,
        private EventDispatcher $eventDispatcher,
        private SessionService $sessionService,
        private ImageReadFacade $imageReadFacade,
        private Paths $paths,
        private ConfigService $configService,
        private EntityManagerInterface $entityManager,
        private MailService $mailService,
        private UserReadFacade $userReadFacade,
        private ThemeReadFacade $themeReadFacade,
        private CsrfService $csrfService,
        private HtmlRenderingInterface $htmlRenderer,
        private AccessControl $accessControl,
    ) {}

    /**
     * Throws when called from `boot()` -- `CurrentTemplate::get()` itself
     * only knows to say "call RequestBootstrap::finalize() first", which
     * means nothing to a plugin/theme author with no reason to know what
     * that method is. This checks `isInitialized()` itself first so the
     * thrown message instead names the real cause plugin authors can act
     * on: use a `subscribedEvents()` handler for a later lifecycle event
     * instead.
     */
    public function template(): Template
    {
        if (! $this->currentTemplate->isInitialized()) {
            throw new LogicException(
                'ExtensionContext::template() is unavailable during boot() -- the request\'s Template isn\'t constructed yet. Use a subscribedEvents() handler for a later lifecycle event instead.',
            );
        }

        return $this->currentTemplate->get();
    }

    /**
     * Returns the same shared `CurrentConfig` instance
     * `Bootstrap\RequestBootstrap` itself uses -- full read **and** write
     * access to its public properties, matching core's own established
     * idiom (`RequestBootstrap` itself does direct public-property
     * mutations on this same shared per-request instance). Safe to call
     * from `boot()`: `Config\ConfigService::loadConfFromDb()` runs before
     * `PluginRegistry::bootActive()`'s position in `connect()`.
     */
    public function config(): CurrentConfig
    {
        return $this->currentConfig;
    }

    /**
     * Reflects pre-authentication state when called from `boot()` -- see
     * this class's own docblock.
     */
    public function currentUser(): User
    {
        return $this->currentUser->get();
    }

    /**
     * One narrow, explicitly self-scoped write: updates only the acting
     * user's own `language` column, never another user's. Grounded in
     * `../piwigo16-plugins/language_switch_16.3.0/language_switch.inc.php`'s
     * own real `UPDATE user_infos SET language = ... WHERE user_id = ...`
     * query for a real (non-guest) user. No existing `UserService`/
     * `CurrentUser` method covered this before -- `CurrentUser::
     * updateLanguage()` only mutates this request's own in-memory state,
     * never persists; `UserService::updateInfosForUser()` persists but
     * accepts an arbitrary field/value map, the same "unrestricted
     * mutation" shape this class's own docblock says never to hand a
     * plugin directly. This method does both narrowly: persists, then
     * syncs the in-memory `CurrentUser` singleton so the rest of the
     * current request observes the change too, matching the legacy
     * plugin's own `$user['language'] = $_GET['lang'];` follow-up.
     */
    public function setLanguage(string $lang): void
    {
        $langCode = LangCode::from($lang);
        $this->userService->updateInfosForUser($this->currentUser->get()->id, [
            'language' => $lang,
        ]);
        $this->currentUser->updateLanguage($langCode);
    }

    /**
     * `setLanguage()`'s own in-memory-only half, exposed separately for a
     * guest/generic visitor's language choice: `language_switch_16.3.0/
     * language_switch.inc.php`'s own real behavior never writes to
     * `user_infos` for a guest/generic session (there's no individual row
     * to own the change) -- only `pwg_set_session_var('lang_switch', ...)`
     * persists it, across requests, via the session; this method is what
     * makes that session-stored choice observable for the *current*
     * request too, the same way `setLanguage()`'s own `$user['language']
     * = ...` follow-up does for a real user.
     */
    public function syncLanguageForRequest(string $lang): void
    {
        $this->currentUser->updateLanguage(LangCode::from($lang));
    }

    /**
     * Every language installed under the core `language/` tree, keyed by
     * code -- `LangService::getLanguages()`'s own real contract. Grounded
     * in `language_switch`'s own real `new languages(); $languages->
     * fs_languages` validation (is `$_GET['lang']` a real, installed
     * language?) and its own `get_languages()` call for the flag-switcher
     * UI (which languages to list at all).
     *
     * @return array<string, string>
     */
    public function languages(): array
    {
        return LangService::getLanguages($this->paths, $this->entityManager);
    }

    /**
     * Generic, arbitrary-key config read -- narrower than
     * `ConfigService::confGetParam()`'s own real return type (never
     * `NotificationConfig`, which that method only ever returns for a
     * *named* `CurrentConfig` property; an extension's own key is
     * intentionally never one of those). Grounded in a real, recurring
     * need across more than one bundled extension: `elegant`'s own
     * `admin/upgrade.inc.php` self-healing check and `modus`'s own
     * `theme_activate()` both read+write a `$conf['<own id>']` blob keyed
     * by their own literal id -- the same key a real, already-installed
     * site's `config` table would already hold from before this fork's
     * own migration, so using the extension's own id here (not an
     * auto-namespaced one, unlike `session()`) is what stays compatible
     * with that existing data.
     *
     * @param array<mixed>|string|int|float|bool|null $default
     * @return array<mixed>|string|int|float|bool|null
     */
    public function getSetting(string $key, array|string|int|float|bool|null $default = null): array|string|int|float|bool|null
    {
        $value = $this->configService->confGetParam($key, $default);

        // VO-typed config values have no plain-scalar form to hand a plugin,
        // so they read back as the caller's own default.
        return $value instanceof NotificationConfig || $value instanceof PhotoSortOrder ? $default : $value;
    }

    /**
     * @param array<mixed>|string|int|float|bool|null $value
     */
    public function setSetting(string $key, array|string|int|float|bool|null $value): void
    {
        $this->configService->confUpdateParam($key, $value);
    }

    /**
     * `ConfigService::confDeleteParam()`'s own real signature also accepts
     * a `list<string>` -- narrowed to a single key here, matching
     * `getSetting()`/`setSetting()`'s own one-key-at-a-time shape. A
     * plugin needing to delete several keys calls this several times.
     * Grounded in a real, common need: 95/406 bundled-catalog plugins and
     * 1/137 themes call legacy `conf_delete_param()` in their own
     * `uninstall()`/`delete()` hook -- the default shape for any plugin
     * with persisted settings at all, not an edge case.
     */
    public function deleteSetting(string $key): void
    {
        $this->configService->confDeleteParam($key);
    }

    /**
     * Carries a lighter version of `template()`'s own caution: `boot()`
     * runs before `RequestBootstrap::finalize()` loads translations, so a
     * plugin's own strings may not be loaded yet during `boot()` (missing
     * text, not a thrown exception).
     */
    public function lang(): Lang
    {
        return $this->lang;
    }

    public function url(): UrlServiceInterface
    {
        return $this->urlService;
    }

    public function redirect(string $url, string $msg = '', int $refresh_time = 0): never
    {
        $this->redirectService->redirect($url, $msg, $refresh_time);
    }

    /**
     * Wraps `Core\AdminContext::isActive()`, which is safe to read at any
     * point in the request (fixed once at container-build time, never
     * mutated mid-request -- unlike `currentUser()`/`template()` above).
     * Exists specifically because `ExtensionInterface::subscribedEvents()`
     * can't condition on anything -- the admin/public branch has to live
     * in handler methods, which need this to make that decision. Grounded
     * in real callers: `AdminTools`/`smartpocket` both genuinely need
     * `if (!defined('IN_ADMIN')) { register public hooks } else {
     * register admin hooks }` at registration time.
     */
    public function isAdminContext(): bool
    {
        return $this->adminContext->isActive();
    }

    /**
     * Namespaced `$_SESSION` store -- see `ExtensionSession`'s own
     * docblock for why this persists across requests rather than being
     * per-request scratch state.
     */
    public function session(): ExtensionSession
    {
        return new ExtensionSession($this->sessionService, $this->extensionId);
    }

    /**
     * @template T of object
     * @param T $event
     * @return T
     */
    public function dispatch(object $event): object
    {
        return $this->eventDispatcher->dispatch($event);
    }

    /**
     * Narrow, purpose-built read facade -- never the existing whole
     * `CategoryService`/`ImageService` directly. See that class's own
     * docblock for the real callers this is grounded in.
     */
    public function images(): ImageReadFacade
    {
        return $this->imageReadFacade;
    }

    /**
     * Narrow, purpose-built read facade -- never `UserService`/raw SQL
     * directly. See that class's own docblock for the real caller this
     * is grounded in.
     */
    public function users(): UserReadFacade
    {
        return $this->userReadFacade;
    }

    /**
     * Narrow, purpose-built read facade -- never `ThemeCatalog`/raw SQL
     * directly. See that class's own docblock for the real caller this
     * is grounded in.
     */
    public function themes(): ThemeReadFacade
    {
        return $this->themeReadFacade;
    }

    /**
     * `MailService::mail()`'s own real, already-public entry point --
     * every core caller (`mailAdmins()`/`mailGroup()`/
     * `mailNotificationAdmins()`) already funnels through it, and its
     * signature already closely matches legacy `pwg_mail($to, $args =
     * array(), $tpl = array())`. Wrapped directly, not behind a narrower
     * facade: unlike raw DB access, `MailService` is already the
     * sanctioned, safe way core itself sends mail.
     *
     * @param string|array<int|string, mixed> $to
     * @param array{from?: array{email: string, name?: string}|string, reply_to_mail_address?: string, reply_to_name?: string, Cc?: array{email: string, name?: string}|string, Bcc?: array{email: string, name?: string}|string, subject?: string, content?: string, content_format?: string, email_format?: string, theme?: string, mail_title?: string, mail_subtitle?: string, auth_key?: string} $args
     * @param array{filename?: string, dirname?: string, assign?: array<string, mixed>} $tpl
     */
    public function mail(string|array $to, array $args = [], array $tpl = []): bool
    {
        return $this->mailService->mail($to, $args, $tpl);
    }

    /**
     * Fail-fast CSRF check for a settings-page POST handler -- the real
     * usage shape every legacy plugin's own `check_pwg_token();` call
     * site already has (a bare statement, no `if`/return-value check), so
     * this is the primary accessor. `CsrfService::checkOrFail()`'s own
     * real contract (L2b, may not depend upward on Html/L3) is why this
     * needs `htmlRenderer` threaded through here rather than
     * `CsrfService` rendering its own failure page.
     */
    public function checkCsrfOrFail(): void
    {
        $this->csrfService->checkOrFail($this->htmlRenderer, $this->redirectService);
    }

    /**
     * `checkCsrfOrFail()`'s own non-throwing half, for a plugin author
     * who wants to render its own inline error inside `ADMIN_CONTENT`
     * instead of the admin shell's generic "access denied" page.
     */
    public function checkCsrf(): ?bool
    {
        return $this->csrfService->check();
    }

    public function csrfToken(): string
    {
        return $this->csrfService->getToken();
    }

    /**
     * `Auth\AccessControl::isWebmaster()`'s own real, already-established
     * permission check -- an `AccessLevel` hierarchy comparison against
     * the current user's status, not a bare `currentUser()->status ===
     * UserStatus::Webmaster` equality this class could otherwise already
     * express directly. Every existing admin controller with a
     * webmaster-only gate (`ConfigurationSubController`'s own real
     * `accessControl->isWebmaster()` calls) already goes through this
     * same service; a settings page's own gate should too.
     */
    public function isWebmaster(): bool
    {
        return $this->accessControl->isWebmaster();
    }
}
