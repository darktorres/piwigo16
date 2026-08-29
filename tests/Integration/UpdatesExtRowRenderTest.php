<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Override;
use Piwigo\Admin\Projection\ExtensionUpdateRow;
use Piwigo\Admin\Projection\UpdatesExtView;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Template\Renderer;
use Piwigo\Template\Template;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

/**
 * `updates_ext.latte`'s update rows have never rendered in this
 * repository: reaching them needs PEM to report a pending update, so
 * `admin-updates-ext.html` captures the "all up to date" state and every
 * expression inside the `{foreach}` is unasserted. That is the whole row
 * body -- ten reads, which P58 has just moved from an untyped bag onto
 * ExtensionUpdateRow.
 */
final class UpdatesExtRowRenderTest extends IntegrationTestCase
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

    public function testAPendingUpdateRowRendersEveryFieldItCarries(): void
    {
        $html = $this->render($this->row());

        self::assertStringContainsString('id="plugins_MyPlugin"', $html);
        self::assertStringContainsString('id="desc_842"', $html);
        self::assertStringContainsString('My Plugin', $html);
        self::assertStringContainsString('2.1.0', $html);
        self::assertStringContainsString('2.4.0', $html);
        self::assertStringContainsString('Fixes a crash', $html);
        self::assertStringContainsString('eid=842#changelog', $html);
        self::assertStringContainsString('origin=piwigo_download', $html);
    }

    /**
     * The revision reaches JavaScript as a quoted string, which is what the
     * update API accepts: ExtensionUpdateInput reads the posted revision
     * through `is_string(...) ? ... : ''`, so a bare number would arrive as
     * an empty revision. Latte's own JS-context escaping supplies the
     * quotes, so this asserts the escaped attribute as rendered.
     */
    public function testTheRevisionReachesTheUpdateCallAsAQuotedString(): void
    {
        $html = $this->render($this->row());

        self::assertStringContainsString(
            'updateExtension(&quot;plugins&quot;, &quot;MyPlugin&quot;, &quot;91&quot;)',
            $html,
        );
    }

    /**
     * `$ignored` drives two separate places in one element -- a class and a
     * data attribute -- which is why it is a bool on the VO rather than a
     * `mixed` the template tests for truthiness twice.
     */
    public function testAnIgnoredRowIsHiddenAndMarked(): void
    {
        $html = $this->render($this->row(ignored: true));

        self::assertStringContainsString('pluginMiniBox u-hidden', $html);
        self::assertStringContainsString('data-ignored="true"', $html);
    }

    public function testANonIgnoredRowIsNeitherHiddenNorMarked(): void
    {
        $html = $this->render($this->row());

        // `u-hidden` on its own appears elsewhere on the page; what must be
        // absent is the row element carrying it.
        self::assertStringNotContainsString('pluginMiniBox u-hidden', $html);
        self::assertStringNotContainsString('data-ignored', $html);
    }

    private function row(bool $ignored = false): ExtensionUpdateRow
    {
        return new ExtensionUpdateRow(
            id: '842',
            revisionId: '91',
            extId: 'MyPlugin',
            name: 'My Plugin',
            url: 'https://pem.example.invalid/extension_view.php?eid=842#changelog',
            revisionDescription: 'Fixes a crash',
            currentVersion: '2.1.0',
            newVersion: '2.4.0',
            downloadUrl: 'https://pem.example.invalid/download.php?rid=91&amp;origin=piwigo_download',
            ignored: $ignored,
        );
    }

    private function render(ExtensionUpdateRow $row): string
    {
        return (string) $this->renderer->render(new UpdatesExtView(
            updatesExtension: [
                'plugins' => [$row],
            ],
            showReset: false,
            pwgToken: 'token',
            extType: 'plugins',
            isWebmaster: 1,
        ));
    }
}
