<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

/**
 * Validated `$_POST` shape for MenubarPageRenderer::render()'s save form
 * (page slug "menubar") -- P27/SEC-40 Request DTO. Per-block fields are
 * named dynamically (`hide_{id}`/`pos_{id}`) by the menubar block id, but
 * those ids come from the server's own registered-block/DB-config set
 * (`$reg_blocks`/`$mb_conf`), never from this request -- so there's no
 * injection surface to pattern-validate, only the existing
 * presence/numeric-coercion checks preserved behind named accessors.
 */
final readonly class MenubarSubmitRequest
{
    /**
     * @param array<array-key, mixed> $post
     */
    private function __construct(
        public bool $isSubmitted,
        private array $post,
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
        return new self(isset($post['submit']), $post);
    }

    public function isHidden(int|string $blockId): bool
    {
        return isset($this->post['hide_' . $blockId]);
    }

    public function positionFor(int|string $blockId): int
    {
        $pos = $this->post['pos_' . $blockId] ?? null;

        return is_numeric($pos) ? (int) $pos : 0;
    }
}
