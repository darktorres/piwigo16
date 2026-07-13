<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/languages.php (page slug "languages") -- a tab dispatcher
 * (installed/new/update) that stays a pure delegate. The 2 leaf files it
 * dispatches to (languages_installed.php/languages_new.php, plus the
 * shared updates_ext.php for "update") were migrated off the
 * languages.class.php god-class onto PemCatalog/ExtensionScanner/
 * ExtensionLifecycle/ExtensionRepository (this batch's real scope).
 */
final class LanguagesSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/languages.php';
    }
}
