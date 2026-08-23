<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install;

use Piwigo\Core\Paths;

/**
 * install.php's own server-rendered writable-directory pre-flight
 * checklist -- computed once, server-side, at `InstallWizard::boot()`
 * time; no AJAX, unlike Part 1's DB-check (writability doesn't depend on
 * anything the operator types).
 */
final class InstallEnvironmentChecker
{
    /**
     * `$paths->root` is `.env`'s own write target ({@see
     * \Piwigo\Core\Env::testModeEnvFile()} resolves to a bare filename
     * directly under root; {@see InstallEnvWriter}'s own temp-file+rename
     * happens there) -- the one entry `InstallWizard::analyzeForm()`
     * treats as blocking. The other 4 aren't blocking for install itself,
     * but the gallery silently breaks post-install without them.
     *
     * `is_writable()` on a non-existent directory returns a clean `false`
     * with no PHP warning (confirmed live) -- safe to call unconditionally
     * even against a directory a genuinely fresh checkout hasn't created
     * yet.
     *
     * @return list<array{path: string, label: string, writable: bool}>
     */
    public function checkWritableDirectories(Paths $paths): array
    {
        return [
            [
                'path' => $paths->root,
                'label' => 'Installation directory',
                'writable' => is_writable($paths->root),
            ],
            [
                'path' => $paths->data,
                'label' => 'Data directory',
                'writable' => is_writable($paths->data),
            ],
            [
                'path' => $paths->upload,
                'label' => 'Upload directory',
                'writable' => is_writable($paths->upload),
            ],
            [
                'path' => $paths->derivatives,
                'label' => 'Derivatives directory',
                'writable' => is_writable($paths->derivatives),
            ],
            [
                'path' => $paths->logs,
                'label' => 'Logs directory',
                'writable' => is_writable($paths->logs),
            ],
        ];
    }
}
