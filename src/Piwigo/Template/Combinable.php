<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Template;

use Piwigo\Core\UrlServiceInterface;

class Combinable
{
    public ?string $path = null;

    public bool $is_template;

    /**
     * @param string|null $path null leaves $path unset -- callers that pass
     *   null (e.g. ScriptLoader::add()'s UI-core-dependency recursion) rely
     *   on a well-known path being filled in afterwards
     * @param string|false $version false disables version-based cache busting
     */
    public function __construct(
        public string $id,
        ?string $path,
        public string|false $version = '0'
    ) {
        $this->setPath($path);
        $this->is_template = false;
    }

    /**
     * @param string|null $path a null/empty path is a deliberate no-op
     */
    public function setPath(?string $path): void
    {
        if (! in_array($path, [null, ''], true)) {
            $this->path = $path;
        }
    }

    public function isRemote(UrlServiceInterface $urlService): bool
    {
        // A combinable with no path yet (setPath()'s own null/empty no-op,
        // not yet filled in by ScriptLoader::fillWellKnown()) has nothing
        // to be remote about.
        if ($this->path === null) {
            return false;
        }

        return $urlService->urlIsRemote($this->path) || str_starts_with($this->path, '//');
    }
}
