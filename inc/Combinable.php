<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * A Combinable represents a JS or CSS file ready for combination and minification.
 */
class Combinable
{
    public string $id;

    public string $path = '';

    public bool|int|string $version;

    public ?bool $is_template;

    public function __construct(
        string $id,
        ?string $path,
        bool|int|string $version = 0
    ) {
        $this->id = $id;
        $this->set_path($path);
        $this->version = $version;
        $this->is_template = false;
    }

    public function set_path(
        ?string $path
    ): void {
        if (! empty($path)) {
            $this->path = $path;
        }
    }

    public function is_remote(): bool
    {
        return functions_url::url_is_remote($this->path) ||
               strncmp($this->path, '//', 2) == 0;
    }
}
