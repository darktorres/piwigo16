<?php

declare(strict_types=1);

namespace Piwigo\Admin\Sync;

use Piwigo\Admin\SyncMode;
use Piwigo\Category\Projection\PhysicalCategoryRow;
use Piwigo\Site\LocalSiteReader;

/**
 * Mutable bag of state shared between the per-stage helpers that
 * MaintenanceController::siteUpdate dispatches to. Pre-sync inputs are
 * readonly; the per-stage outputs (errors, infos, counts, db inventories,
 * caddiables) accumulate across the run.
 */
final class SiteSyncContext
{
    /** @var list<SyncError> */
    public array $errors = [];
    /** @var list<SyncInfo> */
    public array $infos = [];
    /** @var array<string, int> */
    public array $counts = [];
    /** @var array<int, PhysicalCategoryRow> */
    public array $dbCategories = [];
    /** @var array<string, int> */
    public array $dbFulldirs = [];
    /** @var list<int> */
    public array $toDelete = [];
    /** @var list<int> */
    public array $caddiables = [];
    public string $basedir = '';

    public function __construct(
        public readonly int $siteId,
        public readonly string $siteUrl,
        public readonly LocalSiteReader $siteReader,
        public readonly bool $simulate,
        public readonly bool $generalFailure,
        public readonly ?SyncMode $syncMode,
        public readonly string $nowDateTime,
        public readonly string $today,
    ) {
    }
}
