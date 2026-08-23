<?php

declare(strict_types=1);

use Piwigo\Auth\AuthService;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\Projection\MailArgs;
use Piwigo\Core\Projection\MailOptions;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WebmasterMailProviderInterface;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Event\LoadingLang;
use Piwigo\Lang\Translator;
use Piwigo\Mail\BoundedSendmailTransport;
use Piwigo\Mail\Event\BeforeParseMailTemplate;
use Piwigo\Mail\Event\RenderLostPasswordMailContent;
use Piwigo\Mail\MailRecipientRepositoryInterface;
use Piwigo\Mail\MailService;
use Piwigo\Mail\Projection\EmailRecipient;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\MailServiceTestSpyTransport;
use Piwigo\Tests\Support\MailServiceTestTransportSwap;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserService;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Every MailService::__construct() collaborator is resolved from a real
 * booted container -- Kernel::boot() is idempotent, so calling it here is
 * a safe no-op for the handful of tests that already booted their own
 * (possibly custom-rooted, e.g. a fake CurrentPaths root) Kernel before
 * calling this helper.
 */
function mail_service_test_build(
    ?WebmasterMailProviderInterface $webmasterMailProvider = null,
    ?MailRecipientRepositoryInterface $mailRecipientRepo = null,
    ?AuthService $authService = null,
): MailService {
    // Explicitly conditional, not a bare Kernel::boot() call -- a caller
    // that already booted its own (possibly custom-rooted, e.g. a fake
    // CurrentPaths root) Kernel must keep that root; Kernel::boot() itself
    // now throws on a mismatched root instead of silently keeping it.
    if (! Kernel::isBooted()) {
        Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    }

    $lang = Kernel::container()->get(Lang::class);
    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    $paths = Kernel::container()->get(Paths::class);
    $translator = Kernel::container()->get(Translator::class);
    $eventDispatcher = Kernel::container()->get(EventDispatcher::class);
    $currentUser = Kernel::container()->get(CurrentUser::class);
    $urlService = Kernel::container()->get(UrlServiceInterface::class);
    $webmasterMailProvider ??= Kernel::container()->get(WebmasterMailProviderInterface::class);
    $mailRecipientRepo ??= Kernel::container()->get(MailRecipientRepositoryInterface::class);
    $authService ??= Kernel::container()->get(AuthService::class);
    $userService = Kernel::container()->get(UserService::class);
    $pageState = Kernel::container()->get(PageState::class);
    $htmlRenderer = Kernel::container()->get(HtmlRenderingInterface::class);
    $imageStdParams = Kernel::container()->get(ImageStdParams::class);
    if (! $lang instanceof Lang || ! $currentConfig instanceof CurrentConfig
        || ! $paths instanceof Paths
        || ! $translator instanceof Translator || ! $eventDispatcher instanceof EventDispatcher
        || ! $currentUser instanceof CurrentUser || ! $urlService instanceof UrlServiceInterface
        || ! $webmasterMailProvider instanceof WebmasterMailProviderInterface
        || ! $mailRecipientRepo instanceof MailRecipientRepositoryInterface
        || ! $authService instanceof AuthService
        || ! $userService instanceof UserService
        || ! $pageState instanceof PageState
        || ! $htmlRenderer instanceof HtmlRenderingInterface
        || ! $imageStdParams instanceof ImageStdParams
    ) {
        throw new LogicException('Container returned an unexpected type');
    }

    return new MailService(
        $lang,
        $currentConfig,
        $paths,
        $translator,
        $eventDispatcher,
        $currentUser,
        $urlService,
        $webmasterMailProvider,
        $mailRecipientRepo,
        $authService,
        $userService,
        $pageState,
        $htmlRenderer,
        $imageStdParams,
    );
}

// MailService takes WebmasterMailProviderInterface as a required
// constructor collaborator (mail_service_test_build()'s own default
// resolves the real container-shared Piwigo\Users\UserRepository, which
// would need a DB connection this isolated test doesn't have), so the
// tests whose paths reach the webmaster lookup (getMailConfiguration()
// always calls getMailSenderEmail()) construct the service with this
// real fake instead.
function mail_service_with_fake_webmaster(): MailService
{
    return mail_service_test_build(new class() implements WebmasterMailProviderInterface {
        #[Override]
        public function getWebmasterMailAddress(): string
        {
            return 'webmaster@example.test';
        }
    });
}

/**
 * Calls MailService::mail() through a MailServiceTestSpyTransport instead
 * of $service's own real DSN-resolved transport, capturing the
 * fully-built Email one step before a real Transport::send() would run --
 * avoids a genuine send attempt against this environment's real
 * sendmail_path. Same real-injectable-transport technique as
 * SendNotificationEmailHandlerTest. $service is rebuilt with the spy via
 * MailServiceTestTransportSwap rather than requiring every call site to
 * thread a transport override through its own mail_service_test_build()
 * call. Every caller of this helper expects the send to actually be
 * reached (mail()'s own empty-$to early return is tested separately,
 * directly, without this helper) -- throws rather than returning a
 * nullable capture, so every call site can assert against a real,
 * non-null Email without its own null-narrowing boilerplate.
 *
 * @param string|array<int|string, mixed> $to
 * @return array{return: bool, email: Email}
 */
function mail_service_capture_send(MailService $service, string|array $to, ?MailArgs $args = null, ?MailOptions $tpl = null): array
{
    // Same "don't clobber an already-established custom root" reasoning
    // as mail_service_test_build() above.
    if (! Kernel::isBooted()) {
        Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    }
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    $spy = new MailServiceTestSpyTransport();
    $spyService = MailServiceTestTransportSwap::with($service, $spy);

    $return = $spyService->mail($to, $args, $tpl);

    if ($spy->sent === []) {
        throw new RuntimeException('expected mail() to have sent through the spy transport');
    }

    return [
        'return' => $return,
        'email' => $spy->sent[0],
    ];
}

/**
 * Starts the disposable fake ESMTP server (MailServiceTestFakeSmtpServer.php)
 * as a real subprocess, polling a ready-marker file until it's listening
 * rather than a fixed sleep -- same retry-with-readiness-poll shape as
 * ResponseEmitterTest's own local-server helper, except the readiness
 * signal can't be "connect and disconnect" the way that helper's is: this
 * server only ever accepts ONE connection, so a throwaway probe connection
 * would consume it and starve the real client. Unlike that sibling helper,
 * this one used to give up after a single 2s poll window (100 x 20ms) with
 * no outer retry -- under real system load from
 * several concurrent processes, 2s isn't always enough for a fresh
 * `php` subprocess to boot and bind a socket, causing a real, reproducible
 * "fake SMTP test server never became ready" failure though the technique
 * itself was sound. Fixed to match ResponseEmitterTestStartServer's real
 * shape (5 attempts, a fresh port each time, same 2s-per-attempt budget)
 * instead of just enlarging the single window.
 *
 * @return array{0: resource, 1: string, 2: int} the process handle, a throwaway marker-file path, and the bound port
 */
function mail_service_start_fake_smtp(string $mode): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $port = random_int(20_000, 60_000);
        $markerFile = tempnam(sys_get_temp_dir(), 'pwg_smtp_marker_');
        $readyFile = tempnam(sys_get_temp_dir(), 'pwg_smtp_ready_');
        @unlink($markerFile);
        @unlink($readyFile);

        $proc = proc_open(['php', __DIR__ . '/MailServiceTestFakeSmtpServer.php', $mode, (string) $port, $markerFile, $readyFile], $descriptors, $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException('failed to start the fake SMTP test server');
        }

        for ($i = 0; $i < 100; $i++) {
            if (file_exists($readyFile)) {
                @unlink($readyFile);

                return [$proc, $markerFile, $port];
            }
            usleep(20_000);
        }

        proc_terminate($proc);
        proc_close($proc);
        @unlink($readyFile);
    }

    throw new RuntimeException('fake SMTP test server never became ready');
}

/**
 * @param resource $proc
 */
function mail_service_stop_fake_smtp($proc, string $markerFile): void
{
    proc_terminate($proc);
    proc_close($proc);
    @unlink($markerFile);
}

/**
 * Writes a minimal, real gettext PO fixture -- same technique as
 * LangTest.php's own langTestWritePo(), duplicated locally rather than
 * shared across files (Pest test files aren't a stable place to import
 * plain functions from one another).
 */
function mail_service_write_po(string $path, string $translation): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }
    file_put_contents(
        $path,
        "msgid \"\"\nmsgstr \"\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\nmsgid \"MailServiceTestPluginMarker\"\nmsgstr \"{$translation}\"\n"
    );
}

