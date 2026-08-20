<?php

declare(strict_types=1);

namespace Piwigo\Http\Middleware;

use Exception;
use Override;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Lang;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Core\RequestMetrics;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;
use Piwigo\Html\HtmlService;
use Piwigo\Http\SessionBootstrap;
use Piwigo\Session\SessionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * First real per-request bootstrap middleware (workstream C3 Phase 1) --
 * verbatim-ported from the first half of `Bootstrap\RequestBootstrap::
 * connect()` (error-collector install through logger construction), not
 * rewritten: same statements, same order, only the `self::xxx()` static
 * resolvers replaced with real constructor-injected dependencies.
 *
 * Ordering preserved exactly, including a real dependency found while
 * porting this: the session-GC `ini_set()` calls and
 * `SessionBootstrap::register()` read `CurrentConfig` *before*
 * `ConfigService::loadConfFromDb()` runs below -- both only ever see
 * config-file/env defaults at that point, never a DB-persisted override.
 * This may or may not be intentional, but it is today's real behavior;
 * moving `loadConfFromDb()` earlier would be an unrelated, out-of-scope
 * change smuggled into this refactor.
 *
 * `Http\Middleware\SessionMiddleware` (unmodified by this class) starts
 * the actual PHP session later in the pipeline, after this middleware's
 * own DB connect/config load/logger construction -- matching `connect()`'s
 * own `session_start()` position, which sits after all of that in the
 * original method too.
 *
 * `connect()`'s own bare `self::imageStdParams();` call (an eager-resolve
 * with no assignment, warming the container-shared `ImageStdParams`
 * singleton before something downstream needed it fresh) has no
 * equivalent here -- constructor-injecting a dependency already forces
 * PHP-DI to resolve it at middleware-construction time, before any
 * middleware even starts processing the request, so the same warming
 * effect happens automatically. Nothing in this class or the ones after
 * it needs `ImageStdParams` directly, so it isn't listed as a dependency
 * at all; whichever real consumer downstream needs it resolves it via the
 * container as normal.
 */
final readonly class ConfigBootstrapMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ErrorCollector $errorCollector,
        private CurrentConfig $currentConfig,
        private RequestMetrics $requestMetrics,
        private SessionService $sessionService,
        private HtmlService $htmlService,
        private Lang $lang,
        private CurrentLogger $currentLogger,
        private Paths $paths,
        private CurrentConfigService $currentConfigService,
        private ConfigService $configService,
        private DbCredentials $dbCredentials,
        private SessionBootstrap $sessionBootstrap,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Route errors to DevTools (X-PHP-Error-N response headers) instead
        // of inline output, which corrupts JSON/XML/binary responses -- and
        // is also load-bearing for HtmlService::fatalError()'s own
        // recordFatal()+throw sequence (see
        // ErrorCollector::installIfConfigured()'s own docblock).
        $this->errorCollector->installIfConfigured();

        if ($this->currentConfig->sessionGcProbability > 0) {
            @ini_set('session.gc_divisor', 100);
            $gc_probability = $this->currentConfig->sessionGcProbability;
            @ini_set('session.gc_probability', min($gc_probability, 100));
        }

        $this->sessionBootstrap->register();

        $this->requestMetrics->executionUuid = $this->sessionService->generateKey(10);

        // Database connection. DbConnection::build() itself deliberately
        // never touches the session-level ONLY_FULL_GROUP_BY server mode.
        // Built eagerly (not left to DBAL's own lazy first-query connect)
        // so an unreachable DB surfaces here as a friendly fatalError()
        // page, not a raw exception from whatever call happens to run
        // first. Shared for every repository/service constructed for the
        // rest of this method -- DbConnection::build() returns a fresh
        // Connection on every call (no internal caching), so reusing this
        // one avoids opening a separate physical DB connection per
        // repository.
        $conn = DbConnection::build();
        $db_password = $this->dbCredentials->password;
        try {
            $conn->getNativeConnection();
        } catch (Exception $e) {
            $this->htmlService
                ->fatalError($this->lang->t($e->getMessage()));
        }

        // ConfigService::loadConfFromDb() writes CurrentConfig's own
        // properties directly. CurrentConfigService::set() here makes
        // this resolved instance reachable via CurrentConfigService::get()
        // for the rest of this request -- see its own docblock.
        $this->currentConfigService->set($this->configService);
        $this->configService->loadConfFromDb();

        $log_data_location = $this->currentConfig->dataLocation;
        $log_dir = $this->currentConfig->logDir;

        $this->currentLogger->set(new Logger([
            'directory' => $this->paths->root . $log_data_location . $log_dir,
            'severity' => $this->currentConfig->logLevel,
            // we use an hashed filename to prevent direct file access, and we salt with
            // the db_password instead of secret_key because the log must be usable in i.php
            // (secret_key is in the database)
            'filename' => 'log_' . date('Y-m-d') . '_' . sha1(date('Y-m-d') . $db_password) . '.txt',
            'globPattern' => 'log_*.txt',
            'archiveDays' => $this->currentConfig->logArchiveDays,
        ]));

        return $handler->handle($request);
    }
}
