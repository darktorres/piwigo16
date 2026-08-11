<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\ImageDerivativeController;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Image\DerivativeParams;
use Piwigo\Image\DerivativeUrlCodec;
use Piwigo\Image\ImageStdParams;
use Piwigo\Permission\ImageVisibilityChecker;
use Piwigo\Permission\PermissionRepository;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Users\CurrentUser;

// ImageDerivativeController's own trySwitchSource() redirects to an
// already-cached *different* derivative type when it's an exact match for
// what's being requested. Most of the class needs a real DB connection/
// filesystem/permission-checked request to exercise meaningfully, but its
// own URL-construction logic (derivativeUrlPath(), extracted specifically
// so this is possible) is pure and testable in isolation. A raw
// filesystem-relative redirect built the same way was a real bug
// (redirecting into the unreachable _data/i/ tree) -- this locks in the
// fix.

// Never actually invoked -- none of this file's tests exercise
// checkDerivativePermission()/a full __invoke() dispatch (only
// derivativeUrlPath()/parseCustomParams()/ierror() via direct reflection),
// so a real-but-never-queried instance (lazy DBAL, same reasoning as this
// suite's own $currentLogger throwaways) satisfies the constructor only.
function imageDerivativeControllerTestImageVisibilityChecker(): ImageVisibilityChecker
{
    $conn = DbConnection::build();

    return new ImageVisibilityChecker(
        new PermissionRepository(EntityManagerFactory::build($conn)),
        new CurrentUser(new CurrentConfig()),
    );
}

function callDerivativeUrlPath(string $urlSuffix, string $fromType, string $toType, CurrentConfig $currentConfig): string
{
    $method = new ReflectionMethod(ImageDerivativeController::class, 'derivativeUrlPath');

    /** @var string */
    return $method->invoke(null, $urlSuffix, $fromType, $toType, $currentConfig);
}

test('derivativeUrlPath substitutes the type token and keeps the rest of the suffix unchanged', function (): void {
    $currentConfig = new CurrentConfig();
    $currentConfig->phpExtensionInUrls = true;
    $currentConfig->questionMarkInUrls = true;

    $result = callDerivativeUrlPath(
        'upload/2026/08/01/20260801000000-2e7ed83c-th.jpg',
        ImageStdParams::THUMB,
        ImageStdParams::XXSMALL,
        $currentConfig,
    );

    expect($result)
        ->toBe('i.php?/upload/2026/08/01/20260801000000-2e7ed83c-2s.jpg');
});

test('derivativeUrlPath omits .php when php_extension_in_urls is disabled', function (): void {
    $currentConfig = new CurrentConfig();
    $currentConfig->phpExtensionInUrls = false;
    $currentConfig->questionMarkInUrls = true;

    $result = callDerivativeUrlPath('foo-sq.jpg', ImageStdParams::SQUARE, ImageStdParams::THUMB, $currentConfig);

    expect($result)
        ->toBe('i?/foo-th.jpg');
});

test('derivativeUrlPath omits the leading ? when question_mark_in_urls is disabled', function (): void {
    $currentConfig = new CurrentConfig();
    $currentConfig->phpExtensionInUrls = true;
    $currentConfig->questionMarkInUrls = false;

    $result = callDerivativeUrlPath('foo-sq.jpg', ImageStdParams::SQUARE, ImageStdParams::THUMB, $currentConfig);

    expect($result)
        ->toBe('i.php/foo-th.jpg');
});

test('derivativeUrlPath prefixes the app-mount-relative i.php URL, unaware of the app root itself', function (): void {
    $currentConfig = new CurrentConfig();
    $currentConfig->phpExtensionInUrls = true;
    $currentConfig->questionMarkInUrls = true;

    // derivativeUrlPath() itself is deliberately root-agnostic -- the real
    // caller (trySwitchSource()) prefixes its return value with
    // UrlService::getAbsoluteRootUrl(false) separately, the same
    // depth-independent mount-path mechanism parseRequest()'s own
    // $this->srcUrl fix uses for the true-original redirect case.
    $result = callDerivativeUrlPath('upload/2026/08/01/foo-2s.jpg', ImageStdParams::XXSMALL, ImageStdParams::LARGE, $currentConfig);

    expect($result)
        ->toBe('i.php?/upload/2026/08/01/foo-la.jpg');
    expect($result)
        ->not->toContain('..');
});