function mail_service_rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        is_dir($path) ? mail_service_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
    // Lang is a real, container-shared instance with no pre-boot memoized
    // fallback (see LangTestFactory::get()'s own docblock) -- unlike
    // Kernel::reset() below, this file's tests are a genuine mix of ones
    // that boot Kernel and ones that never do, so a bare LangTestFactory::get()
    // here would throw for every test in the latter group.
    if (Kernel::isBooted()) {
        LangTestFactory::get()->reset();
    }
    CurrentUserTestFactory::get()->reset();
    // The switchLangTo() tests below genuinely load real .po translations
    // (e.g. de_DE's real admin.po) into the Translator singleton --
    // without this, e.g.
    // German "Administratoren"/"Jeder" strings leak into
    // PermissionServiceTest's own English-fallback assertions whenever it
    // runs later in the same process (composer test's own default,
    // non---parallel mode).
    TranslatorTestFactory::get()->reset();
    // Every Kernel::boot() call in this file (mail_service_capture_send()'s
    // own, plus several tests' direct calls) was never matched by a reset
    // -- Kernel stayed booted (with whichever root the last call used) for
    // every later test in this shared process, e.g. TemplateInstanceTest's
    // func_get_combined_scripts tests picking up this file's root url.
    Kernel::reset();
});

test('formatEmail wraps a name and email into "name <email>"', function (): void {
    $service = mail_service_test_build();

    expect($service->formatEmail('Jane Doe', 'jane@example.test'))
        ->toBe('"Jane Doe" <jane@example.test>');
});

test('formatEmail returns a bare "<email>" when name is empty', function (): void {
    $service = mail_service_test_build();

    expect($service->formatEmail('', 'jane@example.test'))
        ->toBe('<jane@example.test>');
});

test('formatEmail strips newlines from both name and email (header injection)', function (): void {
    $service = mail_service_test_build();

    expect($service->formatEmail("Jane\r\nBcc: evil@test", 'jane@example.test'))
        ->toBe('"JaneBcc: evil@test" <jane@example.test>');
});

test('formatEmail returns the name concatenated as-is when the email already contains angle brackets', function (): void {
    $service = mail_service_test_build();

    expect($service->formatEmail('Jane Doe', 'Real Name <jane@example.test>'))
        ->toBe('"Jane Doe" Real Name <jane@example.test>');
});

// formatEmail()'s and getStrictEmailList()'s own RemoveStringCast
// mutations (on `(string) preg_replace(...)`) are confirmed-equivalent:
// unlike getCleanRecipientsList()'s casts on genuinely `mixed`-typed
// input above, $name/$email/$emailList here are already string-typed
// parameters, and preg_replace() with a string $subject and this simple,
// non-/u pattern can only return a real string (never null) for any
// reachable input -- there's no way to make the cast's absence produce a
// TypeError or a different value. Verified at both call sites: mutated,
// full suite still passed, restored byte-identical.
test('formatEmail trims surrounding whitespace from both name and email', function (): void {
    $service = mail_service_test_build();

    expect($service->formatEmail('  Jane Doe  ', '  jane@example.test  '))
        ->toBe('"Jane Doe" <jane@example.test>');
});

test('getMailSenderEmail falls back to the webmaster address when mail_sender_email is unset', function (): void {
    $service = mail_service_with_fake_webmaster();

    expect($service->getMailSenderEmail())
        ->toBe('webmaster@example.test');
});

test('unformatEmail parses a "name <email>" string', function (): void {
    $service = mail_service_test_build();

    expect($service->unformatEmail('Jane Doe <jane@example.test>'))
        ->toEqual(new EmailRecipient('jane@example.test', 'Jane Doe'));
});

test('unformatEmail trims surrounding whitespace from both the parsed email and name', function (): void {
    $service = mail_service_test_build();

    expect($service->unformatEmail('  Jane Doe  <  jane@example.test  >'))
        ->toEqual(new EmailRecipient('jane@example.test', 'Jane Doe'));
});

test('unformatEmail trims surrounding whitespace from a bare email string', function (): void {
    $service = mail_service_test_build();

    expect($service->unformatEmail('  jane@example.test  '))
        ->toEqual(new EmailRecipient('jane@example.test', ''));
});

test('unformatEmail treats a bare email string as email with no name', function (): void {
    $service = mail_service_test_build();

    expect($service->unformatEmail('jane@example.test'))
        ->toEqual(new EmailRecipient('jane@example.test', ''));
});

test('unformatEmail accepts an array input with email and name keys', function (): void {
    $service = mail_service_test_build();

    expect($service->unformatEmail([
        'email' => 'jane@example.test',
        'name' => 'Jane',
    ]))->toEqual(new EmailRecipient('jane@example.test', 'Jane'));
});

test('unformatEmail throws on an array input missing the email key, with the exact real method name in the message', function (): void {
    $service = mail_service_test_build();

    expect(fn (): EmailRecipient => $service->unformatEmail([
        'name' => 'Jane',
    ]))
        ->toThrow(InvalidArgumentException::class, 'Piwigo\Mail\MailService::unformatEmail(): array input must contain a string "email" key');
});

test('getCleanRecipientsList returns an empty list for empty input', function (): void {
    $service = mail_service_test_build();

    expect($service->getCleanRecipientsList(null))
        ->toBe([])
        ->and($service->getCleanRecipientsList(''))
        ->toBe([])
        ->and($service->getCleanRecipientsList([]))->toBe([]);
});

test('getCleanRecipientsList parses a comma-separated string', function (): void {
    $service = mail_service_test_build();

    expect($service->getCleanRecipientsList('a@test.com,Bob <b@test.com>'))
        ->toEqual([
            new EmailRecipient('a@test.com', ''),
            new EmailRecipient('b@test.com', 'Bob'),
        ]);
});

test('getCleanRecipientsList returns an empty list for a literal int 0, matching its own null/""/[]/false peers', function (): void {
    $service = mail_service_test_build();

    expect($service->getCleanRecipientsList(0))
        ->toBe([]);
});

test('getCleanRecipientsList deduplicates by email', function (): void {
    $service = mail_service_test_build();

    expect($service->getCleanRecipientsList('a@test.com,a@test.com'))
        ->toEqual([
            new EmailRecipient('a@test.com', ''),
        ]);
});

test('getCleanRecipientsList keeps every entry after a duplicate in the middle of the list, not just up to the first duplicate', function (): void {
    // Also kills the dedup loop's own ContinueToBreak mutation (break
    // instead of continue on a duplicate): a duplicate followed by more
    // real, unique entries proves the loop kept going past it. FalseToTrue
    // on `$existing[...] = true` (the line right after) is a genuine
    // equivalent, not tested separately: isset() only cares about key
    // existence, never the stored value's truthiness, so true vs false
    // here is write-only and unobservable either way.
    $service = mail_service_test_build();

    expect($service->getCleanRecipientsList('a@test.com,a@test.com,b@test.com'))
        ->toEqual([
            new EmailRecipient('a@test.com', ''),
            new EmailRecipient('b@test.com', ''),
        ]);
});

test('getCleanRecipientsList accepts a plain array of emails', function (): void {
    $service = mail_service_test_build();

    expect($service->getCleanRecipientsList(['a@test.com', 'b@test.com']))->toEqual([
        new EmailRecipient('a@test.com', ''),
        new EmailRecipient('b@test.com', ''),
    ]);
});

test('getCleanRecipientsList trims whitespace from a plain array of emails', function (): void {
    $service = mail_service_test_build();

    expect($service->getCleanRecipientsList(['  a@test.com  ']))->toEqual([
        new EmailRecipient('a@test.com', ''),
    ]);
});

test('getCleanRecipientsList string-casts a non-string scalar item inside a plain array of emails', function (): void {
    // Without the cast, trim() would receive a raw int under this file's
    // own strict_types=1 and throw a TypeError instead of formatting it.
    $service = mail_service_test_build();

    expect($service->getCleanRecipientsList(['a@test.com', 42]))->toEqual([
        new EmailRecipient('a@test.com', ''),
        new EmailRecipient('42', ''),
    ]);
});

test('getCleanRecipientsList decides "simple array of emails" vs "hashmap" from the FIRST key only, not any other position', function (): void {
    $service = mail_service_test_build();

    // array_keys() here is [0, 'email'] -- key 0 (int) routes into the
    // "simple array of emails" branch, which iterates every item
    // (including the one at the 'email' key) as its own recipient. If the
    // branch decision looked at the wrong key position (e.g. index 1,
    // 'email', a string) it would instead treat the whole array as ONE
    // hashmap recipient via unformatEmail(), silently dropping the first
    // entry -- these two behaviors produce different counts.
    expect($service->getCleanRecipientsList([
        'a@test.com',
        'email' => 'b@test.com',
    ]))->toEqual([
        new EmailRecipient('a@test.com', ''),
        new EmailRecipient('b@test.com', ''),
    ]);
});

