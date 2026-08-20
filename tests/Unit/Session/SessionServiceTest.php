<?php

declare(strict_types=1);

use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\Kernel;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Session\Projection\FilterCheckKey;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\KernelContainerOverride;
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
// unreachable host and DbCredentials::current() (a pure shim with no
// independent state of its own to un-poison -- it re-derives from the
// process env fresh every call in this file, since Kernel never boots
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

    expect(strlen($service->generateKey(20)))
        ->toBe(20)
        ->and(strlen($service->generateKey(5)))
        ->toBe(5);
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

    expect(strlen($service->generateKey(1)))
        ->toBe(1);
});

test('generateKey never contains + or /', function (): void {
    $service = makeSessionService();

    for ($i = 0; $i < 20; $i++) {
        $key = $service->generateKey(32);
        expect($key)
            ->not->toContain('+')
            ->not->toContain('/');
    }
});

test('generateKey only ever contains alphanumeric characters', function (): void {
    // Kills line 55's EmptyStringToNotEmpty (str_replace(['+', '/'], '',
    // ...) -> str_replace(['+', '/'], 'PEST Mutator was here!', ...)):
    // that mutant never leaves a literal '+' or '/' in the output either
    // (its replacement text contains neither character), so the sibling
    // "never contains + or /" test above cannot distinguish it -- but the
    // replacement text itself ("PEST Mutator was here!") introduces
    // spaces and an '!', which fall outside this method's documented
    // charset ("Characters used are a-z A-Z and numerical values").
    // random_bytes() isn't injectable, so this can't be forced
    // deterministically, but with 100 iterations at size=64 (pre-substr
    // string length 4*ceil(74/3) = 100 base64 characters, each with a
    // ~3.1% independent chance of being '+' or '/'), the chance that
    // every single iteration happens to produce zero '+'/'/' occurrences
    // at all (the only way the mutant's replacement is never triggered)
    // is astronomically small: (1 - (62/64)^100)^100 is indistinguishable
    // from 1.
    $service = makeSessionService();

    for ($i = 0; $i < 100; $i++) {
        $key = $service->generateKey(64);
        expect(ctype_alnum($key))
            ->toBeTrue();
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

    // Both methods are declared to return the literal type `true`, so
    // PHPStan can already prove these calls return true -- but that
    // literal return type is itself the SessionHandlerInterface contract
    // this test guards; loosening either signature back to plain `bool`
    // would make this assertion meaningful again.
    // @phpstan-ignore pest.expectation.redundant
    expect($service->sessionOpen())
        ->toBeTrue()
        ->and($service->sessionClose())
        ->toBeTrue();
});

test('getRemoteAddrSessionHash returns empty string when session_use_ip_address is off', function (): void {
    $currentConfig = new CurrentConfig();
    $currentConfig->sessionUseIpAddress = false;
    $service = makeSessionService($currentConfig);

    expect($service->getRemoteAddrSessionHash())
        ->toBe('');
});

test('getRemoteAddrSessionHash hashes only the first two octets of an ipv4 REMOTE_ADDR when enabled', function (): void {
    // '%02X%02X' against a 4-element explode() only consumes the first two
    // octets -- this is the original get_remote_addr_session_hash()'s real,
    // long-standing behavior (also present unchanged in the reference
    // implementation), not something this migration should silently widen.
    $currentConfig = new CurrentConfig();
    $currentConfig->sessionUseIpAddress = true;
    $service = makeSessionService($currentConfig);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    expect($service->getRemoteAddrSessionHash())
        ->toBe('7F00');
});

test('getRemoteAddrSessionHash returns empty string for an ipv6 REMOTE_ADDR', function (): void {
    $currentConfig = new CurrentConfig();
    $currentConfig->sessionUseIpAddress = true;
    $service = makeSessionService($currentConfig);
    $_SERVER['REMOTE_ADDR'] = '::1';

    expect($service->getRemoteAddrSessionHash())
        ->toBe('');
});

test('getRemoteAddrSessionHash returns empty string instead of throwing when REMOTE_ADDR is unset', function (): void {
    // A session created outside a real HTTP request (a CLI-driven install/
    // bootstrap flow, with no REMOTE_ADDR at all) hits IpAddress::
    // fromRemoteAddr() returning null: ->value ?? '' resolves to '', and
    // explode('.', '') yields a 1-element array, which fails the
    // 4-element ipv4 check and falls back to the same "no real IP"
    // empty-string result this method already returns for ipv6, rather
    // than throwing a ValueError from vsprintf('%02X%02X', ...).
    unset($_SERVER['REMOTE_ADDR']);
    $currentConfig = new CurrentConfig();
    $currentConfig->sessionUseIpAddress = true;
    $service = makeSessionService($currentConfig);

    expect($service->getRemoteAddrSessionHash())
        ->toBe('');
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

    expect($hashA)
        ->toBe('0A14')
        ->and($hashB)
        ->toBe('0A14')
        ->and($hashA)
        ->toBe($hashB);
});

test('setSessionVar/unsetSessionVar round-trip through $_SESSION', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->setSessionVar('foo', 'bar'))
        ->toBeTrue()
        ->and($_SESSION['pwg_foo'] ?? null)->toBe('bar');

    expect($service->unsetSessionVar('foo'))
        ->toBeTrue()
        ->and(isset($_SESSION['pwg_foo']))->toBeFalse();
});

