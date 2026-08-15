<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for PictureModifyPageRenderer::render()
 * (the "properties" tab of the "photo" page slug).
 *
 * `postLevel`/`nameField`/`authorField`/`commentField` are each read once
 * here and reused at both of the original's real sites for that same
 * value (the submit-handling block's own `$data[...]` assignment, and
 * the later, unconditional display-default computation) -- the two
 * sites apply different downstream transforms to the same raw value
 * (`strip_tags()` vs. no transform at all), not different raw reads.
 * `nameField`/`authorField`/`commentField` stay nullable (`null` for
 * absent/non-string) rather than defaulting to `''`: the display site's
 * own fallback-to-row-value logic keys off `is_string(...)`, not
 * emptiness, so collapsing "absent" and "submitted as an empty string"
 * into the same `''` would wrongly discard a deliberate empty
 * submission in favor of the stored row value.
 *
 * `dateCreation` collapses the original's `! in_array(..., [null, false,
 * 0, '0', '', []], true)` "is it empty" gate into a plain `?string`:
 * `date_creation`'s own `InputValidator::validate()` call (pattern
 * `\d\d\d\d-\d\d-\d\d(...)?`) already guarantees that a *present* value
 * can never be one of those empty sentinels, so "present" and "not
 * empty" are the same condition here.
 *
 * `postLevel` is cast to `int` after its own digit-pattern validation
 * (`InputValidator` hard-rejects a non-scalar POST value before this
 * point, so the cast is always safe). `tagsRaw` stays a loose
 * `array<array-key, mixed>|string|null` union, unvalidated -- it mirrors
 * `TagService::getTagIds(string|array $rawTags, ...)`'s own parameter
 * shape exactly, since that's the sole real consumer.
 *
 * `associate`/`represent` replicate the original's own
 * `if (! isset($_POST['associate'])) { $_POST['associate'] = []; }`
 * pre-mutation (done only so the immediately-following
 * `InputValidator::validate()` call always sees a defined array) by
 * validating against a synthetic single-key array instead of mutating
 * the real superglobal -- `InputValidator::validate()` only ever reads
 * `$paramArray[$paramName]`, so this is behaviorally identical.
 */
final readonly class PictureModifyRequest
{
    /**
     * @param array<array-key, mixed>|string|null $tagsRaw
     * @param list<int> $associate
     * @param list<int> $represent
     */
    private function __construct(
        public int $imageId,
        public bool $deletePresent,
        public bool $syncMetadataPresent,
        public bool $isSubmitted,
        public ?int $postLevel,
        public ?string $nameField,
        public ?string $authorField,
        public ?string $commentField,
        public ?string $dateCreation,
        public array|string|null $tagsRaw,
        public array $associate,
        public array $represent,
    ) {}

    public static function fromGlobals(InputValidator $inputValidator): self
    {
        return self::fromArrays($_GET, $_POST, $inputValidator);
    }

    /**
     * @param array<int|string, mixed> $get
     * @param array<int|string, mixed> $post
     */
    public static function fromArrays(array $get, array $post, InputValidator $inputValidator): self
    {
        $inputValidator
            ->validate('image_id', $get, false, ValidationPattern::ID);
        $inputValidator
            ->validate('level', $post, false, '/^\d+$/');
        $inputValidator
            ->validate('date_creation', $post, false, '/^\d\d\d\d-\d\d-\d\d( \d\d:\d\d:\d\d)?$/');

        $image_id = 0;
        if (isset($get['image_id']) && is_numeric($get['image_id'])) {
            $image_id = (int) $get['image_id'];
        }

        $name_raw = $post['name'] ?? null;
        $name_field = is_string($name_raw) ? $name_raw : null;

        $author_raw = $post['author'] ?? null;
        $author_field = is_string($author_raw) ? $author_raw : null;

        $comment_raw = $post['comment'] ?? null;
        $comment_field = is_string($comment_raw) ? $comment_raw : null;

        $date_creation_raw = $post['date_creation'] ?? null;
        $date_creation = is_string($date_creation_raw) ? $date_creation_raw : null;

        $level_raw = $post['level'] ?? null;
        $post_level = is_numeric($level_raw) ? (int) $level_raw : null;

        $tags_raw = $post['tags'] ?? null;
        $tags_raw = is_array($tags_raw) || is_string($tags_raw) ? $tags_raw : null;

        $post_associate = $post['associate'] ?? [];
        $inputValidator
            ->validate('associate', [
                'associate' => $post_associate,
            ], true, ValidationPattern::ID);
        $associate = [];
        if (is_array($post_associate)) {
            foreach ($post_associate as $associate_value) {
                if (is_numeric($associate_value)) {
                    $associate[] = (int) $associate_value;
                }
            }
        }

        $post_represent = $post['represent'] ?? [];
        $inputValidator
            ->validate('represent', [
                'represent' => $post_represent,
            ], true, ValidationPattern::ID);
        $represent = [];
        if (is_array($post_represent)) {
            foreach ($post_represent as $represent_value) {
                if (is_numeric($represent_value)) {
                    $represent[] = (int) $represent_value;
                }
            }
        }

        return new self(
            $image_id,
            isset($get['delete']),
            isset($get['sync_metadata']),
            isset($post['submit']),
            $post_level,
            $name_field,
            $author_field,
            $comment_field,
            $date_creation,
            $tags_raw,
            $associate,
            $represent,
        );
    }
}
