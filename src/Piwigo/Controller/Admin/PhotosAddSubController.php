<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\PhotosAddApplicationsPageRenderer;
use Piwigo\Admin\PhotosAddDirectPageRenderer;
use Piwigo\Admin\PhotosAddFtpPageRenderer;
use Piwigo\Admin\tabsheet;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Template\Template;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/photos_add.php's own tab-dispatch shell (page slug
 * "photos_add"). The 3 tab bodies are typed renderers:
 * PhotosAddDirectPageRenderer ("direct", P23 batch 6e, folding in
 * photos_add_direct_prepare.inc.php's form-prep body too) /
 * PhotosAddApplicationsPageRenderer / PhotosAddFtpPageRenderer -- pure
 * template/form display, no security-sensitive logic; the real upload
 * pipeline they call into (add_uploaded_file() et al) is fully migrated
 * to UploadService (see that class + its own SEC-16/SEC-21 fixes).
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch, so the
 * original photos_add.php's own (redundant) check_status() call is
 * dropped here.
 */
final class PhotosAddSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        /**
         * @var Template $template
         * @var array<string, mixed> $page
         */
        global $template, $page;

        include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        include_once PHPWG_ROOT_PATH . 'admin/include/functions_upload.inc.php';
        // define()'d there instead of here -- an arch test forbids define()
        // calls under src/Piwigo/ (see that file's own docblock).
        include_once PHPWG_ROOT_PATH . 'admin/photos_add.php';

        // upload form config is loaded here to match the original page's
        // own behavior (validated/used by the tab templates), even though
        // this sub-controller doesn't read it directly itself.
        new UploadService()
            ->getUploadFormConfig();

        // admin.php's own shared check_input_parameter('section', ...,
        // '/^[a-z]+[a-z_\/-]*(\.php)?$/i') already runs before dispatch and
        // blocks any '.' other than one trailing ".php" -- real path
        // traversal is already unreachable. This sub-controller still
        // never spliced $_GET['section'] straight into the include path
        // (unlike the original photos_add.php, which did): a tighter
        // 3-value allowlist is real defense-in-depth, not a fix for an
        // actively-exploitable hole.
        $section = $request->getQueryParams()['section'] ?? null;
        $tab = is_string($section) ? $section : 'direct';

        // backward compatibility
        if ($tab === 'ploader') {
            $tab = 'applications';
        }

        if (! in_array($tab, ['direct', 'applications', 'ftp'], true)) {
            $tab = 'direct';
        }

        $page['tab'] = $tab;

        $tabsheet = new tabsheet();
        $tabsheet->set_id('photos_add');
        $tabsheet->select($tab);
        $tabsheet->assign();

        $template->set_filenames([
            'photos_add' => 'photos_add_' . $tab . '.tpl',
        ]);

        if ($tab === 'direct') {
            new PhotosAddDirectPageRenderer()
                ->render();
        } elseif ($tab === 'applications') {
            new PhotosAddApplicationsPageRenderer()
                ->render();
        } else {
            new PhotosAddFtpPageRenderer()
                ->render();
        }
    }
}