test('setSessionVar/unsetSessionVar return false when no session is active', function (): void {
    $service = makeSessionService();
    unset($_SESSION);

    expect($service->setSessionVar('foo', 'bar'))
        ->toBeFalse()
        ->and($service->unsetSessionVar('foo'))
        ->toBeFalse();
});

test('getSessionVar reads back a value written by setSessionVar', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    $service->setSessionVar('foo', 'bar');

    expect($service->getSessionVar('foo'))
        ->toBe('bar');
});

test('getSessionVar returns null for a key that was never set', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->getSessionVar('never_set'))
        ->toBeNull();
});

test('getSessionVar returns null when no session is active', function (): void {
    $service = makeSessionService();
    unset($_SESSION);

    expect($service->getSessionVar('foo'))
        ->toBeNull();
});

test('sessionWrite short-circuits to true without touching the repository when the request is api_key-authenticated', function (): void {
    // Same "never touches the repository's DB-backed methods" shape as
    // this file's own header comment: if this short-circuit branch
    // (SessionService's own private lazy apiKeyRequestFlag() helper,
    // reading the same container-shared instance seeded below) were
    // broken and control fell through to $this->repo->write(), that would
    // attempt a real connection to the deliberately unreachable
    // PIWIGO_DB_HOST set up by makeSessionService() and this test would
    // error out instead of passing -- so a clean `true` result here is
    // itself proof the repository was never reached.
    $service = makeSessionService();
    Kernel::boot();
    $flag = Kernel::container()->get(ApiKeyRequestFlag::class);
    if (! $flag instanceof ApiKeyRequestFlag) {
        throw new LogicException('Container returned an unexpected type for ' . ApiKeyRequestFlag::class);
    }
    $flag->activate();

    // sessionWrite()'s literal `true` return type makes this assertion
    // provably redundant to PHPStan -- but per this test's own docblock
    // above, reaching this line at all (rather than erroring out on an
    // unreachable DB connection) is the real thing under test.
    // @phpstan-ignore pest.expectation.redundant
    expect($service->sessionWrite('some-session-id', 'some-data'))
        ->toBeTrue();
});