// parseCustomParams()'s own null-token guard: every real HTTP request path
// guarantees at least 2 tokens remain after the method's first
// array_shift() (its own `count($tokens) < 2` check a few lines up runs
// *before* the 2 further shift()s below), so a null $token there is
// provably unreachable from a real derivative URL -- the method still
// guards it explicitly rather than relying on assert() (a total no-op in
// this environment, zend.assertions=-1). @param string[] $tokens isn't
// enforced by PHP at runtime for a plain array literal, so reflection with
// a deliberately malformed array (a literal null third element, not just
// an absent one) is the only way to exercise this branch at all -- exactly
// the kind of defensive-guard case this method's own docblock describes.
/**
 * @param array<int, string|null> $tokens
 */
function callParseCustomParams(ImageDerivativeController $controller, array $tokens): DerivativeParams
{
    $method = new ReflectionMethod($controller, 'parseCustomParams');

    /** @var DerivativeParams */
    return $method->invoke($controller, $tokens);
}

/**
 * @param array<int, string|null> $tokens
 */
function callIerrorFor400(ImageDerivativeController $controller, array $tokens): ResponseReadyException
{
    try {
        callParseCustomParams($controller, $tokens);
    } catch (ResponseReadyException $e) {
        return $e;
    }
    throw new RuntimeException('parseCustomParams() did not throw for: ' . json_encode($tokens));
}

test('parseCustomParams() rejects a genuinely empty token array', function (): void {
    // Kills line 505's own DecrementInteger mutant (count($tokens) < 0):
    // with zero tokens, a mutated `< 0` never fires, letting a null
    // $token reach `$token[0]` (silently null under PHP 8) and then
    // DerivativeUrlCodec::urlToSize(null), which throws a TypeError
    // instead of the real, controlled 400 -- a real behavioral
    // difference either way.
    $currentLogger = new CurrentLogger();
    $currentLogger->set(new Logger([
        'severity' => Logger::OFF,
    ]));
    $controller = new ImageDerivativeController(Paths::fromRoot(dirname(__DIR__, 3)), $currentLogger, new EventDispatcher(), new ImageStdParams(), imageDerivativeControllerTestImageVisibilityChecker(), UrlServiceTestFactory::build(), new CurrentConfig());

    $exception = callIerrorFor400($controller, []);

    $response = $exception->response();
    expect($response->getStatusCode())
        ->toBe(400)
        ->and((string) $response->getBody())
        ->toBe('Empty array while parsing Sizing');
});

test('parseCustomParams() parses a single bare "s"-prefixed size token', function (): void {
    // Exactly 1 token: real code's own `count($tokens) < 1` guard is
    // false (proceeds), and `$token[0] === 's'` (index 0) picks the
    // dedicated size-only branch. Kills 5 mutants across 2 lines: line
    // 505's own SmallerToSmallerOrEqual/IncrementInteger (either would
    // wrongly re-trigger the "empty array" 400 for this exact 1-token
    // input) and line 513's DecrementInteger/IncrementInteger (reading
    // $token[-1]/$token[1] instead of $token[0] both miss the 's' at
    // index 0 for 's100x100', falling through to the else branch, which
    // then 400s on the resulting empty $tokens -- success vs. a thrown
    // exception is directly observable either way).
    $controller = new ImageDerivativeController(Paths::fromRoot(dirname(__DIR__, 3)), new CurrentLogger(), new EventDispatcher(), new ImageStdParams(), imageDerivativeControllerTestImageVisibilityChecker(), UrlServiceTestFactory::build(), new CurrentConfig());

    $params = callParseCustomParams($controller, ['s100x100']);

    expect($params->sizing->ideal_size)
        ->toBe([100, 100])
        ->and($params->sizing->max_crop)
        ->toBe(0)
        ->and($params->sizing->min_size)
        ->toBeNull();
});

test('parseCustomParams() parses a single bare "e"-prefixed exact-crop token', function (): void {
    // Kills line 515's own DecrementInteger/IncrementInteger the same
    // way the 's' test above kills line 513's: $token[-1]/$token[1]
    // for 'e50x50' both miss the 'e' at index 0, falling through to the
    // else branch and 400ing instead of succeeding.
    $controller = new ImageDerivativeController(Paths::fromRoot(dirname(__DIR__, 3)), new CurrentLogger(), new EventDispatcher(), new ImageStdParams(), imageDerivativeControllerTestImageVisibilityChecker(), UrlServiceTestFactory::build(), new CurrentConfig());

    $params = callParseCustomParams($controller, ['e50x50']);

    expect($params->sizing->ideal_size)
        ->toBe([50, 50])
        ->and($params->sizing->max_crop)
        ->toBe(1)
        ->and($params->sizing->min_size)
        ->toBe([50, 50]);
});

