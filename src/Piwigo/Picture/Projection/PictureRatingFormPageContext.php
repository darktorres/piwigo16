<?php

declare(strict_types=1);

namespace Piwigo\Picture\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The 'rating' template variable assigned by
 * {@see \Piwigo\Picture\PictureRateRenderer::render()}, only when the
 * current visitor is allowed to rate.
 */
final readonly class PictureRatingFormPageContext implements TemplatePageContext
{
    /**
     * @param list<int> $marks
     */
    public function __construct(
        public string $formAction,
        public ?int $userRate,
        public array $marks,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'rating' => [
                'F_ACTION' => $this->formAction,
                'USER_RATE' => $this->userRate,
                'marks' => $this->marks,
            ],
        ];
    }
}