test('getCleanRecipientsList falls back to an empty email for a non-scalar item inside a plain array', function (): void {
    $service = mail_service_test_build();

    expect($service->getCleanRecipientsList(['a@test.com', null]))->toEqual([
        new EmailRecipient('a@test.com', ''),
        new EmailRecipient('', ''),
    ]);
});

test('getCleanRecipientsList accepts a single hashmap recipient', function (): void {
    $service = mail_service_test_build();

    expect($service->getCleanRecipientsList([
        'email' => 'a@test.com',
        'name' => 'A',
    ]))->toEqual([
        new EmailRecipient('a@test.com', 'A'),
    ]);
});

test('getCleanRecipientsList falls back to a scalar-cast email for a non-array, non-string item inside an array of hashmaps', function (): void {
    $service = mail_service_test_build();

    // The first item being an array routes into the "array of hashmaps"
    // branch; the second item (a bare int) is neither an array nor a
    // string, so it takes the scalar-cast fallback instead of
    // unformatEmail().
    expect($service->getCleanRecipientsList([[
        'email' => 'a@test.com',
    ], 42]))->toEqual([
        new EmailRecipient('a@test.com', ''),
        new EmailRecipient('42', ''),
    ]);
});

test('getCleanRecipientsList trims a whitespace-padded scalar item inside an array of hashmaps', function (): void {
    $service = mail_service_test_build();

    expect($service->getCleanRecipientsList([[
        'email' => 'a@test.com',
    ], '  99  ']))->toEqual([
        new EmailRecipient('a@test.com', ''),
        new EmailRecipient('99', ''),
    ]);
});

test('getCleanRecipientsList falls back to an empty email for a non-scalar item inside an array of hashmaps', function (): void {
    $service = mail_service_test_build();

    expect($service->getCleanRecipientsList([[
        'email' => 'a@test.com',
    ], null]))->toEqual([
        new EmailRecipient('a@test.com', ''),
        new EmailRecipient('', ''),
    ]);
});

test('getCleanRecipientsList falls back to a single, empty-email recipient for a non-array, non-scalar $data', function (): void {
    $service = mail_service_test_build();

    // Not null/''/[]library/false/0 (mail()'s own emptyValue()-guarded
    // callers never reach this with those), and not is_array() -- the only
    // genuinely reachable non-scalar, non-array PHP value here is an
    // object, which the final `else` branch's own is_scalar() fallback (as
    // opposed to a hard TypeError) exists to absorb.
    expect($service->getCleanRecipientsList(new stdClass()))
        ->toEqual([
            new EmailRecipient('', ''),
        ]);
});

test('getCleanRecipientsList string-casts a non-array, non-string scalar $data before exploding it', function (): void {
    // Without the cast, explode() would receive a raw int under this
    // file's own strict_types=1 and throw a TypeError instead.
    $service = mail_service_test_build();

    expect($service->getCleanRecipientsList(42))
        ->toEqual([
            new EmailRecipient('42', ''),
        ]);
});

test('getStrictEmailList strips names, keeping only the bare email addresses', function (): void {
    $service = mail_service_test_build();

    expect($service->getStrictEmailList('Jane <jane@test.com>, bob@test.com'))
        ->toBe('jane@test.com,bob@test.com');
});

test('getStrictEmailList deduplicates identical addresses after stripping names', function (): void {
    $service = mail_service_test_build();

    expect($service->getStrictEmailList('Jane <jane@test.com>, jane@test.com'))
        ->toBe('jane@test.com');
});

test('getMailTemplate resolves the real, absolute theme root and the given email-format subdirectory', function (): void {
    // Historical note (from the old Smarty engine, no longer a live
    // concern now that Template::getTemplateDir() is a plain
    // `$this->templateDirs[0] ?? ''` passthrough, no cache/realpath
    // involved): templateExists() alone wasn't reliable here, since
    // Smarty's own compiled-template disk cache (_data/templates_c/)
    // could satisfy it from an EARLIER test's correctly-resolved compile
    // even after this specific concatenation was broken, and
    // getTemplateDir() alone wasn't reliable either if the live,
    // container-bound Paths->root happened to equal the real process cwd
    // (it does whenever `vendor/bin/pest` itself runs from the project
    // root), since Smarty's own addTemplateDir() resolved a RELATIVE path
    // against cwd, which then coincidentally reconstructed the exact same
    // absolute string the real concatenation would have produced.
    // Pointing CurrentPaths at a deliberately fake, cwd-mismatched root
    // still removes any such coincidence, so the technique is kept even
    // though the original failure modes it defended against are gone.
    $fakeRoot = Paths::fromRoot('/tmp/piwigo-mailservice-test-fake-root-' . (string) getmypid() . '/');
    Kernel::boot($fakeRoot);
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    $service = mail_service_test_build();

    $htmlTemplate = $service->getMailTemplate('text/html');
    $plainTemplate = $service->getMailTemplate('text/plain');

    // No trailing slash: unlike the old Smarty engine (which normalized
    // every registered template dir), Template::getTemplateDir() now
    // returns the exact string setTheme()/setTemplateDir() built --
    // 'root/theme/path' with no separate normalization step. No real
    // production caller ever depended on the trailing slash (this method
    // has none at all besides this test).
    expect($htmlTemplate->getTemplateDir())
        ->toBe($fakeRoot->root . 'themes/default/template/mail/text/html');
    expect($plainTemplate->getTemplateDir())
        ->toBe($fakeRoot->root . 'themes/default/template/mail/text/plain');
});

test('getStrEmailFormat maps the html flag to a MIME content type', function (): void {
    $service = mail_service_test_build();

    expect($service->getStrEmailFormat(true))
        ->toBe('text/html')
        ->and($service->getStrEmailFormat(false))
        ->toBe('text/plain');
});

test('moveCssToBody returns an empty string unchanged', function (): void {
    $service = mail_service_test_build();

    expect($service->moveCssToBody(''))
        ->toBe('');
});

test('moveCssToBody inlines a <style> block into the element it targets', function (): void {
    $service = mail_service_test_build();

    $html = '<html><head><style>p { color: red; }</style></head><body><p>hi</p></body></html>';
    $result = $service->moveCssToBody($html);

    expect($result)
        ->toContain('style="color: red;"');
});

test('getMailSenderName falls back to gallery_title when mail_sender_name is unset', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->galleryTitle = 'My Gallery';
    $service = mail_service_test_build();

    expect($service->getMailSenderName())
        ->toBe('My Gallery');
});

test('getMailSenderName uses mail_sender_name when configured', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderName = 'Custom Sender';
    $service = mail_service_test_build();

    expect($service->getMailSenderName())
        ->toBe('Custom Sender');
});

test('getMailSenderEmail uses the configured mail_sender_email without falling back to the webmaster address', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    // No WebmasterMailProviderInterface fake needed here -- a configured,
    // non-empty mail_sender_email short-circuits before webmasterMailAddress()
    // (which would otherwise need a real DB connection) is ever reached.
    $service = mail_service_test_build();

    expect($service->getMailSenderEmail())
        ->toBe('sender@example.test');
});

test('getMailConfiguration reports use_smtp false when smtp_host is unset', function (): void {
    $service = mail_service_with_fake_webmaster();

    expect($service->getMailConfiguration()['use_smtp'])->toBeFalse();
});

test('getMailConfiguration reports use_smtp true when smtp_host is configured', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->smtpHost = 'smtp.example.test';
    $service = mail_service_with_fake_webmaster();

    expect($service->getMailConfiguration()['use_smtp'])->toBeTrue();
});

test('getMailConfiguration reads debug_mail-adjacent smtp settings from CurrentConfig::', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->smtpHost = 'smtp.example.test';
    CurrentConfigTestFactory::get()->smtpUser = 'mailuser';
    CurrentConfigTestFactory::get()->smtpPassword = 'secret';
    CurrentConfigTestFactory::get()->smtpSecure = 'tls';
    $service = mail_service_with_fake_webmaster();

    $config = $service->getMailConfiguration();

    expect($config['smtp_user'])->toBe('mailuser')
        ->and($config['smtp_password'])->toBe('secret')
        ->and($config['smtp_secure'])->toBe('tls');
});

