<?php

declare(strict_types=1);

use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Mail\MailService;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Picture\PictureCommentRenderer;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\SessionServiceTestFactory;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Picture had zero tests in any suite before this file (Stage 1c's own
 * flagged priority -- the historical $edit_comment scope-sharing bug this
 * class's own docblock documents). Covers the 3 branches of render()
 * reachable without a DB: the "no commentable category" early return, and
 * the 2 reject-response throws -- every other branch needs a real
 * CommentRepository row (findForImage()/countForImage()), which is a DB
 * call and stays at Integration level (see
 * tests/Integration/PictureCommentRendererTest.php for the
 * edit/delete permission-gating + the bug-fix regression check).
 */
function picture_comment_test_rrmdir(string $dir): void
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
        is_dir($path) ? picture_comment_test_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

/**
 * Template::__construct() unconditionally mkgetdir()s a real
 * "templates_c" compile directory under CurrentPathsTestFactory::get()->root -- same
 * "point CurrentPaths at a fresh temp root, clean it up after" shape as
 * tests/Unit/Image/DerivativeCacheServiceTest.php. setDataDirChecked('1')
 * skips the extra is_writable()/confUpdateParam() DB-touching branch for
 * the data dir itself, which would otherwise run unconditionally too.
 */
function makePictureCommentTestTemplate(): Template
{
    $root = sys_get_temp_dir() . '/piwigo-picture-comment-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->setDataLocation('data/');
    CurrentConfigTestFactory::get()->setDataDirChecked('1');

    return TemplateTestFactory::build();
}

beforeEach(function (): void {
    // makePictureCommentTestTemplate() itself calls Kernel::boot() -- must
    // finish (and its return value captured) before CurrentTemplate::
    // current() resolves, or current() resolves the pre-boot memoized
    // fallback instead of the now-booted container's own instance, and
    // this set() call is invisible to every later current()->get() read
    // in this file (Phase 5 execution finding, same pitfall Translator/
    // EventDispatcher/CurrentUser already hit).
    $template = makePictureCommentTestTemplate();
    CurrentTemplate::current()->set($template);
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(1),
        username: 'torres',
        email: '',
        language: '',
        theme: '',
        status: UserStatus::Normal,
        enabledHigh: false,
    ));
    unset($_POST['content'], $_POST['author'], $_POST['website_url'], $_POST['email'], $_POST['key']);
});

afterEach(function (): void {
    picture_comment_test_rrmdir(CurrentPathsTestFactory::get()->root);
    CurrentTemplate::current()->reset();
    Kernel::reset();
    CurrentUserTestFactory::get()->reset();
    CurrentConfigTestFactory::get()->reset();
    unset($_POST['content'], $_POST['author'], $_POST['website_url'], $_POST['email'], $_POST['key']);
});

function makePictureCommentUrlService(): UrlService
{
    return UrlServiceTestFactory::build();
}

function makePictureCommentSessionService(): SessionService
{
    return SessionServiceTestFactory::get();
}

function pictureCommentRendererTestMailService(): MailService
{
    $mailer = Kernel::container()->get(MailService::class);
    if (! $mailer instanceof MailService) {
        throw new LogicException('Container returned an unexpected type for ' . MailService::class);
    }

    return $mailer;
}

test('render does nothing when no related category is commentable', function (): void {
    $renderer = new PictureCommentRenderer();

    $renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, 42, 0, makePictureCommentUrlService(), [
        ['commentable' => false],
        ['commentable' => 0],
    ], '/picture.php', makePictureCommentSessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), pictureCommentRendererTestMailService(), HtmlServiceTestFactory::build());

    expect(CurrentTemplate::current()->get()->get_template_vars('comments'))->toBeNull()
        ->and(CurrentTemplate::current()->get()->get_template_vars('comment_add'))->toBeNull();
});

