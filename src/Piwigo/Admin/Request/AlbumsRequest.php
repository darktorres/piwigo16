<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for AlbumsPageRenderer::render() (page
 * slug "albums"). `order`'s own
 * `is_string(...) && in_array(..., $sort_orders, true) ?: fatalError(...)`
 * hard rejection stays at the call site (goes through
 * `PresentationAccessor::htmlService()` directly, an L3Presentation
 * side-effecting call), same precedent as SearchQueryRequest/
 * UserActivityRequest. `id`'s own `InputValidator::validate()` call only
 * runs when one of the 2 auto-order flags is set, matching the original
 * (id is never read otherwise). `rawId` mirrors the original's own
 * second, unvalidated `$_POST['id']` re-read used only for the
 * `$open_cat` display reassignment after a successful auto-order.
 * `parentId` is `CategoryId::tryFrom()`-parsed after its own
 * `ValidationPattern::ID` check; absent (or a non-positive value
 * `CategoryId` itself rejects, e.g. `0`) becomes `null` instead of the
 * original's `-1` sentinel -- real category ids start at 1, so both
 * collapse to the same "nothing selected" no-op at the consumer.
 */
final readonly class AlbumsRequest
{
    private function __construct(
        public ?CategoryId $parentId,
        public bool $simpleAutoOrder,
        public bool $recursiveAutoOrder,
        public mixed $order,
        public string $id,
        public mixed $rawId,
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
            ->validate('parent_id', $get, false, ValidationPattern::ID);

        $simpleAutoOrder = isset($post['simpleAutoOrder']);
        $recursiveAutoOrder = isset($post['recursiveAutoOrder']);

        $id = '';
        $rawId = null;
        if ($simpleAutoOrder || $recursiveAutoOrder) {
            $inputValidator
                ->validate('id', $post, false, '/^-?\d+$/');
            $rawId = $post['id'] ?? null;
            $id = is_string($rawId) ? $rawId : '';
        }

        return new self(
            CategoryId::tryFrom($get['parent_id'] ?? null),
            $simpleAutoOrder,
            $recursiveAutoOrder,
            $post['order'] ?? null,
            $id,
            $rawId,
        );
    }
}