test('generateResetPasswordMail builds an HTML mail with the reset link and gallery-title-prefixed subject', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    $service = mail_service_test_build();

    $mail = $service->generateResetPasswordMail('jane', 'https://example.test/password.php?key=abc', 'My Gallery', '2 hours');

    expect($mail->subject)
        ->toBe('[My Gallery] Password Reset');
    expect($mail->contentFormat)
        ->toBe('text/html');
    expect($mail->content)
        ->toContain('jane');
    expect($mail->content)
        ->toContain('https://example.test/password.php?key=abc');
    expect($mail->content)
        ->toContain('2 hours');
});

test('generateResetPasswordMail escapes an HTML-special-character-bearing username (P44-D)', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    $service = mail_service_test_build();

    $mail = $service->generateResetPasswordMail('<script>alert(1)</script> & "jane"', 'https://example.test/password.php?key=abc', 'My Gallery', '2 hours');

    expect($mail->content)
        ->not->toContain('<script>alert(1)</script>')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;jane&quot;');
});

test('generateSetPasswordMail builds an HTML mail with the activation link and a welcome subject', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    $service = mail_service_test_build();

    $mail = $service->generateSetPasswordMail('jane', 'https://example.test/password.php?key=xyz', 'My Gallery', '48 hours');

    expect($mail->subject)
        ->toBe('Welcome to My Gallery');
    expect($mail->contentFormat)
        ->toBe('text/html');
    expect($mail->content)
        ->toContain('jane');
    expect($mail->content)
        ->toContain('https://example.test/password.php?key=xyz');
    expect($mail->content)
        ->toContain('48 hours');
});

test('generateSetPasswordMail escapes an HTML-special-character-bearing username (P44-D)', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    $service = mail_service_test_build();

    $mail = $service->generateSetPasswordMail('<script>alert(1)</script> & "jane"', 'https://example.test/password.php?key=xyz', 'My Gallery', '48 hours');

    expect($mail->content)
        ->not->toContain('<script>alert(1)</script>')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;jane&quot;');
});

test('generateCodeVerificationMail embeds the raw verification code and the current gallery title', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->galleryTitle = 'My Gallery';
    $service = mail_service_test_build();

    $mail = $service->generateCodeVerificationMail('482913');

    expect($mail->subject)
        ->toBe('[My Gallery] Your verification code');
    expect($mail->contentFormat)
        ->toBe('text/html');
    expect($mail->content)
        ->toContain('482913');
});

test('generateSuccessResetPasswordMail omits the API-key-revocation notice when there are no API keys', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    $service = mail_service_test_build();

    $mail = $service->generateSuccessResetPasswordMail('jane', 0);

    expect($mail->content)
        ->toContain('Hello jane,');
    expect($mail->content)
        ->not->toContain('API keys');
});

test('generateSuccessResetPasswordMail includes the API-key-revocation notice with the real key count when there are some', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    $service = mail_service_test_build();

    $mail = $service->generateSuccessResetPasswordMail('jane', 3);

    expect($mail->content)
        ->toContain('Hello jane,');
    expect($mail->content)
        ->toContain('3 API keys');
});

// The 4 generate*Mail tests below assert the exact, full concatenated
// string (not just toContain() fragments) -- these methods build their
// content via long chains of `.=` concatenation, and a partial-fragment
// match wouldn't distinguish a mutation that reorders/drops/duplicates one
// of those chained pieces from the real, correctly-ordered result.

test('generateResetPasswordMail assembles the exact HTML content, in order, from every concatenated piece', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    $service = mail_service_test_build();

    $mail = $service->generateResetPasswordMail('jane', 'https://example.test/password.php?key=abc', 'My Gallery', '2 hours');

    expect($mail->subject)
        ->toBe('[My Gallery] Password Reset');
    expect($mail->contentFormat)
        ->toBe('text/html');
    expect($mail->content)
        ->toBe(
            '<p style="margin: 20px 0">Someone requested that the password be reset for the following user account: jane</p>'
                . '<p style="margin: 20px 0">To reset your password, visit the following address: <a href="https://example.test/password.php?key=abc">Change my password</a></p>'
                . '<p style="text-align: center; font-size: 70%;">https://example.test/password.php?key=abc</p>'
                . '<p style="margin: 20px 0;">This link is valid for 2 hours. After this time, you will need to request a new link. If this was a mistake, just ignore this email and nothing will happen.</p>'
        );
});

test('generateResetPasswordMail uses the render_lost_password_mail_content handler\'s own replacement when it returns a real string', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    $service = mail_service_test_build();
    $handler = static function (RenderLostPasswordMailContent $event): void {
        $event->message = 'REPLACED CONTENT';
    };
    EventDispatcherTestFactory::get()->addTypedHandler(RenderLostPasswordMailContent::class, $handler);

    try {
        $mail = $service->generateResetPasswordMail('jane', 'https://example.test/x', 'My Gallery', '2 hours');
    } finally {
        EventDispatcherTestFactory::get()->removeTypedHandler(RenderLostPasswordMailContent::class, $handler);
    }

    expect($mail->content)
        ->toBe('REPLACED CONTENT');
});

test('generateSetPasswordMail assembles the exact HTML content, in order, from every concatenated piece', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    $service = mail_service_test_build();

    $mail = $service->generateSetPasswordMail('jane', 'https://example.test/password.php?key=xyz', 'My Gallery', '48 hours');

    expect($mail->subject)
        ->toBe('Welcome to My Gallery');
    expect($mail->contentFormat)
        ->toBe('text/html');
    expect($mail->content)
        ->toBe(
            '<p style="margin: 20px 0">A photo library administrator has created the following account for you: jane</p>'
                . '<p style="margin: 20px 0">To set your password, visit the following address: <a href="https://example.test/password.php?key=xyz">Activate</a></p>'
                . '<p style="text-align: center; font-size: 70%; margin: 20px 0;">https://example.test/password.php?key=xyz</p>'
                . '<p style="margin: 20px 0;">This link is valid for 48 hours. After this time, you will need to request a new link. If this was a mistake, just ignore this email and nothing will happen.</p>'
        );
});

test('generateSetPasswordMail uses the render_lost_password_mail_content handler\'s own replacement when it returns a real string', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    $service = mail_service_test_build();
    $handler = static function (RenderLostPasswordMailContent $event): void {
        $event->message = 'REPLACED CONTENT';
    };
    EventDispatcherTestFactory::get()->addTypedHandler(RenderLostPasswordMailContent::class, $handler);

    try {
        $mail = $service->generateSetPasswordMail('jane', 'https://example.test/x', 'My Gallery', '48 hours');
    } finally {
        EventDispatcherTestFactory::get()->removeTypedHandler(RenderLostPasswordMailContent::class, $handler);
    }

    expect($mail->content)
        ->toBe('REPLACED CONTENT');
});

test('generateCodeVerificationMail assembles the exact HTML content, in order, from every concatenated piece', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->galleryTitle = 'My Gallery';
    $service = mail_service_test_build();

    $mail = $service->generateCodeVerificationMail('482913');

    expect($mail->subject)
        ->toBe('[My Gallery] Your verification code');
    expect($mail->contentFormat)
        ->toBe('text/html');
    expect($mail->content)
        ->toBe(
            '<p style="margin: 20px 0">Here is your verification code: <br /><span style="font-size: 16px">482913</span></p>'
                . '<p style="margin: 20px 0;">If this was a mistake, just ignore this email and nothing will happen.</p>'
        );
});

test('generateSuccessResetPasswordMail assembles the exact HTML content with no API-key notice when there are none', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    $service = mail_service_test_build();

    $mail = $service->generateSuccessResetPasswordMail('jane', 0);

    expect($mail->subject)
        ->toBe('[Piwigo] Your password has been reset');
    expect($mail->contentFormat)
        ->toBe('text/html');
    expect($mail->content)
        ->toBe(
            '<p style="margin-top: 20px;">Hello jane,</p>'
                . '<p style="margin-bottom: 20px;">Your password was successfully reset.</p>'
                . '<p>If this wasn\'t you, please change your password immediately or contact your webmaster.</p>'
        );
});

test('generateSuccessResetPasswordMail includes the API-key-revocation notice at exactly 1 key, the boundary right above the ">0" guard', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    $service = mail_service_test_build();

    $mail = $service->generateSuccessResetPasswordMail('jane', 1);

    expect($mail->content)
        ->toContain('1 API keys');
});

