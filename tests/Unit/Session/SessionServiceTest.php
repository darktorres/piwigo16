<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\Kernel;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\SessionServiceTestFactory;

// Doctrine ORM EntityManagers/repositories are lazy -- they don't actually
// connect until the first query runs. Every test below only exercises
// SessionService methods that never touch the repository's DB-backed
// methods, so a real SessionRepository/EntityManager pair can be
// constructed here without a live database (confirmed empirically: an
// unreachable db_host never triggers a connection attempt for these
// specific call paths).
// Optional $currentConfig lets a test build (and keep a reference to) its
// own CurrentConfig instance *before* handing it to the SessionService
// under test -- SessionService is constructor-injected with this
// collaborator (not the CurrentConfigTestFactory::get() bridge), so a test that
// needs to control e.g. sessionUseIpAddress() must set it on the exact
// same instance the service reads, not on some unrelated bridge-resolved
// object.
function makeSessionService(?CurrentConfig $currentConfig = null): SessionService
{
    CurrentConfigTestFactory::get()->reset();
    ConfigLoader::applyDefaults();
    putenv('PIWIGO_DB_HOST=unit-test-should-never-connect.invalid');

    return new SessionService(EntityManagerFactory::build()->getRepository(SessionEntity::class), $currentConfig ?? new CurrentConfig());
}

// tests/bootstrap.php loads real PIWIGO_DB_* vars for the whole Pest
// process -- save + restore PIWIGO_DB_HOST specifically, since
// makeSessionService() above overwrites the real one with a deliberately
// unreachable host and DbCredentials::current() (a pure shim now, Phase 3,
// with no independent state of its own to un-poison -- it re-derives from
// the process env fresh every call in this file, since Kernel never boots
// here) has no other mechanism to un-poison it for tests running later in
// the same process.
$originalDbHost = null;

beforeEach(function () use (&$originalDbHost): void {
    $value = getenv('PIWIGO_DB_HOST');
    $originalDbHost = $value === false ? null : $value;
    unset($_SESSION);
});

afterEach(function () use (&$originalDbHost): void {
    putenv($originalDbHost === null ? 'PIWIGO_DB_HOST' : 'PIWIGO_DB_HOST=' . $originalDbHost);
    // Harmless for every other test in this file, which never calls
    // Kernel::boot() at all -- Kernel::reset() is a safe no-op regardless.
    Kernel::reset();
});

test('generateKey returns a string of the requested length', function (): void {
    $service = makeSessionService();

    expect(strlen($service->generateKey(20)))->toBe(20)
        ->and(strlen($service->generateKey(5)))->toBe(5);
});

test('generateKey throws for a size below 1', function (): void {
    $service = makeSessionService();

    $service->generateKey(0);
})->throws(InvalidArgumentException::class);

test('generateKey does not throw for a size of exactly 1', function (): void {
    // Distinguishes `$size < 1` from `$size <= 1` and from `$size < 2`:
    // both mutants would make size=1 throw, but 1 is the smallest legal
    // size and must succeed, returning exactly a 1-character key.
    $service = makeSessionService();

    expect(strlen($service->generateKey(1)))->toBe(1);
});

test('generateKey never contains + or /', function (): void {
    $service = makeSessionService();

    for ($i = 0; $i < 20; $i++) {
        $key = $service->generateKey(32);
        expect($key)->not->toContain('+')->not->toContain('/');
    }
});

// Documented equivalence (mutation sweep, pest --mutate): the `0` start
// offset in `substr(..., 0, $size)` (generateKey()'s final line) cannot be
// distinguished from a mutated `1` offset by any output-based test, and
// random_bytes() isn't injectable here to pin the underlying bytes.
// generateKey() always pulls $size+10 random bytes before base64-encoding
// and stripping '+'/'/', so the pre-substr string is always far longer
// than $size (the +10 bytes alone contribute ~13 extra base64 characters
// before any stripping) -- shifting the start index by 1 only changes
// *which* random characters land in the result, never the returned
// length or charset, both of which are the only properties a black-box
// test can assert without controlling the RNG. Empirically confirmed
// with a 200,000-iteration size=1 stress run (the worst-case smallest
// buffer margin) against a live sed-mutated copy of the source
// (`substr(..., 0, $size)` -> `substr(..., 1, $size)`): every call still
// returned exactly a 1-character string, 0 mismatches. Also reran this
// entire test file against that same mutated copy: all tests passed
// (mutant survives), confirming no assertion in this file -- nor any
// conceivable length/charset assertion -- can observe the offset change.

