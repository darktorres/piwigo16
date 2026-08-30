<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Override;
use Piwigo\Admin\Projection\UpdatesPwgView;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Template\Renderer;
use Piwigo\Template\Template;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

/**
 * `updates_pwg.latte` step 3's "may not be compatible" block, which has
 * never rendered in this repository and, since the P23 port, could not
 * have -- see `ExtensionUpdateChecker::getMissingPlugins()` for the key
 * mismatch that broke it and the test that now pins the join.
 *
 * This covers the other half: what the template does once it is handed a
 * missing extension. It is a View-level render rather than a Browser test
 * because step 3 survives the renderer's own step validation only when
 * `CoreUpdateService::getPiwigoNewVersions()` reports a major release, and
 * that reads `AppInfo::URL . '/download/all_versions.php'` -- a compile-time
 * const pointed at piwigo.org, with none of the three env overrides its PEM
 * neighbour has (`RequestBootstrap::pemUrl()`). Since this branch
 * deliberately versions ahead of upstream, that feed reports nothing newer
 * than AppInfo::VERSION, `major` stays null, and `?step=3` falls back to 0.
 * Depending on upstream's live state would be the wrong fix even if it
 * currently answered differently.
 */
final class UpdatesPwgMissingExtensionsRenderTest extends IntegrationTestCase
{
    private Template $template;

    private Renderer $renderer;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        // Skips Template's own data_dir_checked write, which would otherwise
        // reach for a full RequestBootstrap dependency this test never boots.
        $currentConfig->dataDirChecked = '1';

        $this->template = TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes/admin', 'default');
        CurrentTemplateTestFactory::get()->set($this->template);
        $this->renderer = new Renderer(CurrentTemplateTestFactory::get());
    }

    public function testMissingPluginsAndThemesEachRenderTheirWarningAndLinks(): void
    {
        $html = $this->render(
            missingPlugins: [
                [
                    'uri' => 'https://pem.example.invalid/extension_view.php?eid=101',
                    'name' => 'Legacy Plugin',
                ],
            ],
            missingThemes: [
                [
                    'uri' => 'https://pem.example.invalid/extension_view.php?eid=202',
                    'name' => 'Legacy Theme',
                ],
            ],
        );

        self::assertStringContainsString('Following plugins may not be compatible', $html);
        self::assertStringContainsString('eid=101', $html);
        self::assertStringContainsString('Legacy Plugin', $html);

        self::assertStringContainsString('Following themes may not be compatible', $html);
        self::assertStringContainsString('eid=202', $html);
        self::assertStringContainsString('Legacy Theme', $html);

        // The two consequences of a missing extension, both unreachable
        // along with the lists: the acknowledgement checkbox, and a submit
        // button the admin cannot press until they tick it.
        self::assertStringContainsString('I decide to update anyway', $html);
        self::assertStringContainsString('disabled="disabled"', $html);
    }

    /**
     * Only plugins missing: the themes half of every `or` must stay false,
     * so the themes paragraph is absent while the checkbox and the disabled
     * button are still there.
     */
    public function testOnlyMissingPluginsStillDisablesTheUpdateButton(): void
    {
        $html = $this->render(
            missingPlugins: [
                [
                    'uri' => 'https://pem.example.invalid/extension_view.php?eid=101',
                    'name' => 'Legacy Plugin',
                ],
            ],
            missingThemes: [],
        );

        self::assertStringContainsString('Following plugins may not be compatible', $html);
        self::assertStringNotContainsString('Following themes may not be compatible', $html);
        self::assertStringContainsString('I decide to update anyway', $html);
        self::assertStringContainsString('disabled="disabled"', $html);
    }

    /**
     * The state every real request has produced since the port, whether or
     * not anything was actually missing.
     */
    public function testNothingMissingLeavesTheUpdateButtonPressable(): void
    {
        $html = $this->render(missingPlugins: [], missingThemes: []);

        self::assertStringNotContainsString('may not be compatible', $html);
        self::assertStringNotContainsString('I decide to update anyway', $html);
        self::assertStringNotContainsString('disabled="disabled"', $html);
    }

    /**
     * @param list<array{uri: string, name: string}> $missingPlugins
     * @param list<array{uri: string, name: string}> $missingThemes
     */
    private function render(array $missingPlugins, array $missingThemes): string
    {
        return (string) $this->renderer->render(new UpdatesPwgView(
            containerVersion: null,
            dockerUpdateGuideUrl: null,
            checkVersion: true,
            devVersion: false,
            missingPlugins: $missingPlugins,
            missingThemes: $missingThemes,
            minorReleasePhpRequired: null,
            majorReleasePhpRequired: null,
            step: 3,
            piwigoCurrentVersion: '17.0.0',
            upgradeTo: '18.0.0',
            csrfToken: 'token',
            minorVersion: null,
            minorReleaseUrl: null,
            majorVersion: '18.0.0',
            majorReleaseUrl: 'https://piwigo.example.invalid/releases/18.0.0',
            majorDockerReleaseUrl: null,
        ));
    }
}
