<?php

declare(strict_types=1);

use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\Event\TryLogUser;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Auth\UserFailedLoginEntity;
use Piwigo\Auth\UserFailedLoginRepository;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Controller\Request\IdentificationSubmitRequest;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionRepository;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;

/**
 * Finding 4 (post-DI-campaign shim/facade audit): real, live proof that
 * #[\SensitiveParameter] keeps a plaintext password out of an exception's
 * *captured trace* at each of this finding's fixed sites -- not just that
 * the attribute is present (a reflection-only check would pass even if it
 * did nothing).
 *
 * `zend.exception_ignore_args` defaults to On on this machine's php.ini (a
 * local hardening default this repo doesn't itself configure -- checked,
 * no override anywhere in docker/) -- with it On, Exception::getTrace()
 * never includes 'args' at all, redacted or not, which would make every
 * test below pass for the wrong reason. Each test forces it off for its
 * own duration (matching what a php.ini without that hardening actually
 * exposes -- the real risk this finding describes) and restores the
 * original value in a finally block, same save/restore discipline as
 * HtmlServiceTest.php's own pcre.backtrack_limit tests.
 *
 * Deliberately self-contained (own local helpers, own function names) --
 * `composer test` runs --parallel and doesn't guarantee this file shares
 * a worker with AuthServiceTest.php, so it can't rely on that file's
 * global helper functions being defined in the same process.
 */
function sensitiveParameterTestForceArgsCaptured(): string
{
    $original = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');

    return $original === false ? '1' : $original;
}

function sensitiveParameterTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-sensitiveparameter-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);

    return $root;
}

/**
 * Walks a captured exception trace's `args` for $needle without ever
 * materializing a `print_r()`-style flat string -- a trace raised deep
 * inside a fully-booted app (real EntityManager/Container/EventDispatcher
 * on the stack) can `print_r()` into a multi-gigabyte string, and PHPUnit's
 * Exporter (invoked to build a failure/description message even on
 * `->not->toContain()`'s expected-success path, since Pest implements
 * negation by catching the underlying positive assertion's failure) then
 * tries to bin2hex() the whole thing -- confirmed live to OOM-kill the
 * process.
 *
 * `Exception::getTrace()` captures the *entire* call stack, not just the
 * application code between this test's own try/catch: every frame from
 * PHPUnit's TestRunner/TestSuite/TestCase and Pest's own test-closure
 * machinery is in there too, all the way up to `bin/pest`'s entry point.
 * Confirmed live (dumped real frame class/arg-type lists under both
 * `composer test -- --filter=...` and `composer test:profile`) that
 * `PHPUnit\Framework\TestSuite::runTests(array $tests, ...)` receives, as
 * its own `$tests` arg, literally every TestCase queued for the current
 * run -- under `--filter` that's a handful, harmless; under
 * `composer test:profile` (single long-lived process, no `--parallel`,
 * so no ParaTest worker ever splits the suite) it's all 5,526 Unit+Arch
 * tests in one array, each a full TestCase object. Walking into that from
 * `TestSuite`/`TestRunner`/`TestCase` frames is what exhausted a
 * 200,000-node budget even with a depth cap of 12 -- not a leak, not a
 * bug, just PHPUnit's own bookkeeping, and not a meaningful security
 * question either (a real exception trace captured in production never
 * has PHPUnit frames on it at all). Frames whose class belongs to the
 * test framework itself are skipped entirely rather than walked and
 * bounded -- the right fix for "irrelevant and unboundedly large", not a
 * bigger budget for it.
 *
 * What's left after that filter is genuinely small application-level
 * state, bounded by DEPTH: a real leak sits shallow (a property a level
 * or two off a frame's own args, e.g. an event/DTO object), so the depth
 * cap finds it fast regardless of how large the rest of the graph is. The
 * spl_object_id() visited-set guards the walk against Doctrine's
 * documented reference-cyclic EntityManager/UnitOfWork graph (see
 * tests/Pest.php). $nodeBudget stays as a backstop against a
 * combinatorially wide graph within the depth cap (throws rather than
 * silently returning false -- a leak check must not report "safe" without
 * having actually finished checking) but shouldn't fire in practice now
 * that the framework's own huge, irrelevant state is excluded up front.
 *
 * @param list<array{function: string, line?: int, file?: string, class?: class-string, type?: '->'|'::', args?: list<mixed>, object?: object}> $trace
 */
