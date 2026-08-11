<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Template;

final class Css extends Combinable
{
    /**
     * @param string|false $version false disables version-based cache
     *   busting, mirroring Combinable::$version's own contract
     */
    public function __construct(
        string $id,
        string $path,
        string|false $version = '0',
        public int $order = 0
    ) {
        parent::__construct($id, $path, $version);
    }
}
