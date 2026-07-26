<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap\Request;

/**
 * Validated `$_GET`/`$_POST`/`$_REQUEST` shape for
 * UserBootstrap::initialize() -- P27/SEC-40 Request DTO.
 *
 * This runs at the very top of the request lifecycle (before any
 * controller/WS-method dispatch), but unlike `Http\RequestFactory::
 * fromGlobals()` or `Ws\PwgServer::invoke()`'s own dispatch, nothing else
 * in the framework already wraps these specific fields, so they're
 * genuine raw reads worth their own DTO -- constructed as the first
 * statement of `initialize()`, same as every other Request DTO this
 * migration has built.
 *
 * `authKeyPresent` stays separate from `authKey`: the original calls
 * `AuthService::authKeyLogin()` whenever `$_GET['auth']` is merely SET,
 * even with a malformed (non-string) value -- `authKeyLogin()` already
 * narrows internally (`is_string($authKey) ? $authKey : ''`, per its own
 * docblock), so passing `null` through for a non-string value is
 * behaviorally identical to the original passing the raw array; only the
 * presence check needs preserving separately from the narrowed value.
 *
 * `initialize()`'s own `$_POST['pwg_token'] = $_GET['pwg_token'] = ...;`
 * write is NOT covered by this DTO -- it's a genuine, load-bearing
 * mutation (the synthesized token must be visible to `Ws\Request\
 * WsRawRequest::fromGlobals()`'s own later `$_POST`/`$_GET` read, and to
 * any CSRF check reading `$_REQUEST['pwg_token']` downstream in the same
 * request), analogous to `AdminShell::runDispatch()`'s own page-alias
 * rewriting -- stays a raw write at the call site.
 */
final readonly class UserBootstrapRequest
{
    private function __construct(
        public bool $logoutRequested,
        public bool $authKeyPresent,
        public ?string $authKey,
        public ?string $wsMethod,
        public ?string $username,
        public ?string $password,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArrays($_GET, $_POST, $_REQUEST);
    }

    /**
     * @param array<int|string, mixed> $get
     * @param array<int|string, mixed> $post
     * @param array<int|string, mixed> $request
     */
    public static function fromArrays(array $get, array $post, array $request): self
    {
        $act_raw = $get['act'] ?? null;
        $logout_requested = $act_raw === 'logout';

        $auth_key_present = isset($get['auth']);
        $auth_key_raw = $get['auth'] ?? null;
        $auth_key = is_string($auth_key_raw) ? $auth_key_raw : null;

        $ws_method_raw = $request['method'] ?? null;
        $ws_method = is_string($ws_method_raw) ? $ws_method_raw : null;

        $username_raw = $post['username'] ?? null;
        $username = is_string($username_raw) ? $username_raw : null;

        $password_raw = $post['password'] ?? null;
        $password = is_string($password_raw) ? $password_raw : null;

        return new self(
            $logout_requested,
            $auth_key_present,
            $auth_key,
            $ws_method,
            $username,
            $password,
        );
    }
}