// generateResetPasswordMail/generateSetPasswordMail/generateCodeVerificationMail's
// own setMakeFullUrl()/unsetMakeFullUrl() pairs (RemoveMethodCall on
// either half) are confirmed-equivalent from THIS class's own public
// contract -- their returned array is the only externally observed
// result, and none of these 3 methods' own $message-building calls
// urlService() for anything (their links are caller-supplied parameters,
// unlike generateSuccessResetPasswordMail's own $profileUrl below, which
// genuinely needs the full-url mode active while computing it). Removing
// either call only affects global url-mode state AFTER the method has
// already returned its value, never the value itself. Verified
// against generateResetPasswordMail's own setMakeFullUrl call, representative of
// all 3 methods' identical shape: mutated, both of that method's tests
// still passed, restored byte-identical.
test('generateSuccessResetPasswordMail assembles the exact HTML content, in order, including the API-key notice and real profile URL when there are some', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    $service = mail_service_test_build();
    // getRootUrl() is environment-dependent (RootPathOverride/cwd), same
    // real value generateSuccessResetPasswordMail() itself uses internally
    // via its own constructor-injected $urlService -- resolved from the
    // same container-shared instance rather than hardcoded, so this
    // assertion stays exact without being coupled to where tests happen to
    // run from. Must be read inside the same setMakeFullUrl()-active
    // window generateSuccessResetPasswordMail() itself uses: getRootUrl()
    // returns a bare relative path outside it.
    $urlService = Kernel::container()->get(UrlServiceInterface::class);
    if (! $urlService instanceof UrlServiceInterface) {
        throw new RuntimeException('expected UrlServiceInterface::class to resolve to a UrlServiceInterface');
    }
    $urlService->setMakeFullUrl();
    $rootUrl = $urlService->getRootUrl();
    $urlService->unsetMakeFullUrl();

    $mail = $service->generateSuccessResetPasswordMail('jane', 3);

    expect($mail->content)
        ->toBe(
            '<p style="margin-top: 20px;">Hello jane,</p>'
                . '<p style="margin-bottom: 20px;">Your password was successfully reset.</p>'
                . '<p>If this wasn\'t you, please change your password immediately or contact your webmaster.</p>'
                . '<p style="margin: 20px 0;">If you changed your password because you think it was stolen, we recommend revoking your 3 API keys <a href="' . $rootUrl . 'profile.php">in your profile</a>.</p>'
        );
});

test('mail returns true immediately for an empty $to with no Cc/Bcc, without building anything', function (): void {
    $service = mail_service_test_build();

    expect($service->mail(''))
        ->toBeTrue();
});

test('emptyValue treats 0, 0.0 and false as empty, same as null/""/[]', function (mixed $value): void {
    // emptyValue() is private -- called via Reflection rather than routing
    // these deliberately off-contract values (its own real callers, e.g.
    // mail()'s args[auth_key], are documented `string`-only) through
    // MailService::mail()'s own sealed-shape $args parameter, which would
    // reject a non-string auth_key statically despite it being real,
    // reachable runtime behavior (PHP doesn't enforce docblock types).
    $emptyValue = new ReflectionMethod(MailService::class, 'emptyValue');

    expect($emptyValue->invoke(null, $value))
        ->toBeTrue();
})->with([0, 0.0, false]);

test('mail actually appends a real, non-falsy auth_key to the generated links', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
        authKey: 'REAL123',
    ));

    expect($result['email']->getTextBody())->toContain('auth=REAL123');
});

test('mail builds a To address for every recipient in a comma-separated list', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test, jane@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
    ));

    $toAddresses = array_map(static fn (Address $a): string => $a->getAddress(), $result['email']->getTo());
    expect($toAddresses)
        ->toBe(['bob@example.test', 'jane@example.test']);
});

test('mail defaults the From address to the configured mail sender email/name when args[from] is absent', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    CurrentConfigTestFactory::get()->mailSenderName = 'Test Sender';
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
    ));

    expect($result['email']->getFrom()[0]->getAddress())->toBe('sender@example.test');
    expect($result['email']->getFrom()[0]->getName())->toBe('Test Sender');
});

test('mail uses an explicit args[from] instead of the configured default', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
        from: [
            'email' => 'other@example.test',
            'name' => 'Other',
        ],
    ));

    expect($result['email']->getFrom()[0]->getAddress())->toBe('other@example.test');
    expect($result['email']->getFrom()[0]->getName())->toBe('Other');
});

test('mail defaults reply-to to the same address/name it resolved for From', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    CurrentConfigTestFactory::get()->mailSenderName = 'Test Sender';
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
    ));

    expect($result['email']->getReplyTo()[0]->getAddress())->toBe('sender@example.test');
    expect($result['email']->getReplyTo()[0]->getName())->toBe('Test Sender');
});

test('mail uses explicit reply_to_mail_address/reply_to_name instead of falling back to From', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
        replyToMailAddress: 'reply@example.test',
        replyToName: 'Reply Guy',
    ));

    expect($result['email']->getReplyTo()[0]->getAddress())->toBe('reply@example.test');
    expect($result['email']->getReplyTo()[0]->getName())->toBe('Reply Guy');
});

test('mail defaults the subject to exactly "Piwigo" when absent', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        content: 'y',
    ));

    expect($result['email']->getSubject())->toBe('Piwigo');
});

test('mail strips embedded newlines and surrounding whitespace from the subject (header injection)', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: "  Hi\r\nBcc: evil@test  ",
        content: 'y',
    ));

    expect($result['email']->getSubject())->toBe('HiBcc: evil@test');
});

test('mail builds a Cc address when args[Cc] is a real, non-empty value', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
        cc: 'cc@example.test',
    ));

    expect($result['email']->getCc())->toHaveCount(1);
    expect($result['email']->getCc()[0]->getAddress())->toBe('cc@example.test');
});

test('mail adds no Cc address at all when args[Cc] is absent', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
    ));

    expect($result['email']->getCc())->toBe([]);
});

test('mail Bcc\'s only the explicit recipient when send_bcc_mail_webmaster is false', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    CurrentConfigTestFactory::get()->sendBccMailWebmaster = false;
    $service = mail_service_with_fake_webmaster();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
        bcc: 'bcc@example.test',
    ));

    $bccAddresses = array_map(static fn (Address $a): string => $a->getAddress(), $result['email']->getBcc());
    expect($bccAddresses)
        ->toBe(['bcc@example.test']);
});

test('mail Bcc\'s the webmaster address when send_bcc_mail_webmaster is true, even with no explicit Bcc', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    CurrentConfigTestFactory::get()->sendBccMailWebmaster = true;
    $service = mail_service_with_fake_webmaster();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
    ));

    $bccAddresses = array_map(static fn (Address $a): string => $a->getAddress(), $result['email']->getBcc());
    expect($bccAddresses)
        ->toBe(['webmaster@example.test']);
});

// The 4 theme tests and the content-default test below assert on
// mail()'s own internal $args normalization, which (unlike subject/to/Cc/
// Bcc) never surfaces on the resulting Email itself when mailAllowHtml is
// false (every existing test in this area sets it false) -- the
// theme-specific CSS partial only gets assigned for a text/html content
// type. Tested directly via Reflection against the extracted pure
// resolveMailTheme()/resolveMailContent() methods instead, same
// established pattern as this file's own emptyValue() test above.
test('resolveMailTheme replaces an unset theme with the configured mail_theme', function (): void {
    $resolveMailTheme = new ReflectionMethod(MailService::class, 'resolveMailTheme');

    expect($resolveMailTheme->invoke(null, new MailArgs(), [
        'mail_theme' => 'dark',
    ]))
        ->toBe('dark');
});

test('resolveMailTheme replaces an invalid theme with the configured mail_theme instead of leaving it as-is', function (): void {
    $resolveMailTheme = new ReflectionMethod(MailService::class, 'resolveMailTheme');

    expect($resolveMailTheme->invoke(null, new MailArgs(
        theme: 'bogus',
    ), [
        'mail_theme' => 'dark',
    ]))
        ->toBe('dark');
});

test('resolveMailTheme preserves an explicit, valid theme ("clear")', function (): void {
    $resolveMailTheme = new ReflectionMethod(MailService::class, 'resolveMailTheme');

    expect($resolveMailTheme->invoke(null, new MailArgs(
        theme: 'clear',
    ), [
        'mail_theme' => 'dark',
    ]))
        ->toBe('clear');
});

test('resolveMailTheme preserves an explicit, valid theme ("dark")', function (): void {
    $resolveMailTheme = new ReflectionMethod(MailService::class, 'resolveMailTheme');

    expect($resolveMailTheme->invoke(null, new MailArgs(
        theme: 'dark',
    ), [
        'mail_theme' => 'clear',
    ]))
        ->toBe('dark');
});

