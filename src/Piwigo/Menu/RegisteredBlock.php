<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Menu;

/**
 * Represents a menu block registered in a BlockManager object.
 */
class RegisteredBlock
{
    /**
     * @param string $id
     * @param string $name
     * @param string $owner
     */
    public function __construct(
        protected $id,
        protected $name,
        protected $owner
    ) {}

    /**
     * @return string
     */
    public function get_id()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function get_name()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function get_owner()
    {
        return $this->owner;
    }
}
