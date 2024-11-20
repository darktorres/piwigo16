<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * Represents a menu block ready for display in the BlockManager object.
 */
class DisplayBlock
{
    public array $data = [];

    public string $template;

    public string $raw_content;

    public string|int|null $id;

    protected RegisteredBlock $_registeredBlock;

    protected int $_position;

    protected string $_title;

    public function __construct(
        RegisteredBlock $block
    ) {
        $this->_registeredBlock = $block;
    }

    public function get_block(): RegisteredBlock
    {
        return $this->_registeredBlock;
    }

    public function get_position(): int
    {
        return $this->_position;
    }

    public function set_position(
        int $position
    ): void {
        $this->_position = $position;
    }

    public function get_title(): string
    {
        if (isset($this->_title)) {
            return $this->_title;
        }

        return $this->_registeredBlock->get_name();
    }

    public function set_title(
        string $title
    ): void {
        $this->_title = $title;
    }
}
