<?php

declare(strict_types=1);

namespace Piwigo\Ws\Request;

/**
 * Validated `$_REQUEST['name']`/`['chunk']`/`['chunks']` shape for
 * PwgImages::upload() -- P26/SEC-40 Request DTO. `requestNamePresent` is
 * kept separate from `requestName`: the original's own fallback chain
 * (`isset($_REQUEST['name']) ? $_REQUEST['name'] : (... $_FILES fallback
 * ... : uniqid(...))`) short-circuits on mere PRESENCE, even for a
 * present-but-non-string value (a malformed `?name[]=x` request), so
 * collapsing "absent" and "present but not a string" into the same
 * `null` would wrongly let the method fall through to the `uniqid()`
 * ultimate default. The `uniqid()` call itself stays at the call site --
 * impure/time-based, so it can't live in this otherwise-pure DTO.
 */
final readonly class ChunkedUploadRequest
{
    private function __construct(
        public bool $requestNamePresent,
        public ?string $requestName,
        public int $chunk,
        public int $chunks,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_REQUEST);
    }

    /**
     * @param array<int|string, mixed> $request
     */
    public static function fromArray(array $request): self
    {
        $request_name_present = isset($request['name']);
        $request_name_raw = $request['name'] ?? null;
        $request_name = is_string($request_name_raw) ? $request_name_raw : null;

        $chunk_raw = $request['chunk'] ?? null;
        $chunk = (isset($request['chunk']) && is_scalar($chunk_raw)) ? intval($chunk_raw) : 0;

        $chunks_raw = $request['chunks'] ?? null;
        $chunks = (isset($request['chunks']) && is_scalar($chunks_raw)) ? intval($chunks_raw) : 0;

        return new self($request_name_present, $request_name, $chunk, $chunks);
    }
}