test('sessionWrite throws when the container returns an unexpected type for ApiKeyRequestFlag', function (): void {
    // Kills line 33's InstanceOfToTrue (`!true` instead of
    // `!$apiKeyRequestFlag instanceof ApiKeyRequestFlag`) in the private
    // apiKeyRequestFlag() helper: the mutant's guard can never fire
    // regardless of what the container actually resolved, silently
    // returning the wrong-typed value instead of throwing. The real
    // container, correctly wired, never legitimately resolves
    // ApiKeyRequestFlag::class to anything but an ApiKeyRequestFlag --
    // this branch is otherwise unreachable through the public API.
    // KernelContainerOverride rebinds ApiKeyRequestFlag::class to a plain
    // stdClass (see its own docblock), matching the same pattern used
    // throughout tests/Unit/Bootstrap/*AccessorTest.php and
    // tests/Unit/Core/LoggerTest.php's pageState() test for the identical
    // guard shape.
    $service = makeSessionService();

    KernelContainerOverride::withWrongTypeFor(
        ApiKeyRequestFlag::class,
        function () use ($service): void {
            $service->sessionWrite('some-session-id', 'some-data');
        }
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ApiKeyRequestFlag::class);

test('SessionServiceTestFactory::get returns a fresh read on every call when Kernel is not booted', function (): void {
    $first = SessionServiceTestFactory::get();
    $second = SessionServiceTestFactory::get();

    expect($first)
        ->not->toBe($second);
});

test('SessionServiceTestFactory::get returns the same container-shared instance across calls once Kernel is booted', function (): void {
    makeSessionService();
    Kernel::boot();

    $first = SessionServiceTestFactory::get();
    $second = SessionServiceTestFactory::get();

    expect($first)
        ->toBe($second);
});

test('getFilterEnabled defaults to false when unset and casts a truthy stored value to true', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->getFilterEnabled())
        ->toBeFalse();

    $_SESSION['pwg_filter_enabled'] = 1;
    expect($service->getFilterEnabled())
        ->toBeTrue();

    $_SESSION['pwg_filter_enabled'] = false;
    expect($service->getFilterEnabled())
        ->toBeFalse();
});

test('getFilterCheckKey returns null when unset or missing a required key, and the exact shape when complete', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->getFilterCheckKey())
        ->toBeNull();

    $_SESSION['pwg_filter_check_key'] = [
        'user' => 1,
        'recent_period' => 2,
        'time' => 3,
    ];
    expect($service->getFilterCheckKey())
        ->toBeNull();

    $_SESSION['pwg_filter_check_key'] = [
        'user' => 1,
        'recent_period' => 2,
        'time' => 3,
        'date' => '20260101',
    ];
    expect($service->getFilterCheckKey())
        ->toEqual(new FilterCheckKey(user: 1, recentPeriod: 2, time: 3, date: '20260101'));
});

test('getFilterCheckKey coerces a wrong-typed field to FilterCheckKey::fromArray()s own safe fallback, still returning a non-null instance', function (): void {
    // All 4 keys are present (isset() passes), but 'date' holds a non-string
    // value -- FilterCheckKey::fromArray() coerces it to '' rather than
    // rejecting the whole value, matching FilterService's own downstream
    // is_string()-else-'' fallback it used to duplicate at its own read
    // site before this class existed.
    $service = makeSessionService();
    $_SESSION = [];
    $_SESSION['pwg_filter_check_key'] = [
        'user' => 1,
        'recent_period' => 2,
        'time' => 3,
        'date' => 4,
    ];

    expect($service->getFilterCheckKey())
        ->toEqual(new FilterCheckKey(user: 1, recentPeriod: 2, time: 3, date: ''));
});

test('getFilterCategoriesSerialized returns null when unset or non-string, and the string when set', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->getFilterCategoriesSerialized())
        ->toBeNull();

    $_SESSION['pwg_filter_categories'] = 123;
    expect($service->getFilterCategoriesSerialized())
        ->toBeNull();

    $_SESSION['pwg_filter_categories'] = 'a:1:{i:0;i:5;}';
    expect($service->getFilterCategoriesSerialized())
        ->toBe('a:1:{i:0;i:5;}');
});