function sensitiveParameterTestTraceContains(array $trace, string $needle, int $maxDepth = 12, int $nodeBudget = 200_000): bool
{
    $visited = [];
    $nodesVisited = 0;
    $stack = [];
    foreach ($trace as $frame) {
        $frameClass = $frame['class'] ?? '';
        if (str_starts_with($frameClass, 'PHPUnit\\') || str_starts_with($frameClass, 'Pest\\') || str_starts_with($frameClass, 'ParaTest\\')) {
            continue; // test-framework bookkeeping (e.g. TestSuite's own array of every queued test) -- not application state, and unboundedly large under a non-parallel full-suite run
        }
        foreach ($frame['args'] ?? [] as $arg) {
            $stack[] = [$arg, 0];
        }
    }

    while ($stack !== []) {
        if ($nodesVisited >= $nodeBudget) {
            throw new RuntimeException(sprintf(
                'Trace scan budget (%d nodes) exhausted before reaching a conclusive answer -- the call graph is too wide to safely verify even within the depth cap; raise the budget deliberately after checking why it grew.',
                $nodeBudget,
            ));
        }
        $nodesVisited++;

        [$value, $depth] = array_pop($stack);

        if ($value instanceof SensitiveParameterValue) {
            continue; // the redaction marker itself, not a leak
        }

        if (is_string($value)) {
            if (str_contains($value, $needle)) {
                return true;
            }
            continue;
        }

        if ($depth >= $maxDepth) {
            continue; // stop descending -- a real leak surfaces shallow; deep infrastructure internals aren't worth walking
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $stack[] = [$item, $depth + 1];
            }
            continue;
        }

        if (is_object($value)) {
            $id = spl_object_id($value);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            foreach ((array) $value as $property) {
                $stack[] = [$property, $depth + 1];
            }
        }
    }

    return false;
}

function sensitiveParameterTestAuthService(): AuthService
{
    $conn = DbConnection::build();
    $currentConfig = CurrentConfigTestFactory::get();

    return new AuthService(
        new AuthRepository(EntityManagerFactory::build($conn)),
        new ActivityService(TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class), ActivityRepository::class)),
        HtmlServiceTestFactory::build(),
        new PasswordService(new PasswordRepository(EntityManagerFactory::build($conn)), new DeploymentPolicy()),
        new CookieService(),
        TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(UserFailedLoginEntity::class), UserFailedLoginRepository::class),
        new SessionService(TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), SessionRepository::class), $currentConfig),
        EventDispatcherTestFactory::get(),
        PageStateTestFactory::get(),
        CurrentUserTestFactory::get(),
        $currentConfig,
        CurrentPathsTestFactory::get(),
        EntityManagerFactory::build($conn),
        new ConnectedWithSession(),
    );
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sensitiveParameterTestRoot()));
});

afterEach(function (): void {
    Kernel::reset();
    CurrentConfigTestFactory::get()->reset();
    CurrentUserTestFactory::get()->reset();
    PageStateTestFactory::get()->reset();
});

test('AuthService::tryLogUser() keeps the plaintext password out of its own frame in a trace raised while it is still on the call stack', function (): void {
    $originalIni = sensitiveParameterTestForceArgsCaptured();

    try {
        EventDispatcherTestFactory::get()
            ->addTypedHandler(TryLogUser::class, function (TryLogUser $event): never {
                throw new RuntimeException('forced failure with a password on the call stack');
            });

        $exception = null;
        try {
            sensitiveParameterTestAuthService()->tryLogUser('someuser', 'super-secret-plaintext', false);
        } catch (RuntimeException $e) {
            $exception = $e;
        }

        expect($exception)
            ->not->toBeNull();
        if (! $exception instanceof RuntimeException) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }

        $trace = $exception->getTrace();
        $tryLogUserFrame = array_find($trace, fn ($frame): bool => $frame['function'] === 'tryLogUser');

        expect($tryLogUserFrame)
            ->not->toBeNull();
        if ($tryLogUserFrame === null) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }
        expect($tryLogUserFrame['args'][1] ?? null)
            ->toBeInstanceOf(SensitiveParameterValue::class);
        expect(sensitiveParameterTestTraceContains($trace, 'super-secret-plaintext'))
            ->toBeFalse();
    } finally {
        ini_set('zend.exception_ignore_args', $originalIni);
    }
});

