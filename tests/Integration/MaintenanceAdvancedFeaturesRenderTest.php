<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Override;
use Piwigo\Admin\Projection\AdvancedFeatureLink;
use Piwigo\Admin\Projection\MaintenanceActionsView;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Template\Renderer;
use Piwigo\Template\Template;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

/**
 * The "Advanced features" group of `maintenance_actions.latte` has never
 * rendered in this repository: `MaintenanceActionsPageRenderer` dispatches
 * `GetAdminAdvancedFeaturesLinks` with `[]` and no in-tree handler adds to
 * it, so every fixture and every Browser test sees the fieldset's `n:if`
 * fail. It is a plugin extension point, and AdvancedFeatureLink is the
 * contract those plugins now have to satisfy -- which is worth an
 * assertion rather than being left to the first plugin author to discover.
 *
 * Rendered through the View directly, since reaching it through the page
 * renderer would mean registering a listener inside the served app.
 */
final class MaintenanceAdvancedFeaturesRenderTest extends IntegrationTestCase
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
        // Skips Template's own data_dir_checked write, which would
        // otherwise reach for a full RequestBootstrap dependency this
        // test never boots -- the same note MenubarRendererTest carries.
        $currentConfig->dataDirChecked = '1';

        $this->template = TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes/admin', 'default');
        CurrentTemplateTestFactory::get()->set($this->template);
        $this->renderer = new Renderer(CurrentTemplateTestFactory::get());
    }

    public function testAnAdvancedFeatureLinkRendersItsCaptionUrlAndIconClass(): void
    {
        $html = (string) $this->renderer->render($this->viewWith([
            new AdvancedFeatureLink(caption: 'Build a wall', url: 'admin.php?page=plugin&section=wall', icon: 'icon-th'),
        ]));

        self::assertStringContainsString('Advanced features', $html);
        self::assertStringContainsString('href="admin.php?page=plugin&amp;section=wall"', $html);
        self::assertStringContainsString('class="icon-th maintenance-action"', $html);
        self::assertStringContainsString('Build a wall', $html);
    }

    /**
     * Neither real handler in the reference plugin set sets an icon, which
     * is why $icon is nullable: it renders as the empty leading segment the
     * untyped array produced, not as the string "null".
     */
    public function testAFeatureWithNoIconStillRendersTheActionClass(): void
    {
        $html = (string) $this->renderer->render($this->viewWith([
            new AdvancedFeatureLink(caption: 'Advanced Add Index', url: 'admin.php?page=plugin&overwrite'),
        ]));

        self::assertStringContainsString('class=" maintenance-action"', $html);
        self::assertStringNotContainsString('null maintenance-action', $html);
    }

    /**
     * The in-tree case: the fieldset's own `n:if` keeps the whole group out
     * of the page when no plugin contributed a link.
     */
    public function testTheGroupIsAbsentEntirelyWhenNoPluginContributedALink(): void
    {
        $html = (string) $this->renderer->render($this->viewWith([]));

        self::assertStringNotContainsString('Advanced features', $html);
        // `maintenance-action` alone would not prove anything -- the other
        // fieldsets on this page use it too. `icon-puzzle` is this group's
        // own legend icon.
        self::assertStringNotContainsString('icon-puzzle', $html);
    }

    /**
     * @param list<AdvancedFeatureLink> $advancedFeatures
     */
    private function viewWith(array $advancedFeatures): MaintenanceActionsView
    {
        return new MaintenanceActionsView(
            // Every key maintenance_actions.latte indexes; an incomplete
            // map renders the page but warns on the missing offsets.
            maintActions: [
                'c13y' => [
                    'icon' => 'icon-c13y',
                    'label' => 'c13y',
                ],
                'categories' => [
                    'icon' => 'icon-categories',
                    'label' => 'categories',
                ],
                'compiled-templates' => [
                    'icon' => 'icon-compiled',
                    'label' => 'compiled-templates',
                ],
                'database' => [
                    'icon' => 'icon-database',
                    'label' => 'database',
                ],
                'delete_orphan_tags' => [
                    'icon' => 'icon-delete_orphan_tags',
                    'label' => 'delete_orphan_tags',
                ],
                'derivatives' => [
                    'icon' => 'icon-derivatives',
                    'label' => 'derivatives',
                ],
                'empty_lounge' => [
                    'icon' => 'icon-empty_lounge',
                    'label' => 'empty_lounge',
                ],
                'feeds' => [
                    'icon' => 'icon-feeds',
                    'label' => 'feeds',
                ],
                'history_detail' => [
                    'icon' => 'icon-history_detail',
                    'label' => 'history_detail',
                ],
                'history_summary' => [
                    'icon' => 'icon-history_summary',
                    'label' => 'history_summary',
                ],
                'images' => [
                    'icon' => 'icon-images',
                    'label' => 'images',
                ],
                'lock_gallery' => [
                    'icon' => 'icon-lock_gallery',
                    'label' => 'lock_gallery',
                ],
                'search' => [
                    'icon' => 'icon-search',
                    'label' => 'search',
                ],
                'sessions' => [
                    'icon' => 'icon-sessions',
                    'label' => 'sessions',
                ],
                'unlock_gallery' => [
                    'icon' => 'icon-unlock_gallery',
                    'label' => 'unlock_gallery',
                ],
                'user_cache' => [
                    'icon' => 'icon-user_cache',
                    'label' => 'user_cache',
                ],
            ],
            maintCategories: '',
            maintImages: '',
            maintOrphanTags: '',
            maintUserCache: '',
            maintHistoryDetail: '',
            maintHistorySummary: '',
            maintSessions: '',
            maintFeeds: '',
            maintDatabase: '',
            maintC13y: '',
            maintSearch: '',
            maintCompiledTemplates: '',
            purgeDerivatives: [],
            pwgToken: 'token',
            cacheSizes: null,
            timeElapsedSinceLastCalc: null,
            maintUnlockGallery: null,
            maintLockGallery: null,
            uEmptyLounge: null,
            loungeCounter: null,
            isWebmaster: 1,
            advancedFeatures: $advancedFeatures,
        );
    }
}
