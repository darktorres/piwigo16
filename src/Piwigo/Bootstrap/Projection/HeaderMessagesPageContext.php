<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The `header_msgs` template variable assigned by
 * {@see \Piwigo\Bootstrap\RequestBootstrap} whenever
 * `PageState::$headerMessages` is non-empty.
 */
final readonly class HeaderMessagesPageContext implements TemplatePageContext
{
    /**
     * @param list<string> $headerMsgs
     */
    public function __construct(
        public array $headerMsgs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'header_msgs' => $this->headerMsgs,
        ];
    }
}