test('resolveMailTheme falls back to "clear" when mail_theme itself is not a real string', function (): void {
    // is_string($confMail['mail_theme'] ?? null) ? ... : 'clear' -- every
    // sibling test above always supplies a real string mail_theme, so this
    // final defensive fallback (mail_theme absent or a non-string, which
    // getMailConfiguration() itself never actually produces, but this
    // method has no way to know that) was never exercised.
    $resolveMailTheme = new ReflectionMethod(MailService::class, 'resolveMailTheme');

    expect($resolveMailTheme->invoke(null, new MailArgs(), []))
        ->toBe('clear');
});

test('resolveMailContent defaults to an empty string when the key is entirely absent', function (): void {
    $resolveMailContent = new ReflectionMethod(MailService::class, 'resolveMailContent');

    expect($resolveMailContent->invoke(null, new MailArgs()))
        ->toBe('');
});

test('resolveMailContent preserves an explicit, non-empty content value', function (): void {
    $resolveMailContent = new ReflectionMethod(MailService::class, 'resolveMailContent');

    expect($resolveMailContent->invoke(null, new MailArgs(
        content: 'hello',
    )))
        ->toBe('hello');
});

test('mail decomposes a "[Title] Subtitle" subject into mail_title/mail_subtitle when neither was preset', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    CurrentConfigTestFactory::get()->galleryTitle = 'My Gallery';
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: '[Foo] Bar baz',
        content: 'y',
    ));

    expect($result['email']->getTextBody())->toContain("Foo\n Bar baz");
});

test('mail does not decompose the subject when mail_title was already explicitly set', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    CurrentConfigTestFactory::get()->galleryTitle = 'My Gallery';
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: '[Foo] Bar baz',
        content: 'y',
        mailTitle: 'PresetTitle',
    ));

    // Since mail_title is preset, the decomposition guard's own `&&` never
    // runs at all; mail_subtitle (still unset) falls through to its own
    // separate default of the full, undecomposed subject.
    expect($result['email']->getTextBody())->toContain("PresetTitle\n[Foo] Bar baz");
});

test('mail includes the html part only when mail_allow_html is true and email_format isn\'t forced to text/plain', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    CurrentConfigTestFactory::get()->mailAllowHtml = true;
    // Boots Kernel here (idempotently no-op'd by mail_service_capture_send()'s
    // own later call) so LangTestFactory::get() below resolves the same
    // container-shared instance mail()'s own real $this->lang->langInfo()
    // read will see once mail_service_capture_send() actually sends.
    LangTestFactory::get()->setLangInfo([
        'code' => 'en',
        'direction' => 'ltr',
    ]);
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
    ));

    expect($result['email']->getHtmlBody())->not->toBeNull();
});

test('mail omits the html part when mail_allow_html is true but email_format explicitly forces text/plain', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    CurrentConfigTestFactory::get()->mailAllowHtml = true;
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
        emailFormat: 'text/plain',
    ));

    expect($result['email']->getHtmlBody())->toBeNull();
});

test('mail omits the html part entirely when mail_allow_html is false', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
    ));

    expect($result['email']->getHtmlBody())->toBeNull();
});

test('mail converts a text/plain content into HTML: paragraph-wrapped, escaped, line-broken, and link-ified, in that exact combined form', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    CurrentConfigTestFactory::get()->mailAllowHtml = true;
    LangTestFactory::get()->setLangInfo([
        'code' => 'en',
        'direction' => 'ltr',
    ]);
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: "Line one & <tag>\nLine two https://example.test/path more text",
    ));

    // A single exact fragment proves htmlspecialchars() escaping the raw
    // '&'/'<tag>' BEFORE link-ification runs (not after, which would
    // double-escape the generated <a> tag), nl2br() actually inserting a
    // line break at the real newline, and the URL regex capturing the
    // exact link text -- any one of those breaking would corrupt this
    // fragment. Doesn't anchor on '<p>...</p>' itself: moveCssToBody()'s
    // own Emogrifier DOM pass downstream inlines the theme's own CSS onto
    // the <p> tag (a real "style=...\" attribute) and
    // rewrites nl2br()'s XHTML "<br />" down to the bare HTML5 "<br>" --
    // neither is this concatenation's own concern.
    expect($result['email']->getHtmlBody())->toContain(
        'Line one &amp; &lt;tag&gt;<br>' . "\n"
        . 'Line two <a href="https://example.test/path">https://example.test/path</a> more text'
    );
});

test('mail converts a text/html content into plain text (tags stripped) for the plain-text part', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: '<strong>Bold</strong> text',
        contentFormat: 'text/html',
    ));

    expect($result['email']->getTextBody())->toContain('Bold text');
    expect($result['email']->getTextBody())->not->toContain('<strong>');
});

test('mail assigns every real GALLERY_TITLE/GALLERY_URL/VERSION/APP_URL/CONTACT_MAIL template variable, all visible in the plain-text footer together', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    CurrentConfigTestFactory::get()->galleryTitle = 'My Real Gallery';
    CurrentConfigTestFactory::get()->showVersion = true;
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
    ));
    $body = $result['email']->getTextBody();

    expect($body)
        ->toContain('Sent by "My Real Gallery"')
        ->and($body)
        ->toMatch('/Sent by "My Real Gallery" \S+/')
        ->and($body)
        ->toContain('Powered by "Piwigo 17.0.0" https://upstream.example.invalid')
        ->and($body)
        ->toContain('Contact: sender@example.test');
});

test('mail omits the version number entirely from the footer when show_version is false', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    CurrentConfigTestFactory::get()->showVersion = false;
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
    ));

    expect($result['email']->getTextBody())->toContain('Powered by "Piwigo" ')
        ->and($result['email']->getTextBody())->not->toContain('17.0.0');
});

test('mail assigns the real CONTENT_ENCODING charset into the html header\'s meta tag', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    CurrentConfigTestFactory::get()->mailAllowHtml = true;
    LangTestFactory::get()->setLangInfo([
        'code' => 'en',
        'direction' => 'ltr',
    ]);
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
    ));

    expect($result['email']->getHtmlBody())->toContain('charset=utf-8');
});

test('mail fires the before_parse_mail_template event with the real cache key and content type', function (): void {
    // Kernel must boot before the CurrentConfig writes below -- CurrentConfigTestFactory::get()
    // resolves the pre-boot memoized fallback instance until Kernel::boot()
    // builds the container's own (different) shared instance; seeding
    // before that boot would silently seed an instance MailService's own
    // get()-shim call never sees once the container exists (same ordering
    // pitfall applies to Translator/EventDispatcher/CurrentUser/
    // CurrentTemplate/CurrentConfigService) -- also the reason a real
    // event handler must be registered after boot too, not before.
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    $service = mail_service_test_build();

    $capturedCacheKey = null;
    $capturedContentType = null;
    $handler = function (BeforeParseMailTemplate $event) use (&$capturedCacheKey, &$capturedContentType): void {
        $capturedCacheKey = $event->cacheKey;
        $capturedContentType = $event->contentType;
    };
    EventDispatcherTestFactory::get()->addTypedHandler(BeforeParseMailTemplate::class, $handler);
    try {
        mail_service_capture_send($service, 'bob@example.test', new MailArgs(
            subject: 'x',
            content: 'y',
        ));
    } finally {
        EventDispatcherTestFactory::get()->removeTypedHandler(BeforeParseMailTemplate::class, $handler);
    }

    expect($capturedContentType)
        ->toBe('text/plain');
    expect($capturedCacheKey)
        ->toContain('text/plain');
});

test('mail keys its per-request template cache by auth_key too, not reusing one auth_key\'s rendered link for another', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    $service = mail_service_test_build();

    $result1 = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
        authKey: 'AAA',
    ));
    $result2 = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
        authKey: 'BBB',
    ));

    expect($result1['email']->getTextBody())->toContain('auth=AAA')
        ->and($result1['email']->getTextBody())->not->toContain('auth=BBB')
        ->and($result2['email']->getTextBody())->toContain('auth=BBB')
        ->and($result2['email']->getTextBody())->not->toContain('auth=AAA');
});

