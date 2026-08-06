<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use LogicException;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Core\Lang;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Admin\Integrity\Event\ListCheckIntegrity;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomalyEntity;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomalyRepository;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Core\AppInfo;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Env;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;

/**
 * CheckIntegrity::check()/display()/maintenance() -- the sibling
 * Integration suite (tests/Integration/Admin/Integrity/
 * CheckIntegrityAddAnomalyTest.php, relocated from tests/Unit/ once
 * CheckIntegrity's constructor started needing a real
 * IntegrityIgnoredAnomalyRepository) already covers add_anomaly()/
 * get_htlm_links_more_info() directly (pure logic, no event/template
 * dependency of their own); check()/display() genuinely need a real
 * IntegrityIgnoredAnomalyRepository (update_conf()'s own persistence) and
 * a real rendered check_integrity.tpl (themes/admin/default/template/), so
 * this suite boots Kernel + a real admin Template directly, the same
 * shape as PictureCommentRendererTest's own gallery-theme Template
 * construction.
 *
 * check() overwrites $this->retrieve_list from the 'list_check_integrity'
 * event on every call (there is no other way to populate it) -- this
 * suite registers its own throwaway `[self::class, 'pushQueuedAnomalies']`
 * handler (an array callable, so EventDispatcher's own dedup-by-identity
 * removeEventHandler() call in tearDown() finds and removes exactly it,
 * never touching any other real handler this process might have
 * registered) instead of exercising the real, DB/filesystem-scanning
 * C13yInternal checks.
 */
