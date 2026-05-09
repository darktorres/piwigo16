<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Template;

use Latte\SecurityViolationException;
use PHPUnit\Framework\TestCase;
use Piwigo\Core\Lang;
use Piwigo\Lang\Translator;
use Piwigo\Template\Latte\PiwigoPolicy;
use Piwigo\Template\LatteEngine;
use Piwigo\Template\TemplateRegistry;

/**
 * Phase E — sandbox policy for plugin-supplied templates. Pins the
 * allow/deny matrix at the policy level (unit) and round-trips a few
 * representative cases through a sandboxed engine to prove the policy
 * actually blocks at compile time.
 */
final class PiwigoPolicyTest extends TestCase
{
    private string $tempDir = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/piwigo-latte-policy-' . uniqid('', true);
        mkdir($this->tempDir, 0o700, true);

        Lang::reset();
        Translator::reset();
        unset($GLOBALS['lang']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Lang::reset();
        Translator::reset();
        TemplateRegistry::reset();
        unset($GLOBALS['lang']);
        unset($GLOBALS['template']);

        $files = glob($this->tempDir . '/*');
        foreach ($files === false ? [] : $files as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function test_plugin_policy_allows_structural_tags_and_print(): void
    {
        $policy = PiwigoPolicy::createPluginPolicy();

        self::assertTrue($policy->isTagAllowed('if'));
        self::assertTrue($policy->isTagAllowed('foreach'));
        self::assertTrue($policy->isTagAllowed('var'));
        self::assertTrue($policy->isTagAllowed('='));
        self::assertTrue($policy->isTagAllowed('block'));
        self::assertTrue($policy->isTagAllowed('translate'));
    }

    public function test_plugin_policy_denies_dangerous_tags(): void
    {
        $policy = PiwigoPolicy::createPluginPolicy();

        // Arbitrary PHP — never allowed for plugins.
        self::assertFalse($policy->isTagAllowed('php'));
        // Filesystem-traversing tags.
        self::assertFalse($policy->isTagAllowed('include'));
        self::assertFalse($policy->isTagAllowed('extends'));
        self::assertFalse($policy->isTagAllowed('layout'));
        self::assertFalse($policy->isTagAllowed('embed'));
        self::assertFalse($policy->isTagAllowed('import'));
        // `do` could trigger asset-pipeline side effects via the core
        // extension's functions; deny for plugins.
        self::assertFalse($policy->isTagAllowed('do'));
    }

    public function test_plugin_policy_allows_safe_filters(): void
    {
        $policy = PiwigoPolicy::createPluginPolicy();

        self::assertTrue($policy->isFilterAllowed('translate'));
        self::assertTrue($policy->isFilterAllowed('translate_dec'));
        self::assertTrue($policy->isFilterAllowed('escapeHtml'));
        self::assertTrue($policy->isFilterAllowed('urlencode'));
        self::assertTrue($policy->isFilterAllowed('default'));
        self::assertTrue($policy->isFilterAllowed('count'));
        self::assertTrue($policy->isFilterAllowed('strip_tags'));
    }

    public function test_plugin_policy_denies_state_or_filesystem_filters(): void
    {
        $policy = PiwigoPolicy::createPluginPolicy();

        // Filesystem touchers — should never reach plugin templates.
        self::assertFalse($policy->isFilterAllowed('file_exists'));
        self::assertFalse($policy->isFilterAllowed('is_file'));
        // PHP-runtime reflection.
        self::assertFalse($policy->isFilterAllowed('constant'));
        // Opaque payload decoders.
        self::assertFalse($policy->isFilterAllowed('json_decode'));
        self::assertFalse($policy->isFilterAllowed('stripslashes'));
    }

    public function test_plugin_policy_allows_form_helpers_only(): void
    {
        $policy = PiwigoPolicy::createPluginPolicy();

        // Read-only form helpers.
        self::assertTrue($policy->isFunctionAllowed('htmlOptions'));
        self::assertTrue($policy->isFunctionAllowed('htmlRadios'));
        self::assertTrue($policy->isFunctionAllowed('url_is_remote'));

        // Asset-pipeline functions mutate per-request layout state — deny.
        self::assertFalse($policy->isFunctionAllowed('combineScript'));
        self::assertFalse($policy->isFunctionAllowed('combineCss'));
        self::assertFalse($policy->isFunctionAllowed('htmlHead'));
        self::assertFalse($policy->isFunctionAllowed('defineDerivative'));
        // math() eval-evaluates a user-supplied expression — deny for
        // plugins even though regex-validated inside.
        self::assertFalse($policy->isFunctionAllowed('math'));
    }

    public function test_core_policy_allows_what_plugin_policy_denies(): void
    {
        $policy = PiwigoPolicy::createCorePolicy();

        self::assertTrue($policy->isTagAllowed('do'));
        self::assertTrue($policy->isTagAllowed('include'));
        self::assertTrue($policy->isFilterAllowed('file_exists'));
        self::assertTrue($policy->isFilterAllowed('json_decode'));
        self::assertTrue($policy->isFunctionAllowed('combineScript'));
        self::assertTrue($policy->isFunctionAllowed('htmlHead'));
        self::assertTrue($policy->isFunctionAllowed('math'));

        // `php` tag stays denied even for core — Latte expressions
        // cover everything the templates need.
        self::assertFalse($policy->isTagAllowed('php'));
    }

    public function test_sandboxed_engine_renders_safe_template(): void
    {
        $engine = new LatteEngine($this->tempDir, PiwigoPolicy::createPluginPolicy());

        Lang::loadArray(['Hello' => 'Olá']);
        $output = $engine->renderFromString(
            "{='Hello'|translate}, {if \$n > 0}{\$n} item(s){else}empty{/if}",
            ['n' => 3],
        );

        self::assertSame('Olá, 3 item(s)', $output);
    }

    public function test_sandboxed_engine_blocks_php_tag(): void
    {
        $engine = new LatteEngine($this->tempDir, PiwigoPolicy::createPluginPolicy());

        $this->expectException(SecurityViolationException::class);
        $engine->renderFromString('{php echo "leaked";}');
    }

    public function test_sandboxed_engine_blocks_filesystem_filter(): void
    {
        $engine = new LatteEngine($this->tempDir, PiwigoPolicy::createPluginPolicy());

        $this->expectException(SecurityViolationException::class);
        $engine->renderFromString("{='/etc/passwd'|file_exists}");
    }

    public function test_sandboxed_engine_blocks_asset_pipeline_function(): void
    {
        $engine = new LatteEngine($this->tempDir, PiwigoPolicy::createPluginPolicy());

        $this->expectException(SecurityViolationException::class);
        $engine->renderFromString("{do combineScript(id: 'x', load: 'footer', path: 'x.js')}");
    }
}
