<?php

declare(strict_types=1);

namespace Piwigo\Menu;

/**
 * Represents a menu block ready for display in the BlockManager object.
 */
final class DisplayBlock
{
    protected int $_position = 0;
    /** @var string|null */
    protected $_title = null;

    /** @var array<mixed> */
    public array $data = [];
    public string $template = '';
    public string $raw_content = '';

    public string $id = '';

    /**
     * @param RegisteredBlock $_registeredBlock
     */
    public function __construct(protected $_registeredBlock)
    {
    }

    /**
     * @return RegisteredBlock
     */
    public function getBlock()
    {
        return $this->_registeredBlock;
    }

    public function getPosition(): int
    {
        return $this->_position;
    }

    public function setPosition(int $position): void
    {
        $this->_position = $position;
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        if (isset($this->_title)) {
            return $this->_title;
        } else {
            return $this->_registeredBlock->getName();
        }
    }

    public function setTitle(string $title): void
    {
        $this->_title = $title;
    }
}
