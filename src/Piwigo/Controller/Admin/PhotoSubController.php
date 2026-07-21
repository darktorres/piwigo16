<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\PictureCoiPageRenderer;
use Piwigo\Admin\PictureFormatsPageRenderer;
use Piwigo\Admin\PictureModifyPageRenderer;
use Piwigo\Admin\Tabsheet;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Html\HtmlService;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/photo.php's own tab-dispatch shell (page slug "photo").
 * Its 3 tab bodies are all typed renderers: PictureModifyPageRenderer
 * ("properties", P23 batch 6d), PictureCoiPageRenderer/
 * PictureFormatsPageRenderer ("coi"/"formats", already built in the earlier
 * Config batch and additionally directly reachable as their own top-level
 * `?page=` slugs). Same "fold the shell into the sub-controller, keep tab
 * bodies as their own classes" shape as AlbumSubController.
 *
 * The legacy admin/photo.php had a dynamic `include admin/photo_' .
 * $page['tab'] . '.php'` fallback for any tab outside the known 3 -- no
 * such file has ever existed on disk, and admin.php's own shared
 * check_input_parameter('tab', ..., '/^[a-zA-Z\d_-]+$/') already blocks
 * real path traversal on the 'tab' param before dispatch even reaches this
 * class, the same non-issue AlbumSubController's own docblock documents for
 * the identical pattern in admin/album.php. This class's own KNOWN_TABS
 * allowlist (with a safe 'properties' fallback) is real defense-in-depth,
 * not a fix for an actively-exploitable hole -- it also drops the dynamic
 * `include` entirely, so tab dispatch no longer depends on filesystem state.
 */
final class PhotoSubController implements AdminSubControllerInterface
{
    private const array KNOWN_TABS = ['properties', 'coi', 'formats'];

    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        // Phase 2 global-residual sweep: $page is a local scratch array
        // for this method's own body only (no longer `global $page;`),
        // same shape as Section\SectionPopulator::populate()'s own
        // equivalent fix (Track A5.2e).
        /** @var array<string, mixed> $page */
        $page = [];
        $template = \Piwigo\Template\CurrentTemplate::get();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        new \Piwigo\Validation\InputValidator()
            ->validate('cat_id', $_GET, false, ValidationPattern::ID);
        new \Piwigo\Validation\InputValidator()
            ->validate('image_id', $_GET, false, ValidationPattern::ID);

        // check_input_parameter() above already validated $_GET['image_id'] against
        // ValidationPattern::ID (digits only) when present; $_GET values are always strings
        // (or arrays, for foo[]=... params) so is_string() is the real narrowing.
        $get_image_id = is_string($_GET['image_id'] ?? null) ? $_GET['image_id'] : '';

        $GLOBALS['admin_photo_base_url'] = $this->urlService->getRootUrl() . 'admin.php?page=photo-' . $get_image_id;

        // retrieving direct information about picture
        $imageConn = DbConnection::build();
        $page['image'] = new ImageService(new ImageRepository($imageConn), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository($imageConn)))
            ->getImageInfos($get_image_id, new HtmlService(), true);

        $tab_param = $_GET['tab'] ?? null;
        $tab = is_string($tab_param) && in_array($tab_param, self::KNOWN_TABS, true) ? $tab_param : 'properties';

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('photo');
        $tabsheet->select($tab);
        $tabsheet->assign();

        $template->assign(
            [
                'ADMIN_PAGE_TITLE' => Lang::t('Edit photo') . ' <span class="image-id">#' . $get_image_id . '</span>',
            ]
        );

        if ($tab === 'properties') {
            new PictureModifyPageRenderer($this->redirectService, $this->urlService)
                ->render();
        } elseif ($tab === 'coi') {
            new PictureCoiPageRenderer($this->redirectService)
                ->render();
        } elseif (\Piwigo\Config\Config::isFormatsEnabled()) {
            new PictureFormatsPageRenderer()
                ->render($this->urlService);
        }
    }
}
