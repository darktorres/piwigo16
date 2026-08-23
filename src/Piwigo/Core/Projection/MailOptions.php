<?php

declare(strict_types=1);

namespace Piwigo\Core\Projection;

/**
 * {@see \Piwigo\Core\MailerInterface::mail()}'s own `$tpl` runtime-template
 * options. `$assign` stays the confirmed template-assign boundary -- a
 * genuinely arbitrary caller-supplied variable bag, not narrowed further
 * here.
 *
 * Deliberately mutable (not `final readonly`): `AlbumNotificationPageRenderer`
 * clones a base instance per recipient (`clone $tpl`) and overrides 2
 * `$assign` entries on each clone with a per-user auth-key URL param,
 * matching the original array's own copy-on-assign-then-mutate semantics.
 */
final class MailOptions
{
    /**
     * @param array<string, mixed> $assign
     */
    public function __construct(
        public readonly ?string $filename = null,
        public array $assign = [],
    ) {}

    /**
     * Boundary conversion for {@see \Piwigo\Job\SendNotificationEmailJob::
     * $tpl}, which stays array-shaped on the Job itself (a real Symfony
     * Messenger serialization boundary -- an in-flight queued message must
     * still deserialize against the old array shape).
     *
     * @param array{filename?: string, assign?: array<string, mixed>} $tpl
     */
    public static function fromArray(array $tpl): self
    {
        return new self(
            filename: $tpl['filename'] ?? null,
            assign: $tpl['assign'] ?? [],
        );
    }
}
