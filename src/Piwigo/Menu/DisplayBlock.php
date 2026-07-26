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
 * Represents a menu block ready for display in the BlockManager object.
 */
final class DisplayBlock
{
    /**
     * @var int
     */
    private $_position;

    /**
     * @var string|null null until set_title() is called (the constructor
     *   never sets it) — get_title() falls back to the registered block's
     *   own name in that case
     */
    private $_title;

    /**
     * Genuinely polymorphic by design -- MenubarRenderer sets this to a
     * different shape per block type (categories/tags/links/...), matching
     * the plugin-block-registration pattern.
     *
     * @var mixed
     */
    public $data;

    /**
     * @var string
     */
    public $template;

    /**
     * @var string
     */
    public $raw_content;

    /**
     * Zero real readers/writers anywhere in the codebase today -- reserved
     * slot, same rationale as Core\PageState::$bodyData.
     *
     * @var mixed
     */
    public $id;

    /**
     * @param RegisteredBlock $_registeredBlock
     */
    public function __construct(
        private $_registeredBlock
    ) {}

    /**
     * @return RegisteredBlock
     */
    public function get_block()
    {
        return $this->_registeredBlock;
    }

    /**
     * @return int
     */
    public function get_position()
    {
        return $this->_position;
    }

    /**
     * @param int $position
     */
    public function set_position($position): void
    {
        $this->_position = $position;
    }

    /**
     * @return string
     */
    public function get_title()
    {
        if (isset($this->_title)) {
            return $this->_title;
        } else {
            return $this->_registeredBlock->get_name();
        }
    }

    /**
     * @param string $title
     */
    public function set_title($title): void
    {
        $this->_title = $title;
    }
}
