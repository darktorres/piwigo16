<?php

declare(strict_types=1);

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
    public function __construct(protected $id, protected $name, protected $owner)
    {
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getOwner()
    {
        return $this->owner;
    }
}
