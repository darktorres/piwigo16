<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\PictureFormatsSubController;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Image\ImageStdParams;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Validation\InputValidator;

/**
 * Piwigo\Controller\Admin\PictureFormatsSubController -- a genuinely thin
 * delegate, all 8 constructor deps standard/already-factory-covered. No
 * dedicated Integration/Browser spec of its own.
 *
 * Reuses PictureFormatsPageRendererTest.php's own missing-image_id guard
 * scoping, just reached through handle() instead of a direct render()
 * call.
 */
function pictureFormatsSubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-picture-formats-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->setDataLocation('data/');
    CurrentConfigTestFactory::get()->setDataDirChecked('1');

    return $root;
}

function pictureFormatsSubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? pictureFormatsSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function pictureFormatsSubControllerTestAccessControl(): AccessControl
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

function pictureFormatsSubControllerTestImageStdParams(): ImageStdParams
{
    $imageStdParams = Kernel::container()->get(ImageStdParams::class);
    if (! $imageStdParams instanceof ImageStdParams) {
        throw new LogicException('Container returned an unexpected type for ' . ImageStdParams::class);
    }

    return $imageStdParams;
}

test('handle() delegates to PictureFormatsPageRenderer::render(), which fatal-errors when image_id is missing', function (): void {
    $root = pictureFormatsSubControllerTestRoot();
    unset($_GET['image_id']);

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);

        $subController = new PictureFormatsSubController(
            LangTestFactory::get(),
            pictureFormatsSubControllerTestAccessControl(),
            UrlServiceTestFactory::build(),
            pictureFormatsSubControllerTestImageStdParams(),
            CurrentTemplate::current(),
            HtmlServiceTestFactory::build(),
            new InputValidator(),
            CurrentConfigTestFactory::get(),
        );

        $exception = null;
        try {
            $subController->handle(new ServerRequest('GET', '/admin.php'));
        } catch (ResponseReadyException $e) {
            $exception = $e;
        }

        expect($exception)
            ->toBeInstanceOf(ResponseReadyException::class);
        if (! $exception instanceof ResponseReadyException) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }
        expect((string) $exception->response()->getBody())
            ->toContain('image_id does not exist');
    } finally {
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        pictureFormatsSubControllerTestRrmdir($root);
    }
});