// The cache key's own remaining ConcatSwitchSides/ConcatRemoveRight
// mutations (reordering/dropping pieces WITHOUT reintroducing a
// same-request collision the 4 tests above would catch) are
// confirmed-equivalent from a black-box perspective: $cacheKey is a fully
// internal, never-externally-observed string -- only its UNIQUENESS per
// distinct (contentType, langCode, theme, auth_key) combination is
// observable (via which cached Template gets reused), never its exact
// characters. Verified representatively: with ConcatSwitchSides
// reordering the trailing `. $args['theme']` concat to the front, all 4
// cache-differentiation tests above still passed unchanged, since the
// swap still produces a distinct string per distinct input combination.
test('mail keeps the html and plain-text cache entries of the SAME call separate, even with the same auth_key/lang/theme', function (): void {
    // Kills the auth_key suffix's own ConcatEqualToEqual (`.=` -> `=`,
    // discarding the contentType/langCode/theme prefix the cache key
    // already built): with a single auth_key, a single mail() call whose
    // contentTypeList includes BOTH 'text/html' and 'text/plain' would
    // process html first, cache it under the corrupted "-AAA"-only key,
    // then process plain and find that SAME key already cached --
    // incorrectly reusing the html Template object (and its already-
    // rendered <html>/<style> markup) for the supposedly plain-text part.
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = true;
    LangTestFactory::get()->setLangInfo([
        'code' => 'en',
        'direction' => 'ltr',
    ]);
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
        authKey: 'ZZZ',
    ));

    expect($result['email']->getTextBody())->not->toContain('<html')
        ->and($result['email']->getTextBody())->not->toContain('<!DOCTYPE')
        ->and($result['email']->getHtmlBody())->toContain('<html');
});

test('mail keys its per-request template cache by lang_info[code] too, not reusing one language\'s direction/translations for another', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = true;
    $service = mail_service_test_build();

    LangTestFactory::get()->setLangInfo([
        'code' => 'en',
        'direction' => 'ltr',
    ]);
    $result1 = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
    ));

    LangTestFactory::get()->setLangInfo([
        'code' => 'ar',
        'direction' => 'rtl',
    ]);
    $result2 = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
    ));

    expect($result1['email']->getHtmlBody())->toContain('dir="ltr"');
    expect($result2['email']->getHtmlBody())->toContain('dir="rtl"');
});

test('mail keys its per-request template cache by theme too, not reusing one theme\'s CSS for another', function (): void {
    // Real bug: the cache key
    // never included $args['theme'] even
    // though the cached entry's own CSS file selection depends on it --
    // present identically in the legacy procedural functions_mail.inc.php
    // and 16.x-rewrite's own MailService, a genuine cross-codebase bug,
    // not a rewrite regression. Emogrifier lowercases inlined hex colors.
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = true;
    LangTestFactory::get()->setLangInfo([
        'code' => 'en',
        'direction' => 'ltr',
    ]);
    $service = mail_service_test_build();

    $clearResult = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
        theme: 'clear',
    ));
    $darkResult = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
        theme: 'dark',
    ));

    $clearHtmlBody = $clearResult['email']->getHtmlBody();
    $darkHtmlBody = $darkResult['email']->getHtmlBody();
    if (! is_string($clearHtmlBody) || ! is_string($darkHtmlBody)) {
        throw new RuntimeException('expected both emails to have a real, string html body');
    }
    $clearBody = strtolower($clearHtmlBody);
    $darkBody = strtolower($darkHtmlBody);

    expect($clearBody)
        ->toContain('#e06900') // clear-theme-only accent color
        ->and($clearBody)
        ->not->toContain('#c9224c') // dark-theme-only accent color
        ->and($darkBody)
        ->toContain('#c9224c')
        ->and($darkBody)
        ->not->toContain('#e06900');
});

test('mail inlines the global mail CSS into the html part\'s elements, on top of the selected theme\'s own CSS', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = true;
    LangTestFactory::get()->setLangInfo([
        'code' => 'en',
        'direction' => 'ltr',
    ]);
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
    ));

    expect($result['email']->getHtmlBody())->toContain('Verdana');
});

test('mail computes GALLERY_URL as a genuine absolute URL (setMakeFullUrl active), not the bare relative path', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    $service = mail_service_test_build();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
    ));

    expect($result['email']->getTextBody())->toMatch('/Sent by "[^"]*" http/');
});

test('mail Bcc\'s the webmaster with an explicitly empty name, not a null/missing one', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    CurrentConfigTestFactory::get()->sendBccMailWebmaster = true;
    $service = mail_service_with_fake_webmaster();

    $result = mail_service_capture_send($service, 'bob@example.test', new MailArgs(
        subject: 'x',
        content: 'y',
    ));

    expect($result['email']->getBcc()[0]->getName())->toBe('');
});

test('mail defaults the smtp port to 25 when smtp_host has no explicit ":port" suffix', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    // No port given (unlike every other smtp_host test in this file, which
    // always supplies "host:port") -- distinguishes str_contains($host, ':')
    // actually gating the split, not just always splitting. This
    // deliberately can't bind port 25 for a real fake-server test (a
    // privileged port under 1024) -- the real Transport's own connection
    // failure message reveals the exact host:port it tried, which is
    // enough to prove the default was applied.
    CurrentConfigTestFactory::get()->smtpHost = '127.0.0.1';
    $capturedWarning = null;
    set_error_handler(function (int $errno, string $errstr) use (&$capturedWarning): bool {
        $capturedWarning = $errstr;

        return true;
    });

    try {
        $service = mail_service_test_build();
        $service->mail('bob@example.test', new MailArgs(
            subject: 'x',
            content: 'y',
        ));
    } finally {
        restore_error_handler();
    }

    expect($capturedWarning)
        ->toContain('127.0.0.1:25');
});

test('mail actually reaches a real Transport and sends when no before_send_mail listener intercepts it', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    [$proc, $markerFile, $port] = mail_service_start_fake_smtp('success');
    CurrentConfigTestFactory::get()->smtpHost = '127.0.0.1:' . $port;

    try {
        $service = mail_service_test_build();
        $ret = $service->mail('bob@example.test', new MailArgs(
            subject: 'x',
            content: 'y',
        ));

        // A default `true` `before_send_mail` result (no listener attached)
        // and $ret's own `true` initial value would be indistinguishable
        // from a mutant that skips the whole send block outright -- the
        // marker file only exists if the real fake server actually
        // accepted a real connection, proving the send genuinely happened.
        expect($ret)
            ->toBeTrue();
        expect(file_exists($markerFile))
            ->toBeTrue();
    } finally {
        mail_service_stop_fake_smtp($proc, $markerFile);
    }
});

test('mail returns false and logs a Mailer Error when the real Transport rejects the message', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentConfigTestFactory::get()->mailSenderEmail = 'sender@example.test';
    CurrentConfigTestFactory::get()->mailAllowHtml = false;
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    [$proc, $markerFile, $port] = mail_service_start_fake_smtp('reject_rcpt');
    CurrentConfigTestFactory::get()->smtpHost = '127.0.0.1:' . $port;

    // The rejected RCPT also makes Symfony's own EsmtpTransport attempt a
    // post-failure write (RESET/QUIT) against a connection this disposable
    // server has already closed -- a second, unrelated "Broken pipe"
    // warning follows the real one deterministically, so every warning is
    // collected rather than just the last.
    $capturedWarnings = [];
    set_error_handler(function (int $errno, string $errstr) use (&$capturedWarnings): bool {
        $capturedWarnings[] = $errstr;

        return true;
    });

    try {
        $service = mail_service_test_build();
        $ret = $service->mail('bob@example.test', new MailArgs(
            subject: 'x',
            content: 'y',
        ));

        expect($ret)
            ->toBeFalse();
        // Exact "Mailer Error: <real transport message>" prefix (not just
        // toContain('Mailer Error') anywhere) -- kills the concatenation's
        // own ConcatSwitchSides, which would put the transport's real
        // message BEFORE the "Mailer Error: " label instead of after it.
        $realWarning = current(array_filter($capturedWarnings, static fn (string $w): bool => str_starts_with($w, 'Mailer Error: ')));
        expect($realWarning)
            ->not->toBeFalse();
        expect($realWarning)
            ->toContain('Mailer Error: Expected response code');
    } finally {
        restore_error_handler();
        mail_service_stop_fake_smtp($proc, $markerFile);
    }
});

test('switchLangTo pushes the current language and translations, switchLangBack fully restores them (not just CurrentUser::language)', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentUserTestFactory::get()->set(User::fromUserArray([
        'id' => 1,
        'username' => 'tester',
        'status' => 'normal',
        'language' => 'en_UK',
    ]));
    LangTestFactory::get()->setLangInfo([
        'code' => 'en_UK_marker',
    ]);
    $service = mail_service_test_build();

    $service->switchLangTo('fr_FR');
    expect(CurrentUserTestFactory::get()->get()->language)->toEqual(LangCode::from('fr_FR'));
    expect(LangTestFactory::get()->langInfo()['code'] ?? null)->toBe('fr');

    $service->switchLangBack();

    // Kills reset()'s own FalseToTrue on $switchLangInitialised: if that
    // flag started `true` instead of `false`, the very first switchLangTo()
    // call above would skip saving *this* test's own original lang_info
    // snapshot (considering it "already initialised"), so switchLangBack()
    // would have nothing real to restore it from.
    expect(CurrentUserTestFactory::get()->get()->language)->toEqual(LangCode::from('en_UK'));
    expect(LangTestFactory::get()->langInfo()['code'] ?? null)->toBe('en_UK_marker');
});