test('DbCredentials::__construct() keeps the plaintext password out of its own frame when a TypeError interrupts argument binding', function (): void {
    $originalIni = sensitiveParameterTestForceArgsCaptured();

    try {
        $exception = null;
        try {
            // A deliberately wrong type for $password (array instead of
            // string) forces a TypeError while this exact constructor
            // call is still on the stack -- reflection instead of a
            // real `new DbCredentials(...)` call so PHPStan doesn't need
            // an argument.type suppression for an intentionally-invalid
            // call that exists purely to observe the trace.
            new ReflectionClass(DbCredentials::class)
                ->newInstanceArgs(['db.example.test', 'root', ['not', 'a', 'string'], 'piwigo']);
        } catch (TypeError $e) {
            $exception = $e;
        }

        expect($exception)
            ->not->toBeNull();
        if (! $exception instanceof TypeError) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }

        $trace = $exception->getTrace();
        $constructFrame = array_find($trace, fn ($frame): bool => $frame['function'] === '__construct' && ($frame['class'] ?? null) === DbCredentials::class);

        expect($constructFrame)
            ->not->toBeNull();
        if ($constructFrame === null) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }
        expect($constructFrame['args'][2] ?? null)
            ->toBeInstanceOf(SensitiveParameterValue::class);
        expect(sensitiveParameterTestTraceContains($trace, 'db.example.test'))
            ->toBeTrue(); // sanity: unattributed args (host) DO show up in the clear -- proves this is selective redaction, not a blanket hide
    } finally {
        ini_set('zend.exception_ignore_args', $originalIni);
    }
});

test('IdentificationSubmitRequest\'s private constructor keeps the plaintext password out of its own frame the same way, proving the protection isn\'t limited to public constructors', function (): void {
    $originalIni = sensitiveParameterTestForceArgsCaptured();

    try {
        $reflection = new ReflectionClass(IdentificationSubmitRequest::class);
        $constructor = $reflection->getConstructor();
        expect($constructor)
            ->not->toBeNull();
        if ($constructor === null) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }

        $exception = null;
        try {
            // Same technique as the DbCredentials test above, applied to
            // a *private* constructor -- newInstanceArgs() itself can't
            // reach a private constructor, hence invoking the
            // ReflectionMethod directly against an uninitialized
            // instance instead.
            $constructor->invoke(
                $reflection->newInstanceWithoutConstructor(),
                null,
                null,
                false,
                false,
                'someuser',
                ['not', 'a', 'string'],
                false,
            );
        } catch (TypeError $e) {
            $exception = $e;
        }

        expect($exception)
            ->not->toBeNull();
        if (! $exception instanceof TypeError) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }

        $trace = $exception->getTrace();
        $constructFrame = array_find($trace, fn ($frame): bool => $frame['function'] === '__construct' && ($frame['class'] ?? null) === IdentificationSubmitRequest::class);

        expect($constructFrame)
            ->not->toBeNull();
        if ($constructFrame === null) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }
        expect($constructFrame['args'][5] ?? null)
            ->toBeInstanceOf(SensitiveParameterValue::class);
        expect(sensitiveParameterTestTraceContains($trace, 'someuser'))
            ->toBeTrue(); // sanity: unattributed args (username) DO show up in the clear -- proves this is selective redaction, not a blanket hide
    } finally {
        ini_set('zend.exception_ignore_args', $originalIni);
    }
});

test('TryLogUser::__debugInfo() redacts the plaintext password from var_dump()-style output while leaving password() access untouched', function (): void {
    $event = new TryLogUser(false, 'someuser', 'super-secret-plaintext', false);

    ob_start();
    var_dump($event);
    $dumped = ob_get_clean();

    expect($dumped)
        ->not->toContain('super-secret-plaintext')
        ->and($event->password())
        ->toBe('super-secret-plaintext');
});

test('TryLogUser::__debugInfo() passes a null password through unredacted since there is nothing to protect', function (): void {
    $event = new TryLogUser(false, 'someuser', null, false);

    expect($event->__debugInfo())
        ->toBe([
            'success' => false,
            'username' => 'someuser',
            'password' => null,
            'rememberMe' => false,
        ]);
});

test('TryLogUser::__debugInfo() replaces a real password with an 8-char redaction marker, matching CurrentConfig::all()\'s own convention', function (): void {
    $event = new TryLogUser(true, 'someuser', 'x', true);

    expect($event->__debugInfo())
        ->toBe([
            'success' => true,
            'username' => 'someuser',
            'password' => '********',
            'rememberMe' => true,
        ]);
});