test('getFilterVisibleCategories preserves the -1 sentinel and a real CSV string, and returns null when unset', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->getFilterVisibleCategories())
        ->toBeNull();

    $_SESSION['pwg_filter_visible_categories'] = -1;
    expect($service->getFilterVisibleCategories())
        ->toBe('-1');

    $_SESSION['pwg_filter_visible_categories'] = '1,2,3';
    expect($service->getFilterVisibleCategories())
        ->toBe('1,2,3');
});

test('getFilterVisibleImages preserves the -1 sentinel and a real CSV string, and returns null when unset', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->getFilterVisibleImages())
        ->toBeNull();

    $_SESSION['pwg_filter_visible_images'] = -1;
    expect($service->getFilterVisibleImages())
        ->toBe('-1');

    $_SESSION['pwg_filter_visible_images'] = '4,5,6';
    expect($service->getFilterVisibleImages())
        ->toBe('4,5,6');
});

test('getDeviceVar returns null when unset or non-string, and the string when set', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->getDeviceVar())
        ->toBeNull();

    $_SESSION['pwg_device'] = 123;
    expect($service->getDeviceVar())
        ->toBeNull();

    $_SESSION['pwg_device'] = 'mobile';
    expect($service->getDeviceVar())
        ->toBe('mobile');
});

test('getMobileThemeVar returns null for an unset or non-bool value, and the strict bool when set', function (): void {
    // is_bool() strict narrowing, not a (bool) cast: a stored non-bool
    // (e.g. the int 1) must come back null, not true.
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->getMobileThemeVar())
        ->toBeNull();

    $_SESSION['pwg_mobile_theme'] = 1;
    expect($service->getMobileThemeVar())
        ->toBeNull();

    $_SESSION['pwg_mobile_theme'] = true;
    expect($service->getMobileThemeVar())
        ->toBeTrue();

    $_SESSION['pwg_mobile_theme'] = false;
    expect($service->getMobileThemeVar())
        ->toBeFalse();
});

test('getIndexDeriv returns null when unset or non-string, and the string when set', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->getIndexDeriv())
        ->toBeNull();

    $_SESSION['pwg_index_deriv'] = 123;
    expect($service->getIndexDeriv())
        ->toBeNull();

    $_SESSION['pwg_index_deriv'] = '2small';
    expect($service->getIndexDeriv())
        ->toBe('2small');
});

test('getPluginsShowDetails returns null for an unset or non-bool value, and the strict bool when set', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->getPluginsShowDetails())
        ->toBeNull();

    $_SESSION['pwg_plugins_show_details'] = 1;
    expect($service->getPluginsShowDetails())
        ->toBeNull();

    $_SESSION['pwg_plugins_show_details'] = true;
    expect($service->getPluginsShowDetails())
        ->toBeTrue();

    $_SESSION['pwg_plugins_show_details'] = false;
    expect($service->getPluginsShowDetails())
        ->toBeFalse();
});

test('getPluginsNewOrder returns null when unset, non-string, or empty, and the string when set', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->getPluginsNewOrder())
        ->toBeNull();

    $_SESSION['pwg_plugins_new_order'] = '';
    expect($service->getPluginsNewOrder())
        ->toBeNull();

    $_SESSION['pwg_plugins_new_order'] = 123;
    expect($service->getPluginsNewOrder())
        ->toBeNull();

    $_SESSION['pwg_plugins_new_order'] = 'name';
    expect($service->getPluginsNewOrder())
        ->toBe('name');
});

test('getCommentsOrder returns null when unset or non-string, and the string when set', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->getCommentsOrder())
        ->toBeNull();

    $_SESSION['pwg_comments_order'] = 123;
    expect($service->getCommentsOrder())
        ->toBeNull();

    $_SESSION['pwg_comments_order'] = 'desc';
    expect($service->getCommentsOrder())
        ->toBe('desc');
});

