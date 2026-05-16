<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Plugin;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Validates that docs/schemas/plugin.schema.json accepts well-formed
 * plugin.json manifests and rejects malformed ones. Exercises every
 * required field, every constrained field (version, license, hasSettings,
 * minPiwigo, main, autoload), and a handful of edge cases.
 */
/** @psalm-suppress PropertyNotSetInConstructor — properties initialized in setUp() */
final class PluginSchemaTest extends TestCase
{
    private Validator $validator;

    private object $schema;

    #[\Override]
    protected function setUp(): void
    {
        $schemaJson = file_get_contents(PHPWG_ROOT_PATH . 'docs/schemas/plugin.schema.json');
        self::assertNotFalse($schemaJson, 'plugin.schema.json missing');
        $decoded = json_decode($schemaJson, false, 512, JSON_THROW_ON_ERROR);
        self::assertIsObject($decoded, 'plugin.schema.json must decode to an object');
        $this->schema = $decoded;
        $this->validator = new Validator();
    }

    public function testCanonicalManifestValidates(): void
    {
        $manifest = (object) [
            'id' => 'my-plugin',
            'name' => 'My Plugin',
            'version' => '1.4.0',
            'description' => 'What this plugin does, one line.',
            'license' => 'GPL-3.0-or-later',
            'minPiwigo' => '17.0',
            'main' => 'Piwigo\\Plugin\\MyPlugin\\Plugin',
        ];

        $result = $this->validator->validate($manifest, $this->schema);
        self::assertTrue($result->isValid(), $this->formatError($result));
    }

    public function testFullManifestWithAllOptionalFieldsValidates(): void
    {
        $manifest = (object) [
            '$schema' => 'https://raw.githubusercontent.com/darktorres/piwigo16/16.x-rewrite/docs/schemas/plugin.schema.json',
            'id' => 'my-plugin',
            'name' => 'My Plugin',
            'version' => '1.4.0-beta.1',
            'description' => 'What this plugin does, one line.',
            'homepage' => 'https://example.com/my-plugin',
            'author' => 'Jane Developer',
            'authorUri' => 'https://example.com',
            'license' => 'GPL-3.0-or-later',
            'minPiwigo' => '17.0',
            'hasSettings' => 'webmaster',
            'require' => (object) [
                'piwigo' => '^17.0',
                'plugin/GrumPluginClasses' => '^4.0',
            ],
            'main' => 'Piwigo\\Plugin\\MyPlugin\\Plugin',
            'autoload' => (object) [
                'psr-4' => (object) [
                    'Piwigo\\Plugin\\MyPlugin\\' => 'src/',
                ],
            ],
        ];

        $result = $this->validator->validate($manifest, $this->schema);
        self::assertTrue($result->isValid(), $this->formatError($result));
    }

    /** @return iterable<string, array{0: object, 1: string}> */
    public static function invalidManifestProvider(): iterable
    {
        $base = static fn (): object => (object) [
            'id' => 'my-plugin',
            'name' => 'My Plugin',
            'version' => '1.0.0',
            'description' => 'desc',
            'license' => 'MIT',
            'minPiwigo' => '17.0',
            'main' => 'Piwigo\\Plugin\\MyPlugin\\Plugin',
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

        yield 'missing id'          => [$without('id'),          'id is required'];
        yield 'missing name'        => [$without('name'),        'name is required'];
        yield 'missing version'     => [$without('version'),     'version is required'];
        yield 'missing description' => [$without('description'), 'description is required'];
        yield 'missing license'     => [$without('license'),     'license is required'];
        yield 'missing minPiwigo'   => [$without('minPiwigo'),   'minPiwigo is required'];
        yield 'missing main'        => [$without('main'),        'main is required'];

        yield 'id with spaces'      => [$with('id', 'my plugin'),   'id pattern rejects whitespace'];
        yield 'id empty'            => [$with('id', ''),            'id minLength=1'];
        yield 'version garbage'     => [$with('version', 'abc'),    'version must look like SemVer/revision'];
        yield 'minPiwigo missing minor' => [$with('minPiwigo', '17'), 'minPiwigo requires MAJOR.MINOR'];
        yield 'hasSettings string'  => [$with('hasSettings', 'admin'), 'hasSettings only allows true/false/"webmaster"'];
        yield 'main with slash'     => [$with('main', 'Piwigo/Plugin/Foo'), 'main must use backslash-namespaced FQCN'];
        yield 'homepage not url'    => [$with('homepage', 'not a url'), 'homepage must be a URI'];
        yield 'unknown top-level key' => [$with('unexpected_field', 'foo'), 'additionalProperties: false'];
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
