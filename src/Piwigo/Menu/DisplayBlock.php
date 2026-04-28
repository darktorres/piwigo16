<?php

declare(strict_types=1);

namespace Piwigo\Menu;

/**
 * Represents a menu block ready for display in the BlockManager object.
 */
class DisplayBlock
{
    /** @var int */
    protected $_position;
    /** @var string|null */
    protected $_title = null;

    /** @var mixed */
    public $data;
    /** @var string */
    public $template;
    /** @var string */
    public $raw_content;

    public $id;

    /**
     * @param RegisteredBlock $_registeredBlock
     */
    public function __construct(protected $_registeredBlock)
    {
    }

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
