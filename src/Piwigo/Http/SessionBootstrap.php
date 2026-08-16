<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Piwigo\Auth\CookieService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\InstallationFlag;
use Piwigo\Session\SessionHandler;
use Piwigo\Session\SessionService;

/**
 * Installs Piwigo's DB-backed session save handler before session_start().
 * `Http\Middleware\SessionMiddleware`'s docblock documents this as the
 * thing that registers `SessionHandler` as the save handler on every real
 * request.
 *
 * Lives in `Piwigo\Http\` (L3Presentation), not `Piwigo\Session\` (L1):
 * the body constructs `Piwigo\Auth\CookieService` (L2a), an upward
 * dependency L1 may not take -- same "only violation-free home" reasoning
 * as `Bootstrap\UserBootstrap`. Moved here from `Piwigo\Bootstrap\`
 * (L4Integration, workstream C3 Phase 1) once `Http\Middleware\
 * ConfigBootstrapMiddleware` needed to call it too: `Http\Middleware\*` is
 * L3Presentation, which may not depend upward on L4Integration, so keeping
 * this in `Bootstrap\` would have meant either a real deptrac violation or
 * duplicating this security-adjacent logic (session cookie flags, save
 * handler registration) across two places. L3Presentation may depend
 * downward on L2aCoreDomain (`CookieService`) and L1Infrastructure
 * (`CurrentConfig`/`Session\*`), so this placement is violation-free for
 * both real callers: `Bootstrap\RequestBootstrap::connect()` (L4, may
 * depend downward on L3) and `Http\Middleware\ConfigBootstrapMiddleware`
 * (L3, same-layer). `public/install.php` constructs this directly too,
 * matching its own existing "build heavy objects by hand" pattern (see
 * that file's own `InstallWizard` construction).
 *
 * Converted from a static utility (with container-resolving reach-arounds
 * through `Core\Kernel`'s own accessor, arch-tested to `Bootstrap/` +
 * `index.php` only, which this class lost access to by moving out of
 * `Bootstrap\`) to a plain constructor-injected instance at the same time
 * -- the move made the conversion necessary, not just possible, and
 * matches every other `connect()`-derived piece of workstream C3 Phase 1.
 *
 * In PHP 8.4+ calling session_set_save_handler with two parameters is
 * deprecated. To correct this, we pass a SessionHandlerInterface instance.
 * https://github.com/Piwigo/Piwigo/issues/2296
 */
final readonly class SessionBootstrap
{
    public function __construct(
        private CurrentConfig $currentConfig,
        private SessionService $sessionService,
        private CurrentLogger $currentLogger,
        private InstallationFlag $installationFlag,
    ) {}

    public function register(): void
    {
        if ($this->currentConfig->sessionSaveHandler === 'db'
          and $this->installationFlag->isActive()) {
            session_set_save_handler(new SessionHandler($this->sessionService, $this->currentLogger));

            if (function_exists('ini_set')) {
                $session_use_cookies = $this->currentConfig->sessionUseCookies;
                ini_set('session.use_cookies', $session_use_cookies);

                $session_use_only_cookies = $this->currentConfig->sessionUseOnlyCookies;
                ini_set('session.use_only_cookies', $session_use_only_cookies);

                $session_use_trans_sid = $this->currentConfig->sessionUseTransSid;
                ini_set('session.use_trans_sid', intval($session_use_trans_sid));
                ini_set('session.cookie_httponly', 1);
            }

            $session_name = $this->currentConfig->sessionName;
            session_name($session_name);
            session_set_cookie_params(0, new CookieService()->cookiePath());
            register_shutdown_function(session_write_close(...));
        }
    }
}
