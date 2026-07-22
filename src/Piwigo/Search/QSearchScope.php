<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Search;

/**
 * A search scope applies to a single token and restricts the search to a subset of searchable fields.
 */
class QSearchScope
{
    /**
     * @param string[] $aliases
     */
    public function __construct(
        public string $id,
        public array $aliases,
        public bool $nullable = false,
        public bool $is_text = true
    ) {}

    public function parse(QSingleToken $token): bool
    {
        if (! $this->nullable && strlen($token->term) === 0) {
            return false;
        }
        return true;
    }

    public function process_char(string &$ch, string &$crt_token): bool
    {
        return false;
    }

    /**
     * Only QNumericRangeScope and QDateRangeScope support get_sql() —
     * qsearch_get_images() only calls it for scope ids backed by one of
     * those two subclasses (width/height/ratio/size/hits/score/filesize/id/
     * created/posted), never for a plain QSearchScope (tag/photo/file/
     * author, which have their own dedicated SQL building).
     */
    public function get_sql(string $field, QSingleToken $token): string
    {
        throw new \LogicException(static::class . ' does not support get_sql()');
    }
}
