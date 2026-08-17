<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Core;

/**
 * A pure {message} operation-failure value, returned from any domain
 * helper that needs to signal "this failed, here's why" without throwing
 * (`ImageFilterCriteriaBuilder::stdImageSqlFilterCriteria()`, etc.).
 * Every real caller is a `Controller\Api\*` REST controller
 * pattern-matching `instanceof self` and mapping it onto
 * `ResponseFactory::problem()`.
 */
final readonly class OperationError
{
    public function __construct(
        private readonly string $codeText
    ) {}

    public function message(): string
    {
        return $this->codeText;
    }
}
