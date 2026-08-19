<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `history.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\HistoryPageRenderer::render()}. No `$fAction` field --
 * `F_ACTION` has zero real references in `history.latte`'s own body
 * (its `<form>` submits nowhere; the real search runs client-side
 * against `GET /api/v1/history/search`).
 */
#[Template('history.latte')]
final readonly class HistoryView implements View
{
    public function __construct(
        public int $userId,
        public ?string $userName,
        public string $imageId,
        public string $ip,
        public string $start,
        public string $end,
        public int $guestId,
    ) {}
}
