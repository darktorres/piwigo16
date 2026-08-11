<?php

declare(strict_types=1);

namespace Piwigo\Rate\Projection;

use InvalidArgumentException;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\UserId;

/**
 * Typed row shape for `rate`. `fromRow()` centralises the
 * `is_string($row['x']) ? ... :
 * default` narrowing {@see \Piwigo\Rate\RateRepository}'s own private
 * `toRateRow()` array-shape helper used to provide, same shape as
 * {@see \Piwigo\Category\Projection\Category}.
 *
 * `date` stays `?string`, not `\DateTimeImmutable` -- there is no
 * 1970-01-01 sentinel default (every real insert already sets
 * it explicitly via `RateRepository::insertRate()`), and every real
 * consumer already expects the raw DB DATE string form, same reasoning as
 * {@see \Piwigo\Image\Projection\Image}.
 *
 * `userId`/`elementId` are `UserId`/`ImageId`, not `?UserId`/`?ImageId` --
 * `rate.user_id`/`element_id` are both `NOT NULL`, part of the
 * table's own composite PRIMARY KEY, and each carries a real FOREIGN KEY
 * (`fk_rate_user_id` onto `users.id`, `fk_rate_element_id` onto
 * `images.id`, both `ON DELETE CASCADE`) -- a real fetched row can't
 * carry a missing/invalid value for either, same reasoning as
 * {@see \Piwigo\Comment\Projection\Comment}'s `id`/`imageId`.
 */
final readonly class Rate
{
    public function __construct(
        public UserId $userId,
        public ImageId $elementId,
        public string $anonymousId,
        public int $rate,
        public ?string $date,
    ) {}

    /**
     * @param array<string, mixed> $row a `SELECT *` (or equivalent) row from `rate`
     */
    public static function fromRow(array $row): self
    {
        $userIdValue = $row['user_id'] ?? null;
        $userId = $userIdValue instanceof UserId ? $userIdValue : UserId::tryFrom($userIdValue);
        if (! $userId instanceof UserId) {
            throw new InvalidArgumentException(sprintf('Expected a positive user id, got %s', get_debug_type($userIdValue)));
        }

        $elementIdValue = $row['element_id'] ?? null;
        $elementId = $elementIdValue instanceof ImageId ? $elementIdValue : ImageId::tryFrom($elementIdValue);
        if (! $elementId instanceof ImageId) {
            throw new InvalidArgumentException(sprintf('Expected a positive element id, got %s', get_debug_type($elementIdValue)));
        }

        return new self(
            userId: $userId,
            elementId: $elementId,
            anonymousId: is_string($row['anonymous_id'] ?? null) ? $row['anonymous_id'] : '',
            rate: is_numeric($row['rate'] ?? null) ? (int) $row['rate'] : 0,
            date: is_string($row['date'] ?? null) ? $row['date'] : null,
        );
    }

    /**
     * @return array{user_id: int, element_id: int, anonymous_id: string, rate: int, date: ?string}
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId->value,
            'element_id' => $this->elementId->value,
            'anonymous_id' => $this->anonymousId,
            'rate' => $this->rate,
            'date' => $this->date,
        ];
    }
}
