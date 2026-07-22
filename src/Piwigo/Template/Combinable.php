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
    /**
     * @var string
     */
    public $path;

    /**
     * @var bool
     */
    public $is_template;

    /**
     * @param string $id
     * @param string|null $path null leaves $path unset -- callers that pass
     *   null (e.g. ScriptLoader::add()'s UI-core-dependency recursion) rely
     *   on a well-known path being filled in afterwards
     * @param string|false $version false disables version-based cache busting
     */
    public function __construct(
        public $id,
        $path,
        public $version = '0'
    ) {
        $this->set_path($path);
        $this->is_template = false;
    }

    /**
     * @param string|null $path a null/empty path is a deliberate no-op
     */
    public function set_path($path): void
    {
        if (! in_array($path, [null, ''], true)) {
            $this->path = $path;
        }
    }

    public function is_remote(UrlServiceInterface $urlService): bool
    {
        return $urlService->urlIsRemote($this->path) || str_starts_with($this->path, '//');
    }
}
