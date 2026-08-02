<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for PhotosAddDirectPageRenderer's
 * `render()`/`prepareUploadForm()` (the "direct" tab of the "photos_add"
 * page slug) -- P27/SEC-40 Request DTO.
 *
 * `fromGlobals()`/`fromArrays()` take `$isFormatsEnabled` as an explicit
 * parameter (from `CurrentConfig::isFormatsEnabled()`) rather than reading
 * config from inside the DTO -- the original's own gate for the
 * `formats` GET param (`display_formats`) depends on that config value,
 * and `formats`'s own `InputValidator::validate()` call must stay
 * skipped when the feature is disabled, exactly like the original,
 * not just when the param happens to be present.
 *
 * `postLevel` stays `mixed` -- the original never validated it either,
 * passing it straight through to a template array with a bare
 * `?? 0` default.
 */
final readonly class PhotosAddDirectRequest
{
    private function __construct(
        public bool $batchPresent,
        public string $batch,
        public bool $displayFormats,
        public bool $formatsTruthy,
        public string $formatsId,
        public bool $albumPresent,
        public ?int $albumId,
        public mixed $postLevel,
        public bool $hideWarningsPresent,
    ) {}

    public static function fromGlobals(bool $isFormatsEnabled): self
    {
        return self::fromArrays($_GET, $_POST, $isFormatsEnabled);
    }

    /**
     * @param array<int|string, mixed> $get
     * @param array<int|string, mixed> $post
     */
    public static function fromArrays(array $get, array $post, bool $isFormatsEnabled): self
    {
        $batch_present = isset($get['batch']);
        if ($batch_present) {
            InputValidator::createStatic()
                ->validate('batch', $get, false, '/^\d+(,\d+)*$/');
        }
        $batch_raw = $get['batch'] ?? null;
        $batch = is_string($batch_raw) ? $batch_raw : '';

        $display_formats = $isFormatsEnabled && isset($get['formats']);
        // The (bool) cast is redundant: `&&` itself always produces a
        // genuine bool result regardless of its right operand's type, so
        // removing the cast can't change $formats_truthy's value or type.
        // Confirmed while investigating a mutation-testing gap.
        $formats_truthy = $display_formats && (bool) $get['formats'];
        $formats_id = '';
        if ($formats_truthy) {
            // $mandatory=false below is also unobservable here: reaching
            // this branch already requires $get['formats'] to be
            // PHP-truthy (the check above), and every value InputValidator
            // ::emptyValue() treats as "empty" (null/''/0/0.0/'0'/false/[])
            // is PHP-falsy -- the two sets are complementary, so
            // validate()'s own emptyValue() short-circuit (the only place
            // $mandatory is read) can never trigger on this call.
            InputValidator::createStatic()
                ->validate('formats', $get, false, ValidationPattern::ID, false);
            $formats_raw = $get['formats'] ?? null;
            $formats_id = is_string($formats_raw) ? $formats_raw : '';
        }

        $album_present = isset($get['album']);
        $album_id = null;
        if ($album_present) {
            InputValidator::createStatic()
                ->validate('album', $get, false, ValidationPattern::ID);
            $album_id = is_numeric($get['album']) ? (int) $get['album'] : null;
        }

        return new self(
            $batch_present,
            $batch,
            $display_formats,
            $formats_truthy,
            $formats_id,
            $album_present,
            $album_id,
            $post['level'] ?? 0,
            isset($get['hide_warnings']),
        );
    }
}
