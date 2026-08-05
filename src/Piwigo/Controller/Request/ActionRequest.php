<?php

declare(strict_types=1);

namespace Piwigo\Controller\Request;

use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET` shape for ActionController::__invoke() (replaces
 * action.php) -- P26/SEC-40 Request DTO.
 *
 * `fromGlobals()`/`fromArray()` take `$isFormatsEnabled` as an explicit
 * parameter (from `CurrentConfig::isFormatsEnabled()`) -- the original's
 * `format` gate, and its own `InputValidator::validate()` call, both
 * depend on that config value, not just on the param's presence.
 *
 * The original mutated `$_GET['id']`/`$_GET['part']` in place once a
 * `format` param resolved to a real image id, so a single later
 * unconditional re-check picked up either the direct request values or
 * the format-resolved ones. This DTO instead exposes `id`/`part`
 * (the direct-request values only) and `formatId` (the format-request
 * value only) as independent nullable fields -- the controller itself
 * unifies them into one local `$image_id`/`$get_part` pair, since
 * resolving a `format` id into an image id requires a repository call
 * this DTO has no business making.
 *
 * `pwgToken` normalizes a non-string raw value to `null` -- the
 * original's own `isset(...) and $_GET['pwg_token'] === $token`
 * check is already false whenever the value is absent or non-string, so
 * folding presence into the nullable string loses nothing.
 */
final readonly class ActionRequest
{
    private function __construct(
        public ?int $id,
        public ?string $part,
        public bool $formatRequested,
        public ?int $formatId,
        public ?string $pwgToken,
        public bool $downloadPresent,
    ) {}

    public static function fromGlobals(bool $isFormatsEnabled, InputValidator $inputValidator): self
    {
        return self::fromArray($_GET, $isFormatsEnabled, $inputValidator);
    }

    /**
     * @param array<int|string, mixed> $get
     */
    public static function fromArray(array $get, bool $isFormatsEnabled, InputValidator $inputValidator): self
    {
        $id = null;
        if (isset($get['id']) and is_numeric($get['id'])) {
            $id = (int) $get['id'];
        }

        $part_raw = $get['part'] ?? null;
        $part = (is_string($part_raw) and in_array($part_raw, ['e', 'r', 'f'], true)) ? $part_raw : null;

        $format_requested = $isFormatsEnabled && isset($get['format']);
        $format_id = null;
        if ($format_requested) {
            $inputValidator
                ->validate('format', $get, false, ValidationPattern::ID);
            $format_id = is_numeric($get['format']) ? (int) $get['format'] : null;
        }

        $pwg_token_raw = $get['pwg_token'] ?? null;
        $pwg_token = is_string($pwg_token_raw) ? $pwg_token_raw : null;

        return new self(
            $id,
            $part,
            $format_requested,
            $format_id,
            $pwg_token,
            isset($get['download']),
        );
    }
}
