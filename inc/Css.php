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
 * Implementation of Combinable for CSS files.
 */
final class Css extends Combinable
{
    public function __construct(
        string $id,
        string $path,
        int|string $version = 0,
        public int $order = 0
    ) {
        parent::__construct($id, $path, $version);
    }
}
