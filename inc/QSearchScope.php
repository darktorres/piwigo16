<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

/**
 * A search scope applies to a single token and restricts the search to a subset of searchable fields.
 */
class QSearchScope
{
    public $id;

    public $aliases;

    public $is_text;

    public $nullable;

    public function __construct($id, $aliases, $nullable = false, $is_text = true)
    {
        $this->id = $id;
        $this->aliases = $aliases;
        $this->is_text = $is_text;
        $this->nullable = $nullable;
    }

    public function parse($token)
    {
        if (! $this->nullable && strlen($token->term) == 0) {
            return false;
        }
        return true;
    }

    public function process_char(&$ch, &$crt_token)
    {
        return false;
    }
}
