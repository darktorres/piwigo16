<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Menu;

use Latte\Runtime\Html;

/**
 * Represents a menu block ready for display in the BlockManager object.
 */
final class DisplayBlock
{
    private int $position;

    /**
     * null until setTitle() is called (the constructor never sets it) —
     * getTitle() falls back to the registered block's own name in that case
     */
    private ?string $title = null;

    /**
     * @var Html|null the block's rendered HTML -- Html, not string,
     *   since MenubarRenderer fills it straight from Renderer::render()'s
     *   own Html return. A block nothing fills stays null and
     *   BlockManager::apply() hides it rather than emitting an empty
     *   `<dl>`.
     */
    public ?Html $raw_content = null;

    /**
     * Zero real readers/writers anywhere in the codebase today -- reserved
     * slot, same rationale as Core\PageState::$bodyData.
     */
    public mixed $id = null;

    public function __construct(
        private readonly RegisteredBlock $registeredBlock,
        int $position = 0,
    ) {
        $this->position = $position;
    }

    public function getBlock(): RegisteredBlock
    {
        return $this->registeredBlock;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function getTitle(): string
    {
        if (isset($this->title)) {
            return $this->title;
        } else {
            return $this->registeredBlock->getName();
        }
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }
}
