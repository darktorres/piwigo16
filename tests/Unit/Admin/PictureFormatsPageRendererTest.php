<?php

declare(strict_types=1);

use Piwigo\Admin\PictureFormatsPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Image\ImageStdParams;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Validation\InputValidator;

/**
 * Piwigo\Admin\PictureFormatsPageRenderer -- zero-constructor, every
 * dependency passed as a render() param (B3 Tier 1 shape). No dedicated
 * Integration/Browser spec -- reached only via the "picture_formats" page
 * slug.
 *
 * Only the 2 fatalError() guard branches (missing image_id, image_id not
 * found) are covered here -- same "branches reachable without a DB row"
 * scoping PictureCommentRendererTest.php already established. The real
 * happy path additionally needs a real piwigo_image_format row AND a
 * working DerivativeImage::url() (real ImageStdParams + on-disk source
 * image), which starts to look like an Integration test in Unit's
 * clothing for marginal extra branch coverage over the 2 guards below.
 *
 * The real (non-fake) HtmlService is used throughout, not a fake --
 * HtmlService::fatalError()/accessDenied() both throw a real, catchable
 * ResponseReadyException in test context (PHP 8.4 deprecates
 * E_USER_ERROR), so a real admin CurrentUser passing checkStatus() and a
 * real fatalError() throw are both directly observable without any
 * doubles beyond AccessControlTestFakeRedirectServiceNeverCalled (proves
 * the access grant path never redirects).
 */
function pictureFormatsTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-picture-formats-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->setDataLocation('data/');
    CurrentConfigTestFactory::get()->setDataDirChecked('1');

    return $root;
}

function pictureFormatsTestRrmdir(string $dir): void
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
        is_dir($path) ? pictureFormatsTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function pictureFormatsTestAccessControl(): AccessControl
{
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $currentUser->set(new User(
        id: UserId::from(1),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: '',
        status: UserStatus::Admin,
        enabledHigh: false,
    ));

    return new AccessControl(
        HtmlServiceTestFactory::build(),
        new AccessControlTestFakeRedirectServiceNeverCalled(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );
}

function pictureFormatsTestImageStdParams(): ImageStdParams
{
    $imageStdParams = Kernel::container()->get(ImageStdParams::class);
    if (! $imageStdParams instanceof ImageStdParams) {
        throw new LogicException('Container returned an unexpected type for ' . ImageStdParams::class);
    }

    return $imageStdParams;
}

test('render() fatal-errors when image_id is missing from the request', function (): void {
    $root = pictureFormatsTestRoot();
    unset($_GET['image_id']);

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);

        $exception = null;
        try {
            new PictureFormatsPageRenderer()->render(LangTestFactory::get(), pictureFormatsTestAccessControl(), UrlServiceTestFactory::build(), pictureFormatsTestImageStdParams(), CurrentTemplate::current(), HtmlServiceTestFactory::build(), new InputValidator(), CurrentConfigTestFactory::get());
        } catch (ResponseReadyException $e) {
            $exception = $e;
        }

        expect($exception)->toBeInstanceOf(ResponseReadyException::class);
        if (! $exception instanceof ResponseReadyException) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }
        expect((string) $exception->response()->getBody())->toContain('image_id does not exist');
    } finally {
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        pictureFormatsTestRrmdir($root);
    }
});

test('render() fatal-errors when image_id does not match any real image', function (): void {
    $root = pictureFormatsTestRoot();
    $_GET['image_id'] = '999999';

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);

        $exception = null;
        try {
            new PictureFormatsPageRenderer()->render(LangTestFactory::get(), pictureFormatsTestAccessControl(), UrlServiceTestFactory::build(), pictureFormatsTestImageStdParams(), CurrentTemplate::current(), HtmlServiceTestFactory::build(), new InputValidator(), CurrentConfigTestFactory::get());
        } catch (ResponseReadyException $e) {
            $exception = $e;
        }

        expect($exception)->toBeInstanceOf(ResponseReadyException::class);
        if (! $exception instanceof ResponseReadyException) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }
        expect((string) $exception->response()->getBody())->toContain('image_id #999999 does not exist');
    } finally {
        unset($_GET['image_id']);
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        pictureFormatsTestRrmdir($root);
    }
});
