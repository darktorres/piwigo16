<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * Implementation of Combinable for JS files.
 */
final class Script extends Combinable
{
    public array $extra;

    /**
     * @param int $load_mode 0,1,2
     * @param array<int, string> $precedents
     */
    public function __construct(
        public int $load_mode,
        string $id,
        ?string $path,
        int|string $version = 0,
        public array $precedents = []
    ) {
        parent::__construct($id, $path, $version);
        $this->extra = [];
    }
}
