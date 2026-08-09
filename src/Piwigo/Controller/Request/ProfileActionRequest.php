<?php

declare(strict_types=1);

namespace Piwigo\Controller\Request;

/**
 * Validated request shape for ProfileController::__invoke() (replaces
 * profile.php). Both raw superglobal reads here
 * are presence-only checks (no value is ever read out): `$_POST !== []`
 * gates the CSRF check, `isset($_POST['reset_to_default'])` is a plain
 * checkbox-present toggle -- nothing to pattern-validate with
 * InputValidator.
 */
final readonly class ProfileActionRequest
{
    private function __construct(
        public bool $requiresCsrfCheck,
        public bool $resetToDefault,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_POST);
    }

    /**
     * @param array<array-key, mixed> $post
     */
    public static function fromArray(array $post): self
    {
        return new self($post !== [], isset($post['reset_to_default']));
    }
}
