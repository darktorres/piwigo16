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
 * Represents a menu block registered in a BlockManager object.
 */
final class RegisteredBlock
{
    private string $id;

    private string $name;

    private string $owner;

    public function __construct(
        string $id,
        string $name,
        string $owner
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->owner = $owner;
    }

    public function get_id(): string
    {
        return $this->id;
    }

    public function get_name(): string
    {
        return $this->name;
    }

    public function get_owner(): string
    {
        return $this->owner;
    }
}
