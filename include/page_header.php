<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Page\PageHeaderRenderer;

// Set by the including page script, right before this include ($refresh/
// $url_link mirror $title but are optional -- redirect_html() in this
// same file is the only real setter today).
/**
 * @var string $title
 * @var int|string|null $refresh
 * @var string|null $url_link
 */
global $title, $refresh, $url_link;

$refresh_str = isset($refresh) && is_numeric($refresh) ? (string) $refresh : null;

new PageHeaderRenderer()
    ->render($title, $refresh_str, $url_link ?? null);