test('parseCustomParams() rejects a plain size token with no crop/min-size tokens following it', function (): void {
    // The general (no 's'/'e' prefix) branch, with exactly 1 remaining
    // token after the first shift -- real code's own `count($tokens) <
    // 2` guard (line 520) fires immediately here. Two mutants on this
    // exact guard are confirmed-equivalent (both verified live via a
    // temporary sed-applied mutation, not chased further): a
    // DecrementInteger (`< 1`) on the condition itself, and a
    // RemoveMethodCall on the `ierror()` call inside the if-body --
    // neither fires/executes at this exact count, but both fall through
    // to the pre-existing null-token guard a few lines down, which
    // throws the *identical* ResponseReadyException(400, 'Sizing arr').
    $currentLogger = new CurrentLogger();
    $currentLogger->set(new Logger([
        'severity' => Logger::OFF,
    ]));
    $controller = new ImageDerivativeController(Paths::fromRoot(dirname(__DIR__, 3)), $currentLogger, new EventDispatcher(), new ImageStdParams(), imageDerivativeControllerTestImageVisibilityChecker(), UrlServiceTestFactory::build(), new CurrentConfig());

    $exception = callIerrorFor400($controller, ['100x100', 'a']);

    $response = $exception->response();
    expect($response->getStatusCode())
        ->toBe(400)
        ->and((string) $response->getBody())
        ->toBe('Sizing arr');
});

test('parseCustomParams() 400s a plain size token that is the only token given, instead of falling through to a TypeError', function (): void {
    // 1 total token (no 's'/'e' prefix), so 0 tokens remain after the
    // first shift -- unlike the 2-total-token case above, line 497's
    // RemoveMethodCall mutant is NOT equivalent here: with the
    // ierror('Sizing arr', 400) call removed, execution falls through to
    // array_shift() on an already-empty array (null), then
    // DerivativeUrlCodec::charToFraction(null), which throws an uncaught
    // TypeError under strict_types instead of the controlled 400 response
    // -- a real, directly observable difference (handled error response
    // vs. an uncaught fatal).
    $currentLogger = new CurrentLogger();
    $currentLogger->set(new Logger([
        'severity' => Logger::OFF,
    ]));
    $controller = new ImageDerivativeController(Paths::fromRoot(dirname(__DIR__, 3)), $currentLogger, new EventDispatcher(), new ImageStdParams(), imageDerivativeControllerTestImageVisibilityChecker(), UrlServiceTestFactory::build(), new CurrentConfig());

    $exception = callIerrorFor400($controller, ['100x100']);

    $response = $exception->response();
    expect($response->getStatusCode())
        ->toBe(400)
        ->and((string) $response->getBody())
        ->toBe('Sizing arr');
});

test('parseCustomParams() accepts a size+crop+min-size token triple, exactly at the 2-remaining-tokens boundary', function (): void {
    // 3 total tokens -> exactly 2 remain after the first shift, the
    // real code's own boundary for "just enough" (`count($tokens) < 2`
    // is false only right at this count). Kills line 520's IfNegated,
    // SmallerToGreaterOrEqual, SmallerToSmallerOrEqual, and
    // IncrementInteger mutants -- each would wrongly 400 this exact
    // input instead of succeeding.
    $controller = new ImageDerivativeController(Paths::fromRoot(dirname(__DIR__, 3)), new CurrentLogger(), new EventDispatcher(), new ImageStdParams(), imageDerivativeControllerTestImageVisibilityChecker(), UrlServiceTestFactory::build(), new CurrentConfig());

    $params = callParseCustomParams($controller, ['100x100', 'n', '50x50']);

    expect($params->sizing->ideal_size)
        ->toBe([100, 100])
        ->and($params->sizing->max_crop)
        ->toBe(DerivativeUrlCodec::charToFraction('n'))
        ->and($params->sizing->min_size)
        ->toBe([50, 50]);
});

