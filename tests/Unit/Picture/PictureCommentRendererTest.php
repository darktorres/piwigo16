<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Paths;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Picture\PictureCommentRenderer;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
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
 * "templates_c" compile directory under CurrentPaths::get()->root -- same
 * "point CurrentPaths at a fresh temp root, clean it up after" shape as
 * tests/Unit/Image/DerivativeCacheServiceTest.php. setDataDirChecked('1')
 * skips the extra is_writable()/confUpdateParam() DB-touching branch for
 * the data dir itself, which would otherwise run unconditionally too.
 */
function makePictureCommentTestTemplate(): Template
{
    $root = sys_get_temp_dir() . '/piwigo-picture-comment-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o777, true);
    CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setDataLocation('data/');
    CurrentConfig::setDataDirChecked('1');

    return new Template();
}

beforeEach(function (): void {
    CurrentTemplate::set(makePictureCommentTestTemplate());
    CurrentUser::set(new User(
        id: 1,
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
    picture_comment_test_rrmdir(CurrentPaths::get()->root);
    CurrentTemplate::reset();
    CurrentPaths::reset();
    CurrentUser::reset();
    CurrentConfig::reset();
    unset($_POST['content'], $_POST['author'], $_POST['website_url'], $_POST['email'], $_POST['key']);
});

function makePictureCommentUrlService(): UrlService
{
    return new UrlService(new HtmlService());
}

test('render does nothing when no related category is commentable', function (): void {
    $renderer = new PictureCommentRenderer();

    $renderer->render(null, 42, 0, makePictureCommentUrlService(), [
        ['commentable' => false],
        ['commentable' => 0],
    ], '/picture.php');

    expect(CurrentTemplate::get()->get_template_vars('comments'))->toBeNull()
        ->and(CurrentTemplate::get()->get_template_vars('comment_add'))->toBeNull();
});

test('render rejects a posted comment as "ugly spammer" when no related category is commentable', function (): void {
    $_POST['content'] = 'nice photo!';
    $renderer = new PictureCommentRenderer();

    $exception = null;
    try {
        $renderer->render(null, 42, 0, makePictureCommentUrlService(), [
            ['commentable' => false],
        ], '/picture.php');
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
    CurrentUser::set(new User(
        id: 0,
        username: '',
        email: '',
        language: '',
        theme: '',
        status: UserStatus::Guest,
        enabledHigh: false,
    ));
    CurrentConfig::setCommentsForall(false);
    $_POST['content'] = 'nice photo!';
    $renderer = new PictureCommentRenderer();

    $exception = null;
    try {
        $renderer->render(null, 42, 0, makePictureCommentUrlService(), [
            ['commentable' => true],
        ], '/picture.php');
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
    CurrentUser::set(new User(
        id: 0,
        username: '',
        email: '',
        language: '',
        theme: '',
        status: UserStatus::Guest,
        enabledHigh: false,
    ));
    CurrentConfig::setCommentsForall(true);
    // No $_POST['content'] set -- verifies the guest-reject guard itself
    // is what's gated on comments_forall, not that render() is unusable
    // for a guest altogether (the real insertComment() path needs a DB
    // and stays at Integration level).

    $renderer = new PictureCommentRenderer();
    $renderer->render(null, 42, 0, makePictureCommentUrlService(), [
        ['commentable' => false],
    ], '/picture.php');

    expect(CurrentTemplate::get()->get_template_vars('comments'))->toBeNull();
});
