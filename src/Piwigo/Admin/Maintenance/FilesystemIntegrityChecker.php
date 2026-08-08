<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance;

use Piwigo\Bootstrap\Projection\HeaderMessagesPageContext;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Image\ImageService;
use Piwigo\Template\CurrentTemplate;

/**
 * Narrow "check X, fix Y" utilities, distinct from the much larger,
 * general anomaly framework in Piwigo\Admin\Integrity\CheckIntegrity
 * (add_anomaly()/display()/maintenance()) -- not the same concern,
 * deliberately not merged in and deliberately named more specifically
 * to avoid confusion with it.
 *
 * A single container-shared instance is used by every real caller
 * (Controller\Admin\IntroSubController, Admin\MaintenanceActionsPageRenderer,
 * Admin\AdminShell, Admin\Maintenance\MaintenanceActionDispatcher), all of
 * which obtain it via constructor injection.
 *
 * Depends on `CurrentConfigService` (the wrapper), not plain
 * `ConfigService`, even though every real construction site here is
 * HTTP-admin-page-only (no install/CLI timing risk): this class is
 * resolved from the container by its own test
 * (`tests/Integration/FilesystemIntegrityCheckerTest.php`), which needs
 * to substitute a test-specific `ConfigService` (a deliberately
 * separate `EntityManager`, for Doctrine identity-map isolation from
 * the test's own raw-SQL writes) -- only the wrapper's `set()` reaches
 * a container-resolved consumer that way; direct `ConfigService`
 * injection would always receive the container's own default instance
 * instead, no matter what the test seeds. `Controller\Admin\
 * ConfigurationSubController` gets away with direct injection because
 * nothing in its own tests needs that same substitution.
 */
final class FilesystemIntegrityChecker
{
    public function __construct(
        private readonly Lang $lang,
        private readonly CurrentTemplate $currentTemplate,
        private readonly CurrentConfigService $currentConfigService,
        private readonly ImageService $imageService,
        private readonly CurrentConfig $currentConfig,
        private readonly Paths $paths,
    ) {}

    /**
     * Per-request run-once guard for fsQuickCheck(). Genuinely
     * load-bearing: admin.php's own AdminShell::run() calls
     * fsQuickCheck() directly, then dispatches to a sub-controller
     * (e.g. Controller\Admin\IntroSubController) that calls it again
     * within that same dispatched request.
     */
    private bool $fsQuickCheckDone = false;

    /**
     * Displays a header warning if we find missing photos on a random
     * sample, or if we find duplicate image paths (checked across the
     * whole images table, not just the sample).
     */
    public function fsQuickCheck(): void
    {
        $fs_quick_check_period = $this->currentConfig->fsQuickCheckPeriod();
        if ($fs_quick_check_period === 0) {
            return;
        }

        if ($this->fsQuickCheckDone) {
            return;
        }

        $this->fsQuickCheckDone = true;
        $this->currentConfigService->get()
            ->confUpdateParam('fs_quick_check_last_check', date('c'));

        $imageService = $this->imageService;

        $issue1827_ids = array_map(
            static fn (int $v): string => (string) $v,
            $imageService->getIssue1827CandidateImageIds(5000)
        );
        shuffle($issue1827_ids);
        $issue1827_ids = array_slice($issue1827_ids, 0, 50);

        $random_image_ids = array_map(
            static fn (int $v): string => (string) $v,
            $imageService->getImageIdsSample(5000)
        );
        shuffle($random_image_ids);
        $random_image_ids = array_slice($random_image_ids, 0, 50);

        $fs_quick_check_ids = array_unique(array_merge($issue1827_ids, $random_image_ids));

        if (count($fs_quick_check_ids) < 1) {
            return;
        }

        $fsqc_paths = array_column(
            $imageService->getPathsForFileDeletion($fs_quick_check_ids),
            'path',
            'id'
        );

        foreach ($fsqc_paths as $id => $path) {
            // path is a NOT NULL column in the images table, root-relative
            // (Part II) -- PHP's CWD tracks the executing script's
            // directory, not necessarily the install root, so this must be
            // composed with $this->paths->root rather than checked
            // as-is (found live: a real Visual Regression failure, a
            // spurious "some photos are missing" banner on every admin
            // dashboard load).
            if (! file_exists($this->paths->root . $path)) {
                $template = $this->currentTemplate->get();

                $template->assignContext(new HeaderMessagesPageContext([
                    $this->lang->t('Some photos are missing from your file system. Details provided by plugin Check Uploads'),
                ]));

                return;
            }
        }

        // search for duplicate paths
        $duplicate_paths = $imageService->getDuplicatePaths();

        if (count($duplicate_paths) > 0) {
            $template = $this->currentTemplate->get();

            $template->assignContext(new HeaderMessagesPageContext([
                $this->lang->t('We have found %d duplicate paths. Details provided by plugin Check Uploads', count($duplicate_paths)),
            ]));

            return;
        }
    }

    public function imagesIntegrity(): void
    {
        $imageService = $this->imageService;

        $orphan_image_ids = $imageService->getOrphanImageCategoryLinkIds();

        if (count($orphan_image_ids) > 0) {
            $imageService->deleteImageCategoryRowsForImageIds($orphan_image_ids);
        }
    }

    public function reset(): void
    {
        $this->fsQuickCheckDone = false;
    }
}