test('parseCustomParams() takes the min-size token from the front of the remaining tokens, not the back', function (): void {
    // A 4th, unconsumed trailing token distinguishes array_shift()
    // (real: takes '50x50', the front of the 2 remaining tokens) from
    // a mutated array_pop() (would take the trailing 'ignored-extra'
    // instead) -- with only exactly 2 remaining tokens (the test
    // above), shift and pop are indistinguishable (only one real
    // candidate either way).
    $controller = new ImageDerivativeController(Paths::fromRoot(dirname(__DIR__, 3)), new CurrentLogger(), new EventDispatcher(), new ImageStdParams(), imageDerivativeControllerTestImageVisibilityChecker(), UrlServiceTestFactory::build(), new CurrentConfig());

    $params = callParseCustomParams($controller, ['100x100', 'n', '50x50', 'ignored-extra']);

    expect($params->sizing->min_size)
        ->toBe([50, 50]);
});

/**
 * @param array<string, string> $extraHeaders
 */
function callIerror(ImageDerivativeController $controller, string $msg, int $code, array $extraHeaders = []): ResponseReadyException
{
    $method = new ReflectionMethod($controller, 'ierror');
    try {
        $method->invoke($controller, $msg, $code, $extraHeaders);
    } catch (ResponseReadyException $e) {
        return $e;
    }
    throw new RuntimeException('ierror() did not throw');
}

test('ierror() builds a real redirect response for a 301 code, decoding entities, logging, and applying extra headers', function (): void {
    // Kills line 470's BooleanOrToBooleanAnd (301 && 302 is never true)
    // and the DecrementInteger/IncrementInteger mutants on the literal
    // 301 (300/302 would both miss this exact code) -- any of them
    // would route this call into the text-error branch instead, whose
    // response shape (Content-Type: text/plain, no Location header) is
    // directly distinguishable from a real redirect. Also covers every
    // other mutant reachable only from inside this same redirect branch:
    // line 472's UnwrapHtmlEntityDecode (a real &amp; -> & decode, only
    // observable with an entity actually present in $msg), line 473's
    // ConcatRemoveLeft/Right/SwitchSides + RemoveMethodCall and line
    // 474's CoalesceRemoveLeft/RemoveArrayItem on the debug() log call
    // (same shape as error()'s own log call, asserted the same way: a
    // real file-backed Logger + a real, distinct REQUEST_URI), and line
    // 481's ForeachEmptyIterable on the $extraHeaders loop (a non-empty
    // $extraHeaders here, asserted present on the response).
    $dir = sys_get_temp_dir() . '/piwigo-idc-ierror-redirect-test-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o777, true);
    $logger = new Logger([
        'directory' => $dir,
        'filename' => 'ierror-redirect.txt',
    ]);
    $currentLogger = new CurrentLogger();
    $currentLogger->set($logger);

    $originalRequestUri = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_URI'] = '/i.php/upload/2026/08/01/bar-th.jpg';

    $controller = new ImageDerivativeController(Paths::fromRoot(dirname(__DIR__, 3)), $currentLogger, new EventDispatcher(), new ImageStdParams(), imageDerivativeControllerTestImageVisibilityChecker(), UrlServiceTestFactory::build(), new CurrentConfig());

    $exception = callIerror(
        $controller,
        'https://example.test/target?a=1&amp;b=2',
        301,
        [
            'X-i' => 'No change',
        ],
    );

    $contents = file_get_contents($dir . '/ierror-redirect.txt');

    if ($originalRequestUri === null) {
        unset($_SERVER['REQUEST_URI']);
    } else {
        $_SERVER['REQUEST_URI'] = $originalRequestUri;
    }
    @unlink($dir . '/ierror-redirect.txt');
    @rmdir($dir);

    $response = $exception->response();
    expect($response->getStatusCode())
        ->toBe(301)
        ->and($response->getHeaderLine('Location'))
        ->toBe('https://example.test/target?a=1&b=2')
        ->and($response->getHeaderLine('X-i'))
        ->toBe('No change');

    expect($contents)
        ->not->toBeFalse();
    assert(is_string($contents));
    expect($contents)
        ->toContain('301 https://example.test/target?a=1&b=2')
        ->and($contents)
        ->toContain('/i.php/upload/2026/08/01/bar-th.jpg');
});

