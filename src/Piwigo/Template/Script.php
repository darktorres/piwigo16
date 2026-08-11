<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Template;

final class Script extends Combinable
{
    /**
     * @var array{order?: int}
     */
    public array $extra;

    /**
     * @param int $load_mode 0,1,2
     * @param string|null $path see Combinable::__construct()
     * @param string|false $version false disables version-based cache
     *   busting, mirroring Combinable::$version's own contract
     * @param string[] $precedents
     */
    public function __construct(
        public int $load_mode,
        string $id,
        ?string $path,
        string|false $version = '0',
        public array $precedents = []
    ) {
        parent::__construct($id, $path, $version);
        $this->extra = [];
    }
}
