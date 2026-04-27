<?php

declare(strict_types=1);

namespace Piwigo\Template;

/**
 * A Combinable represents a JS or CSS file ready for cobination and minification.
 */
class Combinable
{
    /** @var string */
    public $path;
    /** @var bool */
    public $is_template;

    /**
     * @param string $id
     * @param string $path
     * @param string $version
     */
    public function __construct(public $id, $path, public $version = 0)
    {
        $this->set_path($path);
        $this->is_template = false;
    }

    /**
     * @param string $path
     */
    public function set_path($path): void
    {
        if (!empty($path)) {
            $this->path = $path;
        }
    }

    public function is_remote(): bool
    {
        return url_is_remote($this->path) || str_starts_with($this->path, '//');
    }
}
