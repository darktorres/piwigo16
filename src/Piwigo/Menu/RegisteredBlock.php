<?php

declare(strict_types=1);

namespace Piwigo\Menu;

/**
 * Represents a menu block registered in a BlockManager object.
 */
final readonly class RegisteredBlock
{
    public function __construct(
        protected string $id,
        protected string $name,
        protected string $owner,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getOwner(): string
    {
        return $this->owner;
    }
}
