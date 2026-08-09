<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET['action']`/`$_GET['language']` for
 * LanguagesInstalledPageRenderer::render() (the "installed" tab of page
 * slug "languages"). `language` is validated
 * against a per-request pattern built from the real filesystem-scanned
 * language ids (`$fs_languages`'s own keys), passed in by the caller --
 * so this DTO can never report a language id that isn't a real, currently
 * installed-on-disk language.
 */
final readonly class LanguagesInstalledActionRequest
{
    private function __construct(
        public ?string $action,
        public ?string $languageId,
    ) {}

    public static function fromGlobals(string $languageIdPattern, InputValidator $inputValidator): self
    {
        return self::fromArray($_GET, $languageIdPattern, $inputValidator);
    }

    /**
     * @param array<int|string, mixed> $source
     */
    public static function fromArray(array $source, string $languageIdPattern, InputValidator $inputValidator): self
    {
        $inputValidator
            ->validate('action', $source, false, '/^(activate|deactivate|set_default|delete)$/');
        $inputValidator
            ->validate('language', $source, false, $languageIdPattern);

        $action = $source['action'] ?? null;
        $language_id = $source['language'] ?? null;

        return new self(
            is_string($action) ? $action : null,
            is_string($language_id) ? $language_id : null,
        );
    }
}
