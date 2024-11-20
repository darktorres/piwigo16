<?php

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
    /**
     * 0,1,2
     */
    public int $load_mode;

    public array $precedents;

    public array $extra;

    /**
     * @param int $load_mode 0,1,2
     * @param array<int, string> $precedents
     */
    public function __construct(
        int $load_mode,
        string $id,
        ?string $path,
        int|string $version = 0,
        array $precedents = []
    ) {
        parent::__construct($id, $path, $version);
        $this->load_mode = $load_mode;
        $this->precedents = $precedents;
        $this->extra = [];
    }
}
