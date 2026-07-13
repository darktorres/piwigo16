<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/themes_standard_pages.php (page slug
 * "themes_standard_pages") -- a flat page, pure delegate.
 *
 * Investigated, not fixed: this page's writes go through the free
 * functions conf_update_param()/conf_get_param() (include/functions.inc.php),
 * not Piwigo\Config\ConfigService. conf_get_param() reads the legacy $conf
 * global directly, which is actually the *correct* source of truth for
 * admin-configurable settings in this codebase (Piwigo\Config\Config's
 * static accessors don't reflect real DB config -- see the SEC-29/
 * CsrfService fixes in P17/P18). conf_update_param() does have a real,
 * latent defect (its non-array/non-bool branch splices the raw value into
 * an INSERT ... ON DUPLICATE KEY UPDATE with zero escaping, unlike every
 * other raw-SQL config write in this codebase) -- but it's called from 90+
 * sites project-wide, including bootstrap-critical code (install.php,
 * upgrade.php, include/common.inc.php) where Doctrine's EntityManager
 * (which ConfigService needs) is not confirmed available. Rewiring it
 * centrally is real, valuable work, but it's a project-wide infrastructure
 * change disproportionate to this batch's real scope (this page's own 3
 * call sites pass admin-validated/sanitized values -- an allowlisted skin
 * name, a str2url()-sanitized filename -- so not currently exploitable
 * here). Left deferred with this note, matching task #343's own deferral
 * precedent.
 */
final class ThemesStandardPagesSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/themes_standard_pages.php';
    }
}