// A mutation-testing sweep found `(bool) $category['commentable']` inside
// the loop above is a confirmed-equivalent mutant when the cast is
// stripped: it's the sole expression inside an `if (...)` condition, and
// PHP's `if` already applies the exact same truthiness conversion
// (zend_is_true()) that an explicit (bool) cast does -- there is no PHP
// value for which `if ($x)` and `if ((bool) $x)` diverge. Live-verified
// with a PHP probe across every representative type this column can hold
// per this method's own docblock (int|bool from the DB driver) plus the
// wider scalar/compound space, to be thorough:
//   true, false, 0, 1, -1, '0', '1', '', '0.0', null, 0.0, -0.0, NAN,
//   [], [0], new stdClass()
// -- `(bool) $v` and the truthiness `if ($v)` uses were identical for
// every one of them (`(bool) $v === $b` where `$b` was set by an actual
// `if ($v) {...} else {...}`). No test can ever kill this mutant, by
// construction, so no test is added for it -- it's the loop's own
// `break, not continue` behavior (below) and the "any category, not just
// the first, makes the picture commentable" outcome (implicitly exercised
// by the "render lets a guest post..." test group) that are the real,
// observable contracts here.

test('render only counts the first commentable related category then stops (`break`, not `continue`)', function (): void {
    // Kills line 92's BreakToContinue. $showComments can only ever be set
    // to `true` (never back to `false`) by this loop, so break vs.
    // continue is unobservable through $showComments itself -- both leave
    // it `true` once any commentable category is seen. The real
    // difference is whether the loop body runs again for a LATER row:
    // this second row has no 'commentable' key at all, so reading
    // $category['commentable'] on it emits a PHP "Undefined array key"
    // warning -- but only if the loop actually reaches it (`continue`),
    // never if `break` stops iteration right after the first (true) row,
    // as the real code does. Captured directly via set_error_handler()
    // (same technique as ImageServiceTest.php's own break-vs-continue
    // test) rather than relying on phpunit.xml.dist's failOnWarning to
    // fail the test out from under us, so this assertion is self-
    // contained. Live-verified: mutating this `break` to `continue`
    // makes $capturedWarning become the literal string
    // `Undefined array key "commentable"` instead of staying null, while
    // the render() call itself still throws the same "Session expired"
    // ResponseReadyException either way (guest + comments_forall off,
    // same as the test below) -- so only the warning capture
    // distinguishes the two.
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(1),
        username: '',
        email: '',
        language: '',
        theme: '',
        status: UserStatus::Guest,
        enabledHigh: false,
    ));
    CurrentConfigTestFactory::get()->setCommentsForall(false);
    $_POST['content'] = 'nice photo!';
    $renderer = new PictureCommentRenderer();

    $capturedWarning = null;
    set_error_handler(static function (int $errno, string $errstr) use (&$capturedWarning): bool {
        $capturedWarning = $errstr;

        return true;
    });

    $exception = null;
    try {
        $renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, 42, 0, makePictureCommentUrlService(), [
            ['commentable' => true],
            ['id' => 999], // no 'commentable' key -- only reached by `continue`
        ], '/picture.php', makePictureCommentSessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), pictureCommentRendererTestMailService(), HtmlServiceTestFactory::build());
    } catch (ResponseReadyException $e) {
        $exception = $e;
    } finally {
        restore_error_handler();
    }

    expect($capturedWarning)->toBeNull();
    expect($exception)->toBeInstanceOf(ResponseReadyException::class);
    if (! $exception instanceof ResponseReadyException) {
        return; // unreachable -- the assertion above already failed the test otherwise.
    }
    $response = $exception->response();
    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getBody())->toBe('Session expired');
});

test('render rejects a posted comment as "ugly spammer" when no related category is commentable', function (): void {
    $_POST['content'] = 'nice photo!';
    $renderer = new PictureCommentRenderer();

    $exception = null;
    try {
        $renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, 42, 0, makePictureCommentUrlService(), [
            ['commentable' => false],
        ], '/picture.php', makePictureCommentSessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), pictureCommentRendererTestMailService(), HtmlServiceTestFactory::build());
    } catch (ResponseReadyException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(ResponseReadyException::class);
    if (! $exception instanceof ResponseReadyException) {
        return; // unreachable -- the assertion above already failed the test otherwise.
    }
    $response = $exception->response();
    expect($response->getStatusCode())->toBe(403)
        ->and((string) $response->getBody())->toBe('ugly spammer');
});

