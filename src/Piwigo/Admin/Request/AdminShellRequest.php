<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for the parts of AdminShell::runDispatch()
 * that don't participate in its own page-slug alias rewriting.
 *
 * This deliberately does NOT cover `page`/`section`/`tab`'s own later
 * reads: `runDispatch()` rewrites `$_GET['page']`/`$_GET['section']`/
 * `$_GET['tab']` in place (the `plugin-`/`album-`/`photo-` slug alias
 * blocks) specifically so `RequestFactory::fromGlobals()` (called at the
 * very end of the same method, to build the PSR-7 request every
 * sub-controller receives) sees the *rewritten* values -- an
 * intentional, load-bearing exception to reading through a DTO, the
 * same category as `RequestFactory::fromGlobals()`'s own sole
 * legitimate superglobal read. `tab`'s own `InputValidator::validate()`
 * call runs after that rewriting too (a rewritten tab value from the
 * `album-134-properties` alias form must still be the one validated),
 * so it stays in `runDispatch()` itself, not here.
 *
 * `testGet`/`changeThemeUrlParams` are both snapshotted from `$_GET`
 * before any of that rewriting happens (their own real read sites, in
 * the original, both run earlier in the method than the first alias
 * block) -- `page`/`section`'s own `InputValidator::validate()` calls
 * run at that same early point too, and are folded in here.
 *
 * `pluginsNewOrder` narrows to `?string`: its sole write path,
 * `AdminShell::runDispatch()`'s `setSessionVar('plugins_new_order',
 * ...)`, round-trips through the identical `$_SESSION[
 * 'pwg_plugins_new_order']` key `SessionService::getPluginsNewOrder()`
 * already reads back as `?string`.
 */
final readonly class AdminShellRequest
{
    /**
     * @param array<int|string, mixed> $testGet
     * @param array<string, string> $changeThemeUrlParams
     */
    private function __construct(
        public bool $pluginsNewOrderPresent,
        public ?string $pluginsNewOrder,
        public bool $changeThemePresent,
        public array $changeThemeUrlParams,
        public array $testGet,
        public bool $isPostNonEmpty,
    ) {}

    public static function fromGlobals(InputValidator $inputValidator): self
    {
        return self::fromArrays($_GET, $_POST, $inputValidator);
    }

    /**
     * @param array<int|string, mixed> $get
     * @param array<int|string, mixed> $post
     */
    public static function fromArrays(array $get, array $post, InputValidator $inputValidator): self
    {
        $inputValidator
            ->validate('page', $get, false, '/^[a-zA-Z\d_-]+$/');
        $inputValidator
            ->validate('section', $get, false, '/^[a-z]+[a-z_\/-]*(\.php)?$/i');

        $change_theme_url_params = [];
        foreach (['page', 'tab', 'section'] as $url_param) {
            if (isset($get[$url_param]) and is_scalar($get[$url_param])) {
                $change_theme_url_params[$url_param] = (string) $get[$url_param];
            }
        }

        $test_get = $get;
        unset($test_get['page'], $test_get['section'], $test_get['tag']);

        $plugins_new_order_raw = $get['plugins_new_order'] ?? null;

        return new self(
            isset($get['plugins_new_order']),
            is_string($plugins_new_order_raw) ? $plugins_new_order_raw : null,
            isset($get['change_theme']),
            $change_theme_url_params,
            $test_get,
            $post !== [],
        );
    }
}