final class CheckIntegrityTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    /**
     * @var list<array{anomaly: string, correction_fct: ?string, correction_fct_args: ?array<string, mixed>, correction_msg: ?string}>
     */
    public static array $queuedAnomalies = [];

    public static function pushQueuedAnomalies(ListCheckIntegrity $event): void
    {
        $c13y = $event->value;
        foreach (self::$queuedAnomalies as $a) {
            $c13y->add_anomaly($a['anomaly'], $a['correction_fct'], $a['correction_fct_args'], $a['correction_msg']);
        }
    }

    public static function fakeCorrectionSucceeds(): bool
    {
        return true;
    }

    public static function fakeCorrectionFails(): bool
    {
        return false;
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        Kernel::boot();
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfig::current()));

        DbConnection::build()->executeStatement('DELETE FROM ' . Tables::integrityIgnoredAnomalies());

        self::$queuedAnomalies = [];
        EventDispatcher::get()->addTypedHandler(ListCheckIntegrity::class, [self::class, 'pushQueuedAnomalies']);

        unset($_POST['c13y_submit_correction'], $_POST['c13y_submit_ignore'], $_POST['c13y_selection']);

        CurrentTemplate::current()->set(TemplateTestFactory::build(CurrentPaths::get()->root . 'themes/admin', 'default'));
    }

    #[Override]
    protected function tearDown(): void
    {
        EventDispatcher::get()->removeEventHandler(ListCheckIntegrity::class, [self::class, 'pushQueuedAnomalies']);
        unset($_POST['c13y_submit_correction'], $_POST['c13y_submit_ignore'], $_POST['c13y_selection']);
        DbConnection::build()->executeStatement('DELETE FROM ' . Tables::integrityIgnoredAnomalies());
        CurrentTemplate::current()->reset();
        Kernel::reset();
        parent::tearDown();
    }

    /** @param array<mixed>|null $correctionFctArgs */
    private function anomalyId(string $anomaly, ?string $correctionFct = null, ?array $correctionFctArgs = null, ?string $correctionMsg = null): string
    {
        return md5($anomaly . $correctionFct . serialize($correctionFctArgs) . $correctionMsg);
    }

    private function buildIntegrityRepo(): IntegrityIgnoredAnomalyRepository
    {
        $repo = EntityManagerFactory::build(DbConnection::build())->getRepository(IntegrityIgnoredAnomalyEntity::class);
        self::assertInstanceOf(IntegrityIgnoredAnomalyRepository::class, $repo);

        return $repo;
    }

    private function newCheckIntegrity(): CheckIntegrity
    {
        return new CheckIntegrity(LangTestFactory::get(), $this->buildIntegrityRepo(), new Translator(CurrentConfig::current()), EventDispatcher::get(), PageStateTestFactory::get(), CurrentTemplate::current());
    }

    public function test_check_reports_no_header_note_when_zero_anomalies_are_found(): void
    {
        $before = count(PageStateTestFactory::get()->headerNotes);

        $this->newCheckIntegrity()->check();

        self::assertCount($before, PageStateTestFactory::get()->headerNotes);
    }

    public function test_check_reports_a_singular_header_note_for_exactly_one_anomaly(): void
    {
        self::$queuedAnomalies = [
            ['anomaly' => 'Singular anomaly ' . uniqid(), 'correction_fct' => null, 'correction_fct_args' => null, 'correction_msg' => null],
        ];

        $this->newCheckIntegrity()->check();

        self::assertContains('1 anomaly has been detected.', PageStateTestFactory::get()->headerNotes);
    }

    public function test_check_reports_a_plural_header_note_for_multiple_anomalies(): void
    {
        self::$queuedAnomalies = [
            ['anomaly' => 'Plural anomaly one ' . uniqid(), 'correction_fct' => null, 'correction_fct_args' => null, 'correction_msg' => null],
            ['anomaly' => 'Plural anomaly two ' . uniqid(), 'correction_fct' => null, 'correction_fct_args' => null, 'correction_msg' => null],
        ];

        $this->newCheckIntegrity()->check();

        self::assertContains('2 anomalies have been detected.', PageStateTestFactory::get()->headerNotes);
    }

    public function test_check_correction_mode_reports_the_corrected_count_for_a_successful_fix(): void
    {
        $anomaly = 'Fixable anomaly ' . uniqid();
        $correctionFct = self::class . '::fakeCorrectionSucceeds';
        self::$queuedAnomalies = [
            ['anomaly' => $anomaly, 'correction_fct' => $correctionFct, 'correction_fct_args' => null, 'correction_msg' => null],
        ];
        $_POST['c13y_submit_correction'] = '1';
        $_POST['c13y_selection'] = [$this->anomalyId($anomaly, $correctionFct)];

        $c13y = $this->newCheckIntegrity();
        $c13y->check();

        self::assertContains('1 anomaly has been corrected.', PageStateTestFactory::get()->infos);
        self::assertTrue($c13y->retrieve_list[0]['corrected'] ?? false);
    }

    public function test_check_correction_mode_reports_the_not_corrected_count_for_a_failed_fix(): void
    {
        $anomaly = 'Unfixable anomaly ' . uniqid();
        $correctionFct = self::class . '::fakeCorrectionFails';
        self::$queuedAnomalies = [
            ['anomaly' => $anomaly, 'correction_fct' => $correctionFct, 'correction_fct_args' => null, 'correction_msg' => null],
        ];
        $_POST['c13y_submit_correction'] = '1';
        $_POST['c13y_selection'] = [$this->anomalyId($anomaly, $correctionFct)];

        $c13y = $this->newCheckIntegrity();
        $c13y->check();

        self::assertContains('1 anomaly has not been corrected.', PageStateTestFactory::get()->errors);
        self::assertFalse($c13y->retrieve_list[0]['corrected'] ?? false);
    }

    public function test_check_ignore_mode_marks_the_anomaly_ignored_and_persists_the_build_ignore_list(): void
    {
        $anomaly = 'Ignorable anomaly ' . uniqid();
        $id = $this->anomalyId($anomaly);
        self::$queuedAnomalies = [
            ['anomaly' => $anomaly, 'correction_fct' => null, 'correction_fct_args' => null, 'correction_msg' => null],
        ];
        $_POST['c13y_submit_ignore'] = '1';
        $_POST['c13y_selection'] = [$id];

        $c13y = $this->newCheckIntegrity();
        $c13y->check();

        self::assertContains('1 anomaly has been ignored.', PageStateTestFactory::get()->infos);
        self::assertTrue($c13y->retrieve_list[0]['ignored'] ?? false);
        self::assertSame([$id], $c13y->build_ignore_list);

        $rows = DbConnection::build()->fetchAllAssociative(
            'SELECT anomaly_id, piwigo_version FROM ' . Tables::integrityIgnoredAnomalies()
        );
        self::assertCount(1, $rows);
        self::assertSame($id, $rows[0]['anomaly_id']);
        self::assertSame(AppInfo::VERSION, $rows[0]['piwigo_version']);
    }

    public function test_check_skips_an_already_ignored_anomaly_reported_via_current_config(): void
    {
        $anomaly = 'Pre-ignored anomaly ' . uniqid();
        $id = $this->anomalyId($anomaly);
        $this->buildIntegrityRepo()->syncForVersion(AppInfo::VERSION, [$id], Env::now()->format('Y-m-d H:i:s'));
        self::$queuedAnomalies = [
            ['anomaly' => $anomaly, 'correction_fct' => null, 'correction_fct_args' => null, 'correction_msg' => null],
        ];

        $c13y = $this->newCheckIntegrity();
        $c13y->check();

        self::assertSame([], $c13y->retrieve_list);
        self::assertSame([$id], $c13y->build_ignore_list);
    }

    public function test_maintenance_delegates_to_update_conf_and_clears_the_ignore_list(): void
    {
        // A row for a *different* version than AppInfo::VERSION -- must
        // survive maintenance()/update_conf() untouched, since
        // syncForVersion() is scoped to the current version only (proves
        // the version-scoping design, not just that clearing works).
        $this->buildIntegrityRepo()->syncForVersion('stale-version', ['stale-id'], Env::now()->format('Y-m-d H:i:s'));
        $this->buildIntegrityRepo()->syncForVersion(AppInfo::VERSION, ['current-id'], Env::now()->format('Y-m-d H:i:s'));

        $this->newCheckIntegrity()->maintenance();

        $currentVersionRows = $this->buildIntegrityRepo()->findIgnoredAnomalyIdsForVersion(AppInfo::VERSION);
        self::assertSame([], $currentVersionRows);

        $staleVersionRows = $this->buildIntegrityRepo()->findIgnoredAnomalyIdsForVersion('stale-version');
        self::assertSame(['stale-id'], $staleVersionRows);
    }

    // ------------------------------------------------------------ display()

    public function test_display_flags_an_ignored_anomaly_and_never_offers_it_for_selection(): void
    {
        $c13y = $this->newCheckIntegrity();
        $c13y->retrieve_list = [
            [
                'id' => 'ignored-1',
                'anomaly' => 'An ignored anomaly',
                'correction_fct' => null,
                'correction_fct_args' => null,
                'correction_msg' => null,
                'is_callable' => false,
                'ignored' => true,
            ],
        ];

        $c13y->display();

        $template = CurrentTemplate::current()->get();
        $list = $template->get_template_vars('c13y_list');
        self::assertIsArray($list);
        self::assertIsArray($list[0]);
        self::assertTrue($list[0]['show_ignore_msg']);
        self::assertFalse($list[0]['can_select']);
        self::assertFalse((bool) $template->get_template_vars('c13y_show_submit_ignore'));
        self::assertFalse((bool) $template->get_template_vars('c13y_show_submit_automatic_correction'));
    }

    public function test_display_throws_when_an_anomaly_is_marked_ignored_false(): void
    {
        $c13y = $this->newCheckIntegrity();
        $c13y->retrieve_list = [
            [
                'id' => 'bad-1',
                'anomaly' => 'Malformed anomaly',
                'correction_fct' => null,
                'correction_fct_args' => null,
                'correction_msg' => null,
                'is_callable' => false,
                'ignored' => false,
            ],
        ];

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("\$c13y['ignored'] cannot be false");

        $c13y->display();
    }

    public function test_display_flags_a_successfully_corrected_anomaly(): void
    {
        $c13y = $this->newCheckIntegrity();
        $c13y->retrieve_list = [
            [
                'id' => 'corrected-1',
                'anomaly' => 'A corrected anomaly',
                'correction_fct' => 'strlen',
                'correction_fct_args' => null,
                'correction_msg' => null,
                'is_callable' => true,
                'corrected' => true,
            ],
        ];

        $c13y->display();

        $list = CurrentTemplate::current()->get()->get_template_vars('c13y_list');
        self::assertIsArray($list);
        self::assertIsArray($list[0]);
        self::assertTrue($list[0]['show_correction_success_fct']);
        self::assertFalse($list[0]['can_select']);
    }

    public function test_display_flags_a_failed_correction_with_the_more_info_links(): void
    {
        $c13y = $this->newCheckIntegrity();
        $c13y->retrieve_list = [
            [
                'id' => 'failed-1',
                'anomaly' => 'A failed-correction anomaly',
                'correction_fct' => 'strlen',
                'correction_fct_args' => null,
                'correction_msg' => null,
                'is_callable' => true,
                'corrected' => false,
            ],
        ];

        $c13y->display();

        $list = CurrentTemplate::current()->get()->get_template_vars('c13y_list');
        self::assertIsArray($list);
        self::assertIsArray($list[0]);
        self::assertFalse($list[0]['show_correction_success_fct']);
        self::assertFalse($list[0]['can_select']);
        self::assertIsString($list[0]['correction_error_fct']);
        self::assertNotSame('', $list[0]['correction_error_fct']);
        self::assertStringContainsString('forum', $list[0]['correction_error_fct']);
    }

    public function test_display_offers_a_callable_uncorrected_anomaly_for_automatic_correction(): void
    {
        $c13y = $this->newCheckIntegrity();
        $c13y->retrieve_list = [
            [
                'id' => 'selectable-1',
                'anomaly' => 'A selectable anomaly',
                'correction_fct' => 'strlen',
                'correction_fct_args' => null,
                'correction_msg' => null,
                'is_callable' => true,
            ],
        ];

        $c13y->display();

        $template = CurrentTemplate::current()->get();
        $list = $template->get_template_vars('c13y_list');
        self::assertIsArray($list);
        self::assertIsArray($list[0]);
        self::assertTrue($list[0]['show_correction_fct']);
        self::assertTrue($list[0]['can_select']);
        self::assertTrue((bool) $template->get_template_vars('c13y_show_submit_automatic_correction'));
        self::assertTrue((bool) $template->get_template_vars('c13y_show_submit_ignore'));
        $doCheck = $template->get_template_vars('c13y_do_check');
        self::assertIsArray($doCheck);
        self::assertContains('selectable-1', $doCheck);
    }

    public function test_display_flags_a_non_callable_correction_function_as_a_bad_fct(): void
    {
        $c13y = $this->newCheckIntegrity();
        $c13y->retrieve_list = [
            [
                'id' => 'bad-fct-1',
                'anomaly' => 'A bad-fct anomaly',
                'correction_fct' => 'this_function_does_not_exist_anywhere',
                'correction_fct_args' => null,
                'correction_msg' => null,
                'is_callable' => false,
            ],
        ];

        $c13y->display();

        $list = CurrentTemplate::current()->get()->get_template_vars('c13y_list');
        self::assertIsArray($list);
        self::assertIsArray($list[0]);
        self::assertTrue($list[0]['show_correction_bad_fct']);
        self::assertTrue($list[0]['can_select']);
    }

    public function test_display_shows_a_correction_msg_for_an_anomaly_with_no_correction_function(): void
    {
        $c13y = $this->newCheckIntegrity();
        $c13y->retrieve_list = [
            [
                'id' => 'msg-only-1',
                'anomaly' => 'A message-only anomaly',
                'correction_fct' => null,
                'correction_fct_args' => null,
                'correction_msg' => 'please fix this by hand',
                'is_callable' => false,
            ],
        ];

        $c13y->display();

        $list = CurrentTemplate::current()->get()->get_template_vars('c13y_list');
        self::assertIsArray($list);
        self::assertIsArray($list[0]);
        self::assertTrue($list[0]['can_select']);
        self::assertSame('please fix this by hand', $list[0]['correction_msg']);
    }

    public function test_display_does_nothing_when_there_are_no_anomalies(): void
    {
        $c13y = $this->newCheckIntegrity();

        $c13y->display();

        // No 'check_integrity' filename ever gets set_filenames()'d, so
        // parse() never runs -- get_template_vars() for a var nothing ever
        // assigned stays null.
        self::assertNull(CurrentTemplate::current()->get()->get_template_vars('c13y_show_submit_ignore'));
    }
}