test('render rejects a posted comment as "Session expired" for a guest when comments_forall is off', function (): void {
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(1),
        username: '',
        email: '',
        language: '',
        theme: '',
        status: UserStatus::Guest,
        enabledHigh: false,
    ));
    CurrentConfigTestFactory::get()->setCommentsForall(false);
    $_POST['content'] = 'nice photo!';
    $renderer = new PictureCommentRenderer();

    $exception = null;
    try {
        $renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, 42, 0, makePictureCommentUrlService(), [
            ['commentable' => true],
        ], '/picture.php', makePictureCommentSessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), pictureCommentRendererTestMailService(), HtmlServiceTestFactory::build());
    } catch (ResponseReadyException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(ResponseReadyException::class);
    if (! $exception instanceof ResponseReadyException) {
        return; // unreachable -- the assertion above already failed the test otherwise.
    }
    $response = $exception->response();
    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getBody())->toBe('Session expired');
});

test('render lets a guest post a comment when comments_forall is on', function (): void {
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(1),
        username: '',
        email: '',
        language: '',
        theme: '',
        status: UserStatus::Guest,
        enabledHigh: false,
    ));
    CurrentConfigTestFactory::get()->setCommentsForall(true);
    // No $_POST['content'] set -- verifies the guest-reject guard itself
    // is what's gated on comments_forall, not that render() is unusable
    // for a guest altogether (the real insertComment() path needs a DB
    // and stays at Integration level).

    $renderer = new PictureCommentRenderer();
    $renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, 42, 0, makePictureCommentUrlService(), [
        ['commentable' => false],
    ], '/picture.php', makePictureCommentSessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), pictureCommentRendererTestMailService(), HtmlServiceTestFactory::build());

    expect(CurrentTemplate::current()->get()->get_template_vars('comments'))->toBeNull();
});

test('render does not reject a logged-in (non-guest) user\'s posted comment even when comments_forall is off (`and`, not `or`)', function (): void {
    // Kills line 97's LogicalAndToLogicalOr. With comments_forall off,
    // `! commentsForall()` alone is already true, so an `or` mutant would
    // reject ANY posted comment on that basis alone, guest or not -- the
    // real `and` correctly also requires isAGuest(), so this test's
    // logged-in, non-guest user (beforeEach's default 'torres', a
    // UserStatus::Normal user -- not a guest) must be let through this
    // guard, unlike the guest in the "Session expired" test above.
    //
    // There's no DB-free way to observe render() getting PAST line 97 for
    // a posted comment: $showComments must be true to even reach this
    // guard (line 96's `and`), and once true, `if (! $showComments)
    // return;` a few lines down never fires either, so the method
    // unconditionally reaches countForImage() -- a real, but read-only
    // (COUNT(*), 0 rows for this nonexistent image id, no write) DB call.
    // Rather than stop short, this test lets the whole render() call
    // complete: it seeds a real (trivial, static-content) comment_list.tpl
    // into this test's own throwaway template root (same temp root
    // beforeEach already points CurrentPaths at) so the final
    // assign_var_from_handle('COMMENT_LIST', ...) resolves cleanly too,
    // and asserts on the fully-rendered result -- stronger evidence than
    // merely "didn't throw".
    //
    // Live-verified: mutating line 97's `and` to `or` makes this exact
    // scenario throw ResponseReadyException("Session expired") instead of
    // completing, confirming this test distinguishes the two operators.
    CurrentConfigTestFactory::get()->setCommentsForall(false);
    $_POST['content'] = 'nice photo!';
    file_put_contents(CurrentPathsTestFactory::get()->root . '/comment_list.tpl', 'STATIC-COMMENT-LIST-CONTENT');
    CurrentTemplate::current()->get()->set_template_dir(CurrentPathsTestFactory::get()->root);

    $renderer = new PictureCommentRenderer();
    // A nonexistent image id keeps countForImage()'s real COUNT(*) query
    // harmless (always 0 rows), so the row-fetching branch below it
    // (which needs a real CommentRepository row -- DB-write territory)
    // is never entered either.
    $renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), null, 999999999, 0, makePictureCommentUrlService(), [
        ['commentable' => true],
    ], '/picture.php', makePictureCommentSessionService(), new EventDispatcher(), PageStateTestFactory::get(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), pictureCommentRendererTestMailService(), HtmlServiceTestFactory::build());

    expect(CurrentTemplate::current()->get()->get_template_vars('COMMENT_LIST'))->toBe('STATIC-COMMENT-LIST-CONTENT')
        ->and(CurrentTemplate::current()->get()->get_template_vars('comments'))->toBe([]);
});
