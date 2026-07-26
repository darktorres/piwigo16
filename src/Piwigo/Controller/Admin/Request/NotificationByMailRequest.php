<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for
 * NotificationByMailSubController::handle() (page slug
 * "notification_by_mail") -- P27/SEC-40 Request DTO.
 *
 * `post` retains the raw `$_POST` array rather than enumerating fixed
 * properties: `handle()`'s "param" tab reads `$_POST[$nbm_user['param']]`
 * for each DB-discovered `nbm_%` config key (a genuinely dynamic,
 * per-row key set unknowable ahead of time), the same "expose the raw
 * bag" precedent as `Ws\Request\WsRawRequest::$params` and
 * `ProfileFormSubmitRequest::$post`. `handle()` builds its own local
 * mutable working copy from this bag (its "param" tab strips tags from
 * `nbm_send_mail_as` in place before the generic per-config-key loop
 * reads it back, and `doTimeoutTreatment()` filters a selection key back
 * into the same bag before later display-section reads see it) -- both
 * mutations stay within `handle()`'s own call graph, so a local copy
 * replaces the former in-place `$_POST` mutation without touching the
 * real superglobal.
 */
final readonly class NotificationByMailRequest
{
    /**
     * @param array<int|string, mixed> $post
     */
    private function __construct(
        public string $pageMode,
        public array $post,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArrays($_GET, $_POST);
    }

    /**
     * @param array<int|string, mixed> $get
     * @param array<int|string, mixed> $post
     */
    public static function fromArrays(array $get, array $post): self
    {
        new InputValidator()
            ->validate('mode', $get, false, '/^(param|subscribe|send)$/');

        $mode_raw = $get['mode'] ?? null;
        $page_mode = is_string($mode_raw) ? $mode_raw : 'send';

        return new self($page_mode, $post);
    }
}
