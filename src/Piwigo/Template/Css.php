<?php

declare(strict_types=1);

namespace Piwigo\Template;

/**
 * Implementation of Combinable for CSS files.
 */
final class Css extends Combinable
{
    /**
     * @param string $id
     * @param string $path
     * @param string|int $version
     * @param int $order
     */
    public function __construct($id, ?string $path, $version = 0, public $order = 0)
    {
        parent::__construct($id, $path, $version);
    }
}