test('sessionOpen and sessionClose always return true', function (): void {
    $service = makeSessionService();

    expect($service->sessionOpen())->toBeTrue()
        ->and($service->sessionClose())->toBeTrue();
});

test('getRemoteAddrSessionHash returns empty string when session_use_ip_address is off', function (): void {
    $currentConfig = new CurrentConfig();
    $currentConfig->setSessionUseIpAddress(false);
    $service = makeSessionService($currentConfig);

    expect($service->getRemoteAddrSessionHash())->toBe('');
});

test('getRemoteAddrSessionHash hashes only the first two octets of an ipv4 REMOTE_ADDR when enabled', function (): void {
    // '%02X%02X' against a 4-element explode() only consumes the first two
    // octets -- this is the original get_remote_addr_session_hash()'s real,
    // long-standing behavior (also present unchanged in the reference
    // implementation), not something this migration should silently widen.
    $currentConfig = new CurrentConfig();
    $currentConfig->setSessionUseIpAddress(true);
    $service = makeSessionService($currentConfig);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    expect($service->getRemoteAddrSessionHash())->toBe('7F00');
});

test('getRemoteAddrSessionHash returns empty string for an ipv6 REMOTE_ADDR', function (): void {
    $currentConfig = new CurrentConfig();
    $currentConfig->setSessionUseIpAddress(true);
    $service = makeSessionService($currentConfig);
    $_SERVER['REMOTE_ADDR'] = '::1';

    expect($service->getRemoteAddrSessionHash())->toBe('');
});

test('getRemoteAddrSessionHash returns empty string instead of throwing when REMOTE_ADDR is unset', function (): void {
    // Real bug, found via a new Integration test that legitimately creates
    // a session outside a real HTTP request (a CLI-driven install/
    // bootstrap flow, with no REMOTE_ADDR at all): IpAddress::fromRemoteAddr()
    // returns null, ->value ?? '' resolves to '', and explode('.', '')
    // used to be handed straight to vsprintf('%02X%02X', ...), which
    // throws a ValueError for a 1-element array instead of falling back
    // to the same "no real IP" empty-string result this method already
    // returns for ipv6.
    unset($_SERVER['REMOTE_ADDR']);
    $currentConfig = new CurrentConfig();
    $currentConfig->setSessionUseIpAddress(true);
    $service = makeSessionService($currentConfig);

    expect($service->getRemoteAddrSessionHash())->toBe('');
});

test('remoteAddrHash returns empty string immediately when useIpAddress is false, without falling through to REMOTE_ADDR parsing', function (): void {
    // Proves `if (! $useIpAddress) { return ''; }` is a genuine early
    // return, not dead code: REMOTE_ADDR is deliberately set to a valid
    // IPv4 that WOULD hash to a non-empty value ('7F00') if execution fell
    // through to the IP-parsing logic below instead of returning early.
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    expect(SessionService::remoteAddrHash(false))->toBe('');
});

test('remoteAddrHash returns empty string when REMOTE_ADDR is unset, called directly', function (): void {
    // Same "no crash on a missing REMOTE_ADDR" scenario as
    // getRemoteAddrSessionHash's own unset-REMOTE_ADDR test above, but
    // exercised directly against the static method (IpAddress::fromRemoteAddr()
    // returns null here, so ->value ?? '' resolves via the null-coalesce
    // fallback rather than a real IpAddress::value).
    unset($_SERVER['REMOTE_ADDR']);

    expect(SessionService::remoteAddrHash(true))->toBe('');
});

// Documented equivalence (mutation sweep, pest --mutate): the `''` on
// `IpAddress::fromRemoteAddr()->value ?? ''` cannot be killed by any test
// in this file, including the two immediately above. Whenever that
// null-coalesce actually fires (fromRemoteAddr() returned null --
// REMOTE_ADDR unset/non-string/invalid), $remoteAddr only ever reaches
// the final `return '';` fallback below it, because the IPv4 branch
// requires `count(explode('.', $remoteAddr)) === 4`; Pest's
// EmptyStringToNotEmpty mutator always substitutes the fixed literal
// 'PEST Mutator was here!' (no dots), which also fails that same count
// check and falls through to the identical `return '';`. So no output
// can differ between '' and the mutant string for *any* mutator-supplied
// replacement lacking exactly 3 dots. Confirmed live: sed-mutating
// `?? ''` to `?? 'PEST Mutator was here!'` and rerunning this entire
// file (all tests above included) leaves all assertions green -- the
// mutant survives regardless.

