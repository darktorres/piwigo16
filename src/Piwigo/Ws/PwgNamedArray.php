<?php

declare(strict_types=1);

namespace Piwigo\Ws;

/**
 * Simple wrapper around an array (keys are consecutive integers starting at 0).
 * Provides naming clues for xml output (xml attributes vs. xml child elements?)
 * Usually returned by web service function implementation.
 */
class PwgNamedArray
{
    /** @var array<string, int> */
    private array $_xmlAttributes = [];

    /**
     * Constructs a named array
     * @param mixed $_content array (keys must be consecutive integers starting at 0)
     * @param string $_itemName xml element name for values of arr (e.g. image)
     * @param array $xmlAttributes sub-item attributes that will be encoded as xml attributes
     */
    /** @param string[] $xmlAttributes */
    public function __construct(private mixed $_content, private readonly string $_itemName, array $xmlAttributes = [])
    {
        $this->_xmlAttributes = array_flip($xmlAttributes);
    }

    public function getContent(): mixed
    {
        return $this->_content;
    }

    public function appendItem(mixed $item): void
    {
        if (!is_array($this->_content)) {
            $this->_content = [];
        }
        $this->_content[] = $item;
    }

    /** @return array<string, int> */
    public function getXmlAttributes(): array
    {
        return $this->_xmlAttributes;
    }

    public function getItemName(): string
    {
        return $this->_itemName;
    }
}
