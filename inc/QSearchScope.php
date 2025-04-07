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
 * A search scope applies to a single token and restricts the search to a subset of searchable fields.
 */
class QSearchScope
{
    public function __construct(
        public string $id,
        public array $aliases,
        public bool $nullable = false,
        public bool $is_text = true
    ) {}

    public function parse(
        QSingleToken $token
    ): bool {
        if (! $this->nullable &&
            strlen($token->term) == 0
        ) {
            return false;
        }

        return true;
    }

    public function process_char(
        string &$ch,
        string &$crt_token
    ): false {
        return false;
    }
}
