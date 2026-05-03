<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Piwigo\Config\Config;
use Piwigo\Config\UnknownConfigKeyException;
use ReflectionMethod;

final class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        Config::reset();
    }

    protected function tearDown(): void
    {
        Config::reset();
    }

    public function test_raw_returns_default_when_key_missing(): void
    {
        self::assertNull(Config::raw('nonexistent'));
        self::assertSame('fallback', Config::raw('nonexistent', 'fallback'));
    }

    public function test_raw_returns_value_after_loadArray(): void
    {
        Config::loadArray(['upload_dir' => './upload', 'enable_formats' => false]);

        self::assertSame('./upload', Config::raw('upload_dir'));
        self::assertFalse(Config::raw('enable_formats'));
    }

    public function test_typed_accessor_coerces_string_value(): void
    {
        Config::loadArray(['admin_theme' => 42]);

        self::assertSame('42', Config::adminTheme());
    }

    public function test_typed_accessor_int_returns_default_when_unset(): void
    {
        Config::loadArray([]);

        self::assertSame(20, Config::nbmTreatmentTimeoutDefault());
    }

    public function test_typed_accessor_bool_coerces_truthy_values(): void
    {
        Config::loadArray(['activate_comments' => 1, 'allow_html_descriptions' => 0]);

        self::assertTrue(Config::activateComments());
        self::assertFalse(Config::allowHtmlDescriptions());
    }

    public function test_private_typed_getter_rejects_unknown_key(): void
    {
        Config::loadArray([]);
        $getter = new ReflectionMethod(Config::class, 'getString');

        $this->expectException(UnknownConfigKeyException::class);
        $this->expectExceptionMessage("Config::getString('definitely_not_in_schema')");

        $getter->invoke(null, 'definitely_not_in_schema', 'default');
    }

    public function test_paths_cluster_defaults(): void
    {
        Config::loadArray([]);

        self::assertSame('_data/', Config::dataLocation());
        self::assertSame('./upload', Config::uploadDir());
        self::assertSame('', Config::themesDir());
        self::assertSame('/logs', Config::logDir());
        self::assertNull(Config::galleryUrl());
        self::assertSame('', Config::alternativePemUrl());
        self::assertSame('admin.php?page=photos_add', Config::noPhotoYetUrl());
        self::assertSame('', Config::ffmpegDir());
        self::assertSame('', Config::extImagickDir());
    }

    public function test_identity_cluster_defaults(): void
    {
        Config::loadArray([]);

        self::assertSame('Piwigo', Config::galleryTitle());
        self::assertSame(2, Config::guestId());
        self::assertSame(2, Config::defaultUserId());
        self::assertSame(1, Config::webmasterId());
        self::assertFalse(Config::isFormatsEnabled());
        self::assertTrue(Config::allowHtmlDescriptions());
        self::assertTrue(Config::activateComments());
        self::assertSame(['jpg', 'jpeg', 'png', 'gif', 'webp'], Config::pictureExtensions());
        self::assertSame([], Config::fileExtensions()); // populated at runtime from picture_ext + more
    }

    public function test_security_cluster_defaults(): void
    {
        Config::loadArray([]);

        self::assertSame('', Config::secretKey());
        self::assertSame(3 * 24 * 60 * 60, Config::authKeyDuration());
        self::assertFalse(Config::apacheAuthentication());
        self::assertSame('pwg_password_hash', Config::passwordHash());
        self::assertSame('pwg_password_verify', Config::passwordVerify());
    }

    public function test_mail_cluster_defaults(): void
    {
        Config::loadArray([]);

        self::assertSame('', Config::smtpHost());
        self::assertSame('', Config::smtpUser());
        self::assertSame('', Config::smtpPassword());
        self::assertNull(Config::smtpSecure());
        self::assertSame('', Config::mailSenderName());
        self::assertSame('', Config::mailSenderEmail());
        self::assertTrue(Config::mailAllowHtml());
        self::assertFalse(Config::sendBccMailWebmaster());
    }

    public function test_debug_cluster_defaults(): void
    {
        Config::loadArray([]);

        self::assertSame(E_ALL, Config::showPhpErrors());
        self::assertTrue(Config::showPhpErrorsOnFrontend());
        self::assertFalse(Config::showQueries());
        self::assertFalse(Config::debugL10n());
        self::assertFalse(Config::debugTemplate());
        self::assertFalse(Config::debugMail());
        self::assertFalse(Config::dieOnSqlError());
        self::assertFalse(Config::templateForceCompile());
    }

    public function test_session_cluster_defaults(): void
    {
        Config::loadArray([]);

        self::assertTrue(Config::sessionUseCookies());
        self::assertTrue(Config::sessionUseOnlyCookies());
        self::assertFalse(Config::sessionUseTransSid());
        self::assertSame('pwg_id', Config::sessionName());
        self::assertSame('db', Config::sessionSaveHandler());
        self::assertSame(3600, Config::sessionLength());
        self::assertTrue(Config::authorizeRemembering());
        self::assertSame('pwg_remember', Config::rememberMeName());
        self::assertSame(5184000, Config::rememberMeLength());
        self::assertTrue(Config::sessionUseIpAddress());
        self::assertSame(1, Config::sessionGcProbability());
    }

    public function test_derivatives_cluster_defaults(): void
    {
        Config::loadArray([]);

        self::assertSame('medium', Config::derivativeDefaultSize());
        self::assertSame(256000, Config::derivativesStripMetadataThreshold());
        self::assertSame(0755, Config::chmodValue());
        self::assertSame('png', Config::tiffRepresentativeExt());
        self::assertSame(['cr2', 'tif', 'tiff', 'nef', 'dng', 'ai', 'psd'], Config::formatExtensions());
    }

    public function test_tags_cluster_defaults(): void
    {
        Config::loadArray([]);

        self::assertSame(200, Config::fullTagCloudItemsNumber());
        self::assertSame(20, Config::menubarTagCloudItemsNumber());
        self::assertSame('all_or_current', Config::menubarTagCloudContent());
        self::assertSame(12, Config::contentTagCloudItemsNumber());
        self::assertSame(5, Config::tagsLevels());
        self::assertSame('cloud', Config::tagsDefaultDisplayMode());
        self::assertSame(4, Config::tagLettersColumnNumber());
    }

    public function test_uploads_cluster_defaults(): void
    {
        Config::loadArray([]);

        self::assertTrue(Config::uploadFormAutomaticRotation());
        self::assertFalse(Config::uploadFormAllTypes());
        self::assertSame(500, Config::uploadFormChunkSize());
        self::assertSame(1000, Config::uploadFormMaxFileSize());
        self::assertTrue(Config::enableSynchronization());
    }

    public function test_notification_cluster_defaults(): void
    {
        Config::loadArray([]);

        self::assertFalse(Config::nbmDefaultValueUserEnabled());
        self::assertFalse(Config::nbmListAllEnabledUsersToSend());
        self::assertEqualsWithDelta(0.8, Config::nbmMaxTreatmentTimeoutPercent(), 0.001);
        self::assertSame(20, Config::nbmTreatmentTimeoutDefault());
    }

    public function test_infra_cluster_defaults(): void
    {
        Config::loadArray([]);

        self::assertSame('DEBUG', Config::logLevel());
        self::assertSame(30, Config::logArchiveDays());
    }

    public function test_override_updates_typed_accessor_without_persisting(): void
    {
        Config::loadArray(['order_by' => 'ORDER BY id ASC']);
        Config::override('order_by', 'ORDER BY date_creation DESC');

        self::assertSame('ORDER BY date_creation DESC', Config::orderBy());
    }

    public function test_galleryUrl_returns_null_when_unset(): void
    {
        Config::loadArray([]);
        self::assertNull(Config::galleryUrl());
    }

    public function test_galleryUrl_returns_string_when_set(): void
    {
        Config::loadArray(['gallery_url' => 'https://example.com/gallery']);
        self::assertSame('https://example.com/gallery', Config::galleryUrl());
    }

    public function test_pictureExtensions_returns_list_from_data(): void
    {
        Config::loadArray(['picture_ext' => ['jpg', 'png']]);
        self::assertSame(['jpg', 'png'], Config::pictureExtensions());
    }
}
