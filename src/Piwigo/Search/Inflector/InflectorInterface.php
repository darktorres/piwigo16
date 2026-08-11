<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Search\Inflector;

interface InflectorInterface
{
    /**
     * @return array<int, string>
     */
    public function getVariants(string $word): array;
}