test('ierror() builds a real redirect response for a 302 code too', function (): void {
    // Kills the DecrementInteger/IncrementInteger mutants on the
    // literal 302 specifically -- the 301 test above can't (a code=301
    // call takes the redirect branch regardless of how the *302*
    // literal alone is mutated).
    $currentLogger = new CurrentLogger();
    $currentLogger->set(new Logger([
        'severity' => Logger::OFF,
    ]));
    $controller = new ImageDerivativeController(Paths::fromRoot(dirname(__DIR__, 3)), $currentLogger, new EventDispatcher(), new ImageStdParams(), imageDerivativeControllerTestImageVisibilityChecker(), UrlServiceTestFactory::build(), new CurrentConfig());

    $exception = callIerror($controller, 'https://example.test/target-302', 302);

    $response = $exception->response();
    expect($response->getStatusCode())
        ->toBe(302)
        ->and($response->getHeaderLine('Location'))
        ->toBe('https://example.test/target-302');
});

test('ierror() logs the exact code+message concatenation and the real request URI for a non-redirect code', function (): void {
    // A real, file-backed Logger (matching LoggerTest.php's own
    // sys_get_temp_dir() convention) so the logged line can be asserted
    // on directly -- kills every ConcatRemoveLeft/ConcatRemoveRight/
    // ConcatSwitchSides mutant on `$code . ' ' . $msg` (any of them
    // changes this exact substring), the RemoveMethodCall mutant on the
    // whole $logger->error(...) call (nothing would be logged at all),
    // the CoalesceRemoveLeft mutant on `$_SERVER['REQUEST_URI'] ?? null`
    // (a real, distinct REQUEST_URI is set below and must appear in the
    // logged context), and the RemoveArrayItem mutant on the single
    // 'url' context entry (an empty context array skips the whole
    // indented context block in Logger::formatMessage(), so the request
    // URI would be silently missing from the log either way).
    $dir = sys_get_temp_dir() . '/piwigo-idc-ierror-test-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o777, true);
    $logger = new Logger([
        'directory' => $dir,
        'filename' => 'ierror.txt',
    ]);
    $currentLogger = new CurrentLogger();
    $currentLogger->set($logger);

    $originalRequestUri = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_URI'] = '/i.php/upload/2026/08/01/foo-th.jpg';

    $controller = new ImageDerivativeController(Paths::fromRoot(dirname(__DIR__, 3)), $currentLogger, new EventDispatcher(), new ImageStdParams(), imageDerivativeControllerTestImageVisibilityChecker(), UrlServiceTestFactory::build(), new CurrentConfig());
    $exception = callIerror($controller, 'Db file path not found', 404);

    $contents = file_get_contents($dir . '/ierror.txt');

    if ($originalRequestUri === null) {
        unset($_SERVER['REQUEST_URI']);
    } else {
        $_SERVER['REQUEST_URI'] = $originalRequestUri;
    }
    @unlink($dir . '/ierror.txt');
    @rmdir($dir);

    expect($exception->response()->getStatusCode())
        ->toBe(404);
    expect($contents)
        ->not->toBeFalse();
    assert(is_string($contents));
    expect($contents)
        ->toContain('404 Db file path not found')
        ->and($contents)
        ->toContain('/i.php/upload/2026/08/01/foo-th.jpg');
});

test('parseCustomParams() 400s its own "impossible" null-token guard when invoked with a malformed token array', function (): void {
    $currentLogger = new CurrentLogger();
    $currentLogger->set(new Logger([
        'severity' => Logger::OFF,
    ]));

    $controller = new ImageDerivativeController(Paths::fromRoot(dirname(__DIR__, 3)), $currentLogger, new EventDispatcher(), new ImageStdParams(), imageDerivativeControllerTestImageVisibilityChecker(), UrlServiceTestFactory::build(), new CurrentConfig());
    $method = new ReflectionMethod(ImageDerivativeController::class, 'parseCustomParams');

    $exception = null;
    try {
        // '150x100' has no leading 's'/'e', forcing the 3-token branch;
        // 'a' is a valid crop-fraction token; the literal null in the 3rd
        // slot stands in for what only a non-HTTP caller could ever
        // produce.
        $method->invoke($controller, ['150x100', 'a', null]);
    } catch (ResponseReadyException $e) {
        $exception = $e;
    }

    expect($exception)
        ->toBeInstanceOf(ResponseReadyException::class);
    if (! $exception instanceof ResponseReadyException) {
        return; // unreachable -- the assertion above already failed the test otherwise.
    }
    $response = $exception->response();
    expect($response->getStatusCode())
        ->toBe(400)
        ->and((string) $response->getBody())
        ->toBe('Sizing arr');
});
