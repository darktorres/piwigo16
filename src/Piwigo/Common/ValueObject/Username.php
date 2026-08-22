<?php

declare(strict_types=1);

namespace Piwigo\Common\ValueObject;

use InvalidArgumentException;
use Override;

/**
 * Piwigo username. Capped at the `users.username` column width (VARCHAR 100,
 * utf8mb4_bin), with control characters and edge whitespace rejected so the
 * stored value is always a clean, identity-comparable handle.
 *
 * The collation is binary, so two Username instances compare case-sensitively
 * by `$a->value === $b->value` — `Foo` and `foo` are distinct accounts at
 * the DB level and stay distinct here.
 *
 * [P44-H] Also rejects the 5 HTML-special characters (`<`, `>`, `&`, `"`,
 * `'`) -- neither this class nor the registration/profile-update flow ever
 * restricted username characters beyond the checks above, and a stored
 * username reaches several raw-HTML sinks unescaped elsewhere (e.g.
 * MailService's password-reset/activation mail bodies). This is
 * deliberately narrower than a general alphanumeric-only policy: legitimate
 * unicode names, spaces, and punctuation outside this specific set stay
 * valid -- only the exact character class that enables HTML injection is
 * blocked, closing the root cause for every current and future consumer
 * of stored username data at once.
 */
final readonly class Username implements StringVo
{
    private const int MAX_LENGTH = 100;

    private function __construct(
        public string $value
    ) {}

    /**
     * @throws InvalidArgumentException when $value is empty, too long, padded, or contains control characters
     */
    #[Override]
    public static function from(string $value): self
    {
        if ($value === '') {
            throw new InvalidArgumentException('Username must not be empty');
        }
        if (strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                'Username exceeds ' . self::MAX_LENGTH . " chars: '{$value}'",
            );
        }
        if ($value !== trim($value)) {
            throw new InvalidArgumentException("Username must not have leading or trailing whitespace: '{$value}'");
        }
        // Reject any C0 / DEL control char anywhere in the string.
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("Username must not contain control characters: '{$value}'");
        }
        // Reject the HTML-special character class (P44-H) -- see this
        // class's own docblock for why this specific set, not a broader
        // alphanumeric-only restriction.
        if (preg_match('/[<>&"\']/', $value) === 1) {
            throw new InvalidArgumentException("Username must not contain '<', '>', '&', '\"', or \"'\": '{$value}'");
        }
        return new self($value);
    }

    #[Override]
    public static function tryFrom(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }
        try {
            return self::from($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    #[Override]
    public function equals(StringVo $other): bool
    {
        return $other instanceof self && $other->value === $this->value;
    }

    #[Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