test('getImageOrder returns null when unset or non-numeric, and the int when set', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->getImageOrder())
        ->toBeNull();

    $_SESSION['pwg_image_order'] = 'abc';
    expect($service->getImageOrder())
        ->toBeNull();

    $_SESSION['pwg_image_order'] = '3';
    expect($service->getImageOrder())
        ->toBe(3);

    $_SESSION['pwg_image_order'] = 7;
    expect($service->getImageOrder())
        ->toBe(7);
});

test('isShowMetadataEnabled is a pure presence check, ignoring the stored value', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->isShowMetadataEnabled())
        ->toBeFalse();

    $_SESSION['pwg_show_metadata'] = 1;
    expect($service->isShowMetadataEnabled())
        ->toBeTrue();
});

test('getRefererImageId returns null when unset or non-numeric, and the int when set', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->getRefererImageId())
        ->toBeNull();

    $_SESSION['pwg_referer_image_id'] = 'abc';
    expect($service->getRefererImageId())
        ->toBeNull();

    $_SESSION['pwg_referer_image_id'] = '42';
    expect($service->getRefererImageId())
        ->toBe(42);
});

test('getPictureDeriv returns null when unset or non-string, and the string when set', function (): void {
    $service = makeSessionService();
    $_SESSION = [];

    expect($service->getPictureDeriv())
        ->toBeNull();

    $_SESSION['pwg_picture_deriv'] = 123;
    expect($service->getPictureDeriv())
        ->toBeNull();

    $_SESSION['pwg_picture_deriv'] = '2large';
    expect($service->getPictureDeriv())
        ->toBe('2large');
});

/**
 * [Mutation] A scoped `pest --mutate` rerun leaves 12 mutations
 * "untested" -- zero real Unit-suite gaps, all individually
 * hand-mutation-verified against the real source (temporary sed edit +
 * a full rerun of this file, reverted after):
 *
 * 1. generateKey()'s own `$size + 10` buffer padding (Line 52, both
 *    directions) and its substr() start offset (Line 56, 0 -> 1) are
 *    genuinely unobservable: shifting a fixed-length slice of
 *    already-random base64 data by a byte, or trimming the spare
 *    padding by one, produces output that's just as validly random and
 *    the same length -- no black-box assertion (length, charset) can
 *    distinguish "starts here" from "starts one byte later" in uniform
 *    random data without mocking random_bytes() itself, which isn't a
 *    seam this class exposes.
 * 2. remoteAddrHash()'s own `?? ''` fallback (Line 102) is masked by
 *    what happens right after it: neither explode('.', ...) nor
 *    str_contains(..., ':') distinguish an empty string from any other
 *    '.'/':'-free sentinel text, so both real and mutated fallbacks
 *    reach the exact same ipv4/ipv6/neither branch.
 * 3. array_slice($octets, 0, 2)'s own Line 122 mutations (raising the
 *    limit, or removing the wrapper entirely and passing the full
 *    4-element $octets) are provably inert: vsprintf('%02X%02X', ...)
 *    only ever consumes the first 2 array elements its format string
 *    needs, silently ignoring any extras -- confirmed live for both.
 * 4. sessionRead()/sessionWrite()/sessionDestroy()'s own
 *    `getRemoteAddrSessionHash() . $sessionId` concatenation (Lines
 *    133/148/158) is out of scope for the Unit suite by this file's own
 *    established design (see the top-of-file comment: every test here
 *    deliberately avoids SessionRepository's real DB-backed methods,
 *    using an unreachable db_host) -- SessionRepository is `final`
 *    (can't be faked via subclassing), so covering this needs a real
 *    database. tests/Integration/SessionHandlerTest.php's own docblock
 *    already documents this exact split: "SessionServiceTest (Unit
 *    suite) deliberately only covers the DB-independent methods...
 *    real DB-backed sessionRead()/sessionWrite()/sessionDestroy()...
 *    closes both gaps at once with a real DB connection" -- already
 *    covered, just at the Integration layer this Unit-suite-focused
 *    campaign doesn't touch.
 */