test('remoteAddrHash ignores the 3rd and 4th octets of an ipv4 REMOTE_ADDR', function (): void {
    // array_slice($octets, 0, 2) means only the first two octets ever
    // reach vsprintf() -- confirmed here by comparing two addresses that
    // share the first two octets but differ in the last two: they must
    // hash identically, proving octets 3 and 4 never leak into the result.
    $_SERVER['REMOTE_ADDR'] = '10.20.30.40';
    $hashA = SessionService::remoteAddrHash(true);

    $_SERVER['REMOTE_ADDR'] = '10.20.99.99';
    $hashB = SessionService::remoteAddrHash(true);

    // This test kills the IncrementInteger mutant that shifts
    // array_slice()'s *offset* (0 -> 1, using octets[1..2] instead of
    // octets[0..1]) -- confirmed live via sed-mutate-and-rerun: with this
    // test in place plus the pre-existing 127.0.0.1 test above, that
    // mutant fails 2 assertions.
    //
    // Documented equivalence for the other two Line 135 mutants (also
    // confirmed live via sed-mutate-and-rerun, all tests green under
    // each): array_slice()'s *length* (2 -> 3, IncrementInteger) and
    // unwrapping array_slice() entirely (UnwrapArraySlice, using the
    // full 4-element $octets). Both are unkillable by design: this line
    // only runs when `count($octets) === 4` (the guard just above), and
    // PHP's vsprintf('%02X%02X', ...) silently ignores array elements
    // beyond what '%02X%02X' consumes (verified: vsprintf('%02X%02X',
    // [10,20,30,40]) === vsprintf('%02X%02X', [10,20,30]) ===
    // vsprintf('%02X%02X', [10,20]) === '0A14') -- so whether the 3rd/4th
    // octet is included in the slice (length 3, or no slice at all) can
    // never change the formatted output. Only shifting the *offset*
    // (this test's mutant) changes which octets get consumed.

    expect($hashA)->toBe('0A14')
        ->and($hashB)->toBe('0A14')
        ->and($hashA)->toBe($hashB);
});

test('setSessionVar/getSessionVar/unsetSessionVar round-trip through $_SESSION', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->setSessionVar('foo', 'bar'))->toBeTrue()
        ->and($service->getSessionVar('foo'))->toBe('bar');

    expect($service->unsetSessionVar('foo'))->toBeTrue()
        ->and($service->getSessionVar('foo', 'gone'))->toBe('gone');
});

test('setSessionVar/unsetSessionVar return false when no session is active', function (): void {
    $service = makeSessionService();
    unset($_SESSION);

    expect($service->setSessionVar('foo', 'bar'))->toBeFalse()
        ->and($service->unsetSessionVar('foo'))->toBeFalse();
});

test('getSessionVar returns the default when unset', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->getSessionVar('missing', 'fallback'))->toBe('fallback');
});

test('sessionWrite short-circuits to true without touching the repository when the request is api_key-authenticated', function (): void {
    // Same "never touches the repository's DB-backed methods" shape as
    // this file's own header comment: if this short-circuit branch
    // (SessionService's own private lazy apiKeyRequestFlag() helper,
    // reading the same container-shared instance seeded below -- Phase 11
    // sub-phase 11G) were broken and control fell through to
    // $this->repo->write(), that would attempt a real connection to the
    // deliberately unreachable PIWIGO_DB_HOST set up by
    // makeSessionService() and this test would error out instead of
    // passing -- so a clean `true` result here is itself proof the
    // repository was never reached.
    $service = makeSessionService();
    Kernel::boot();
    $flag = Kernel::container()->get(ApiKeyRequestFlag::class);
    expect($flag)->toBeInstanceOf(ApiKeyRequestFlag::class);
    $flag->activate();

    expect($service->sessionWrite('some-session-id', 'some-data'))->toBeTrue();
});

test('SessionServiceTestFactory::get returns a fresh read on every call when Kernel is not booted', function (): void {
    $first = SessionServiceTestFactory::get();
    $second = SessionServiceTestFactory::get();

    expect($first)->not->toBe($second);
});

test('SessionServiceTestFactory::get returns the same container-shared instance across calls once Kernel is booted', function (): void {
    makeSessionService();
    Kernel::boot();

    $first = SessionServiceTestFactory::get();
    $second = SessionServiceTestFactory::get();

    expect($first)->toBe($second);
});