test('switchLangTo resets lang_info/the translation dictionary before reloading, rather than merging fresh data on top of stale state', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentUserTestFactory::get()->set(User::fromUserArray([
        'id' => 1,
        'username' => 'tester',
        'status' => 'normal',
        'language' => 'en_UK',
    ]));
    // Neither key below is ever set by a real language file -- if they
    // survive switchLangTo('fr_FR'), the reset (not the reload itself)
    // was skipped: Lang::load()'s own internal array_merge($old, $fresh)
    // would otherwise carry stale keys forward indefinitely.
    LangTestFactory::get()->setLangInfo([
        'code' => 'xx',
        'my_custom_marker' => 'stale',
    ]);
    LangTestFactory::get()->loadArray([
        'my_data_marker_key' => 'stale-data',
    ]);
    $service = mail_service_test_build();

    $service->switchLangTo('fr_FR');

    expect(LangTestFactory::get()->langInfo())->not->toHaveKey('my_custom_marker');
    expect(LangTestFactory::get()->has('my_data_marker_key'))->toBeFalse();
});

test('switchLangTo fires the loading_lang event while reloading a language for the first time', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentUserTestFactory::get()->set(User::fromUserArray([
        'id' => 1,
        'username' => 'tester',
        'status' => 'normal',
        'language' => 'en_UK',
    ]));
    $service = mail_service_test_build();

    $fired = false;
    $handler = function () use (&$fired): void {
        $fired = true;
    };
    EventDispatcherTestFactory::get()->addTypedHandler(LoadingLang::class, $handler);
    try {
        $service->switchLangTo('fr_FR');
    } finally {
        EventDispatcherTestFactory::get()->removeTypedHandler(LoadingLang::class, $handler);
    }

    expect($fired)
        ->toBeTrue();
});

test('switchLangTo/switchLangBack nest in real LIFO order across more than one push', function (): void {
    // Kills switchLangBack()'s own ArrayPopToArrayShift: a single-push
    // round trip can't distinguish pop (LIFO) from shift (FIFO) since
    // there's only one element either way -- this needs two nested pushes.
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentUserTestFactory::get()->set(User::fromUserArray([
        'id' => 1,
        'username' => 'tester',
        'status' => 'normal',
        'language' => 'en_UK',
    ]));
    $service = mail_service_test_build();

    $service->switchLangTo('fr_FR');
    $service->switchLangTo('de_DE');
    expect(CurrentUserTestFactory::get()->get()->language)->toEqual(LangCode::from('de_DE'));

    $service->switchLangBack();
    expect(CurrentUserTestFactory::get()->get()->language)->toEqual(LangCode::from('fr_FR'));

    $service->switchLangBack();
    expect(CurrentUserTestFactory::get()->get()->language)->toEqual(LangCode::from('en_UK'));
});

test('switchLangTo reuses its own cache for a language already switched to once, without reloading language files again', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentUserTestFactory::get()->set(User::fromUserArray([
        'id' => 1,
        'username' => 'tester',
        'status' => 'normal',
        'language' => 'en_UK',
    ]));
    $service = mail_service_test_build();
    $service->switchLangTo('fr_FR');
    $service->switchLangBack();

    $loadingLangCalls = 0;
    $handler = function () use (&$loadingLangCalls): void {
        $loadingLangCalls++;
    };
    EventDispatcherTestFactory::get()->addTypedHandler(LoadingLang::class, $handler);
    try {
        $service->switchLangTo('fr_FR');
    } finally {
        EventDispatcherTestFactory::get()->removeTypedHandler(LoadingLang::class, $handler);
    }

    expect($loadingLangCalls)
        ->toBe(0);
    expect(LangTestFactory::get()->langInfo()['code'] ?? null)->toBe('fr');
});

test('switchLangTo only ever snapshots the ORIGINAL starting language once per request, not once per distinct language switched away from', function (): void {
    // "Considered OK on first call" (this method's own comment): once
    // $switchLangInitialised flips true, switching away from a SECOND,
    // never-before-seen starting language does NOT capture a fresh
    // snapshot for it -- switchLangBack() then has nothing real to
    // restore for that second language and leaves lang_info as whatever
    // the target language's own reload produced. Deliberate existing
    // behavior, not a bug -- this test pins it down so a mutation that
    // widened the guard (re-snapshotting on every distinct language)
    // can't slip through unnoticed.
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentUserTestFactory::get()->set(User::fromUserArray([
        'id' => 1,
        'username' => 'tester',
        'status' => 'normal',
        'language' => 'en_UK',
    ]));
    $service = mail_service_test_build();

    LangTestFactory::get()->setLangInfo([
        'code' => 'marker-en',
    ]);
    $service->switchLangTo('fr_FR');
    $service->switchLangBack();

    CurrentUserTestFactory::get()->updateLanguage(LangCode::from('de_DE'));
    LangTestFactory::get()->setLangInfo([
        'code' => 'marker-de',
    ]);
    $service->switchLangTo('es_ES');
    $service->switchLangBack();

    expect(LangTestFactory::get()->langInfo()['code'] ?? null)->not->toBe('marker-de');
    expect(CurrentUserTestFactory::get()->get()->language)->toEqual(LangCode::from('de_DE'));
});

test('switchLangTo replays every plugin language file already loaded this request, in the newly-switched-to language', function (): void {
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    CurrentUserTestFactory::get()->set(User::fromUserArray([
        'id' => 1,
        'username' => 'tester',
        'status' => 'normal',
        'language' => 'en_UK',
    ]));
    $dir = sys_get_temp_dir() . '/piwigo-mailservice-plugin-test-' . (string) getmypid() . '/';

    try {
        mail_service_write_po($dir . 'language/fr_FR/plugin.po', 'Marqueur du plugin');
        // A real Lang::load() call for a plugin ($dirname non-empty) both
        // loads it AND registers it into Lang::languageFiles() -- the
        // exact list switchLangTo()'s own plugin-file replay loop reads.
        LangTestFactory::get()->load('plugin.lang', $dir, [
            'language' => 'fr_FR',
        ]);

        mail_service_write_po($dir . 'language/de_DE/plugin.po', 'Deutscher Marker');
        $service = mail_service_test_build();

        $service->switchLangTo('de_DE');

        expect(TranslatorTestFactory::get()->translate('MailServiceTestPluginMarker'))->toBe('Deutscher Marker');
    } finally {
        mail_service_rrmdir($dir);
    }
});

test('buildMailer wraps native://default in the bounded sendmail transport, but leaves any other DSN to Symfony\'s own Transport::fromDsn', function (): void {
    $service = mail_service_test_build();
    $buildMailer = new ReflectionMethod($service, 'buildMailer');
    $transportProperty = new ReflectionProperty(Mailer::class, 'transport');

    $nativeMailer = $buildMailer->invoke($service, 'native://default');
    if (! $nativeMailer instanceof Mailer) {
        throw new RuntimeException('expected buildMailer() to return a Mailer');
    }
    expect($transportProperty->getValue($nativeMailer))
        ->toBeInstanceOf(BoundedSendmailTransport::class);

    $smtpMailer = $buildMailer->invoke($service, 'smtp://127.0.0.1:2525');
    if (! $smtpMailer instanceof Mailer) {
        throw new RuntimeException('expected buildMailer() to return a Mailer');
    }
    expect($transportProperty->getValue($smtpMailer))
        ->not->toBeInstanceOf(BoundedSendmailTransport::class);
});

// moveCssToBody's own early `$content === ''` return (line 1027-1029) is
// confirmed-equivalent for BOTH its own mutations (EmptyStringToNotEmpty,
// RemoveEarlyReturn): the existing "empty
// string unchanged" test above still passes unchanged either way, because
// Pelago\Emogrifier\CssInliner::fromHtml('') itself throws
// InvalidArgumentException("The provided HTML must not be empty.") for a
// genuinely empty input, which the very next line's catch(\Exception)
// block silently swallows and returns the original (still-empty) $content
// -- the exact same result the early return would have produced. There is
// no input that distinguishes "return early" from "fall through, throw,
// and get caught" here: they converge on the identical output.
