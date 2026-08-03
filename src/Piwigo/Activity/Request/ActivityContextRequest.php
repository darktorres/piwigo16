<?php

declare(strict_types=1);

namespace Piwigo\Activity\Request;

/**
 * Validated `$_GET`/`$_POST`/`$_REQUEST` shape for
 * ActivityService::record() -- P26/SEC-40 Request DTO.
 *
 * `record()` is called from dozens of L4Integration/legacy sites (per
 * the class's own docblock), so changing its signature to thread these
 * fields in as parameters would ripple across every caller for a
 * read-only, audit-log-enrichment concern -- constructed as the first
 * statement of `record()` instead, same as every WS method this
 * migration has already converted under `PwgServer::invoke()`'s own
 * fixed dispatch signature.
 *
 * `pageParam` covers the original's own 2 separate `$_GET['page']` reads
 * (the "script" fallback and the "sync" detection) -- same key, reused.
 * `destinationTag` narrows to `?string` (was stored raw into `$details`
 * before): the value is written straight into an audit-log `$details`
 * array (`array<string, mixed>`) for informational purposes only, and a
 * malformed non-string request value has no legitimate reason to
 * propagate any further than that log entry.
 */
final readonly class ActivityContextRequest
{
    private function __construct(
        public ?string $requestMethod,
        public ?string $requestAction,
        public ?string $pageParam,
        public bool $destinationTagPresent,
        public ?string $destinationTag,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArrays($_GET, $_POST, $_REQUEST);
    }

    /**
     * @param array<int|string, mixed> $get
     * @param array<int|string, mixed> $post
     * @param array<int|string, mixed> $request
     */
    public static function fromArrays(array $get, array $post, array $request): self
    {
        $request_method_raw = $request['method'] ?? null;
        $request_action_raw = $request['action'] ?? null;
        $page_param_raw = $get['page'] ?? null;
        $destination_tag_raw = $post['destination_tag'] ?? null;

        return new self(
            is_string($request_method_raw) ? $request_method_raw : null,
            is_string($request_action_raw) ? $request_action_raw : null,
            is_string($page_param_raw) ? $page_param_raw : null,
            isset($post['destination_tag']),
            is_string($destination_tag_raw) ? $destination_tag_raw : null,
        );
    }
}
