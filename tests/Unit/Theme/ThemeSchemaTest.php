<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Theme;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validates that docs/schemas/theme.schema.json accepts well-formed
 * theme.json manifests and rejects malformed ones. Exercises every
 * required field, every constrained field (parent, loadParentCss,
 * assets, localHead, main, autoload), and edge cases.
 */
final class ThemeSchemaTest extends TestCase
{
    private Validator $validator;

    private object $schema;

    #[\Override]
    protected function setUp(): void
    {
        $schemaJson = file_get_contents(PHPWG_ROOT_PATH . 'docs/schemas/theme.schema.json');
        self::assertNotFalse($schemaJson, 'theme.schema.json missing');
        $decoded = json_decode($schemaJson, false, 512, JSON_THROW_ON_ERROR);
        self::assertIsObject($decoded, 'theme.schema.json must decode to an object');
        $this->schema = $decoded;
        $this->validator = new Validator();
    }

    public function testCanonicalRootThemeValidates(): void
    {
        $manifest = (object) [
            'id' => '_base',
            'version' => '1.0.0',
            'name' => 'Base',
            'main' => 'Piwigo\\Theme\\Base\\Theme',
        ];

        $result = $this->validator->validate($manifest, $this->schema);
        self::assertTrue($result->isValid(), $this->formatError($result));
    }

    public function testChildThemeWithFullManifestValidates(): void
    {
        $manifest = (object) [
            '$schema' => 'https://raw.githubusercontent.com/darktorres/piwigo16/16.x-rewrite/docs/schemas/theme.schema.json',
            'id' => 'standard_pages',
            'version' => '1.0.0',
            'name' => 'Standard Pages',
            'parent' => '_base',
            'loadParentCss' => false,
            'assets' => (object) [
                'img' => 'images',
                'icon' => 'icon',
                'mimeIcon' => 'icon/mimetypes',
            ],
            'localHead' => 'local_head.latte',
            'main' => 'Piwigo\\Theme\\StandardPages\\Theme',
            'autoload' => (object) [
                'psr-4' => (object) [
                    'Piwigo\\Theme\\StandardPages\\' => 'src/',
                ],
            ],
        ];

        $result = $this->validator->validate($manifest, $this->schema);
        self::assertTrue($result->isValid(), $this->formatError($result));
    }

    public function testNullParentValidates(): void
    {
        $manifest = (object) [
            'id' => '_base',
            'version' => '1.0.0',
            'name' => 'Base',
            'parent' => null,
            'main' => 'Piwigo\\Theme\\Base\\Theme',
        ];

        $result = $this->validator->validate($manifest, $this->schema);
        self::assertTrue($result->isValid(), $this->formatError($result));
    }

    /** @return iterable<string, array{0: object, 1: string}> */
    public static function invalidManifestProvider(): iterable
    {
        $base = static fn (): object => (object) [
            'id' => 'standard_pages',
            'version' => '1.0.0',
            'name' => 'Standard Pages',
            'main' => 'Piwigo\\Theme\\StandardPages\\Theme',
        ];

        $without = static function (string $missing) use ($base): object {
            $m = $base();
            unset($m->$missing);
            return $m;
        };

        $with = static function (string $field, mixed $value) use ($base): object {
            $m = $base();
            $m->$field = $value;
            return $m;
        };

        yield 'missing id'      => [$without('id'),      'id is required'];
        yield 'missing version' => [$without('version'), 'version is required'];
        yield 'missing name'    => [$without('name'),    'name is required'];
        yield 'missing main'    => [$without('main'),    'main is required'];

        yield 'id with slash'        => [$with('id', 'admin/dark'), 'id pattern rejects slashes'];
        yield 'parent with space'    => [$with('parent', 'pure default'), 'parent must match id pattern'];
        yield 'loadParentCss string' => [$with('loadParentCss', 'yes'), 'loadParentCss must be boolean'];
        yield 'localHead empty'      => [$with('localHead', ''), 'localHead minLength=1'];
        yield 'main with slash'      => [$with('main', 'Piwigo/Theme/Foo'), 'main must use backslash-namespaced FQCN'];
        yield 'unknown top-level key' => [$with('color_scheme', 'dark'), 'additionalProperties: false'];
    }

    #[DataProvider('invalidManifestProvider')]
    public function testInvalidManifestRejected(object $manifest, string $reason): void
    {
        $result = $this->validator->validate($manifest, $this->schema);
        self::assertFalse($result->isValid(), "Expected rejection: {$reason}");
    }

    private function formatError(\Opis\JsonSchema\ValidationResult $result): string
    {
        if (!$result->hasError()) {
            return '';
        }
        $err = $result->error();
        if ($err === null) {
            return '';
        }
        return $err->message() . ' at ' . implode('/', $err->data()->path());
    }
}
