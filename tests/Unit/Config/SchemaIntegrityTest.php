<?php

declare(strict_types=1);

use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Db\EntityManagerFactory;

// Guards the property contract on Piwigo\Config\CurrentConfig: every
// config-value property is publicly readable, write access matches an
// explicit private(set) allow-list, and a `get`/`set` hook exists only on
// the documented properties that carry real logic beyond a plain
// pass-through.
//
// CurrentConfig's config-key properties are instance members (not static).
// This file's own reflection filters those out, plus the private backing
// fields behind the fully-hooked properties (chmodValue/recentPostDates/
// defaultFiltersViews/filterPages/orderBy/orderByInsideCategory) --
// implementation detail of those properties, not config keys of their own.

const SCHEMA_INTEGRITY_BACKING_FIELDS = ['chmodValueStorage', 'recentPostDatesStorage', 'defaultFiltersViewsStorage', 'filterPagesStorage', 'orderByStorage', 'orderByInsideCategoryStorage'];

// There is deliberately no allow-list here any more: every public zero-arg
// static method on CurrentConfig must be backed by a property of the same
// name. The last exception, defaultsArray(), was a hand-picked defaults
// snapshot read by exactly one caller
// (ConfigurationSubController::orderByIsLocal()), and both went with
// order_by_custom.

// Never written from outside CurrentConfig today -- see the property-hooks
// conversion this file's own tests below enforce. A property landing here
// is a deliberate encapsulation decision, not an accident; keep this list
// and CurrentConfig.php in sync by hand (no schema/generator to run, same
// as adding a new property).
const SCHEMA_INTEGRITY_PRIVATE_SET_LIST = [
    'activityDisplayConnections', 'addCacheToStorageChart', 'adminTheme', 'albumDescriptionOnAllPages', 'albumMoveDelayBeforeAutoOpening',
    'allowHtmlDescriptions', 'allowUserCustomization', 'allowUserRegistration', 'allowWebServices', 'authorizeRemembering',
    'batchManagerImagesPerPageGlobal', 'batchManagerImagesPerPageUnit', 'cacheBackend', 'cacheDefaultTtl', 'cacheNamespace',
    'cacheRedisUrl', 'cacheSizes', 'calendarShowAny', 'calendarShowEmpty', 'checksumComputeBlocksize',
    'commentsOrder', 'commentsPageNbComments', 'contentTagCloudItemsNumber', 'dashboardActivityNbWeeks', 'dashboardCheckForUpdates',
    'derivativeDefaultSize', 'derivativesStripMetadataThreshold', 'dieOnSqlError', 'doublePasswordTypeInAdmin', 'ffmpegDir',
    'fsQuickCheckLastCheck', 'fullTagCloudItemsNumber', 'historyAdmin', 'historyGuest', 'indexCaddieIcon',
    'indexCreatedDateIcon', 'indexEditIcon', 'indexFlatIcon', 'indexNewIcon', 'indexPostedDateIcon',
    'indexSearchInSetAction', 'indexSearchInSetButton', 'indexSizesIcon', 'indexSlideshowIcon', 'indexSortOrderInput',
    'inheritanceByDefault', 'lastMajorUpdate', 'levelSeparator', 'lightAlbumManagerThreshold', 'lightSlideshow',
    'linkedAlbumSearchLimit', 'logArchiveDays', 'logDir', 'logLevel', 'loginLockoutDurationMinutes',
    'loginLockoutWindowMinutes', 'maxRequests', 'menubarTagCloudContent', 'menubarTagCloudItemsNumber', 'nbCategoriesPage',
    'nbLogsPage', 'nbmDefaultValueUserEnabled', 'nbmSendHtmlMail', 'nbmSendRecentPostDates', 'newcatDefaultCommentable',
    'newcatDefaultStatus', 'newcatDefaultVisible', 'noPhotoYet', 'noPhotoYetUrl', 'pageBanner',
    'passwordActivationDuration', 'passwordResetCodeDuration', 'passwordResetDuration', 'passwordResetRequestWindowMinutes', 'pdfRepresentativeExt',
    'pdfViewerFilesizeThreshold',
    'pemLanguagesCategory', 'pemPluginsCategory', 'pemThemesCategory', 'pictureCaddieIcon', 'pictureDownloadIcon',
    'pictureEditIcon', 'pictureFavoriteIcon', 'pictureMenu', 'pictureMetadataIcon', 'pictureNavigationIcons',
    'pictureNavigationThumb', 'pictureRepresentativeIcon', 'pictureSizesIcon', 'pictureSlideshowIcon', 'piwigoInstalledVersion',
    'relatedAlbumsDisplayLimit', 'relatedAlbumsMaximumItemsToCompute', 'rememberMeLength', 'rememberMeName', 'representativeCacheOnLevel',
    'representativeCacheOnSubcats', 'rssFeedAuthor', 'sendPiwigoInfosLastNotice', 'sendPiwigoInfosOriginHash', 'sendPiwigoInfosPeriod',
    'sendPiwigoInfosUpdateUrl', 'sessionGcProbability', 'sessionName', 'sessionSaveHandler', 'sessionUseCookies',
    'sessionUseOnlyCookies', 'sessionUseTransSid', 'showMobileAppBannerInAdmin', 'showMobileAppBannerInGallery', 'showNewsletterSubscription',
    'showPiwigoLatestNews', 'showThumbnailCaption', 'slideshowPeriodStep', 'standardPagesSelectedLogo',
    'standardPagesSelectedLogoPath', 'standardPagesSelectedSkin', 'statCompareYearDisplayed', 'tagLettersColumnNumber', 'tagsDefaultDisplayMode',
    'templateForceCompile', 'topNumber', 'trustedProxies', 'uniquenessMode', 'updateNotifyReminderPeriod',
    'uploadFormAutomaticRotation', 'uploadFormChunkSize', 'uploadFormMaxFileSize',
];

// The only properties whose get/set carries real logic (sanitization, a
// SAPI-dependent default, a lazily-built value object) instead of a plain
// pass-through -- see CurrentConfig.php's own per-property docblocks. Also
// includes the 3 get-only computed properties (themesPath/combinedDir/
// derivativeDir) -- not config keys of their own, but real hooked
// properties all the same (a `get` hook computing a derived value, no
// `set` at all).
const SCHEMA_INTEGRITY_HOOKED_LIST = [
    'apiKeyDuration', 'apiKeyForbiddenMethods', 'availablePermissionLevels', 'blkMenubar',
    'chmodValue', 'combinedDir', 'defaultFiltersViews', 'derivativeDir',
    'fileExtensions', 'filterPages', 'formatExtensions',
    'headerNotes', 'historySectionsCache', 'links', 'metadataKeywordSeparatorRegex',
    'pictureExtensions', 'pictureInformations', 'randomIndexRedirect', 'rateItems',
    'orderBy', 'orderByInsideCategory',
    'recentPostDates', 'showExifFields', 'showIptcMapping', 'syncCharsRegex',
    'syncExcludeFolders', 'themesPath', 'useExifMapping', 'useIptcMapping',
];

/**
 * @return list<ReflectionProperty>
 */
function schemaIntegrityConfigProperties(): array
{
    $reflection = new ReflectionClass(CurrentConfig::class);

    return array_values(array_filter(
        $reflection->getProperties(),
        static fn (ReflectionProperty $property): bool => ! $property->isStatic()
            && ! in_array($property->getName(), SCHEMA_INTEGRITY_BACKING_FIELDS, true),
    ));
}

function schemaIntegrityHookBody(ReflectionMethod $hook): string
{
    $sourceLines = file((string) $hook->getFileName());
    if ($sourceLines === false) {
        throw new RuntimeException('Unable to read ' . $hook->getFileName());
    }
    $start = $hook->getStartLine();
    $end = $hook->getEndLine();
    if ($start === false || $end === false) {
        throw new RuntimeException('Unable to determine source lines for a property hook.');
    }

    // Unlike a regular method in this codebase's own style (signature line,
    // then a `{` on its own line), a hook's opening brace/arrow sits on the
    // signature line itself -- getStartLine() is that combined header line,
    // so the body starts one line later than a method-shaped slice would
    // put it.
    return trim(implode('', array_slice($sourceLines, $start, max(0, $end - $start - 1))));
}

test('every config property is publicly readable, and write access matches the documented private(set) list', function (): void {
    foreach (schemaIntegrityConfigProperties() as $property) {
        $name = $property->getName();
        expect($property->isPublic())
            ->toBeTrue("CurrentConfig::\${$name} must be publicly readable.");

        $shouldBePrivateSet = in_array($name, SCHEMA_INTEGRITY_PRIVATE_SET_LIST, true);
        expect($property->isPrivateSet())
            ->toBe(
                $shouldBePrivateSet,
                $shouldBePrivateSet
                            ? "CurrentConfig::\${$name} is on SCHEMA_INTEGRITY_PRIVATE_SET_LIST but isn't private(set)."
                            : "CurrentConfig::\${$name} is private(set) but isn't on SCHEMA_INTEGRITY_PRIVATE_SET_LIST.",
            );
    }
});

test('every public zero-arg static method is backed by a property of the same name', function (): void {
    $propertyNames = array_map(static fn (ReflectionProperty $p): string => $p->getName(), schemaIntegrityConfigProperties());

    $reflection = new ReflectionClass(CurrentConfig::class);
    $unlisted = [];
    foreach ($reflection->getMethods(ReflectionMethod::IS_STATIC) as $method) {
        if (! $method->isPublic() || $method->getNumberOfParameters() !== 0) {
            continue;
        }
        $name = $method->getName();
        if (in_array($name, $propertyNames, true)) {
            continue;
        }
        $unlisted[] = $name;
    }

    expect($unlisted)
        ->toBe([], 'Public static zero-arg method(s) on CurrentConfig have no matching property: ' . implode(', ', $unlisted));
});

test('hooks exist only on the documented properties, and each one writes its own state', function (): void {
    foreach (schemaIntegrityConfigProperties() as $property) {
        $name = $property->getName();
        $shouldHaveHooks = in_array($name, SCHEMA_INTEGRITY_HOOKED_LIST, true);
        expect($property->hasHooks())
            ->toBe(
                $shouldHaveHooks,
                $shouldHaveHooks
                            ? "CurrentConfig::\${$name} is on SCHEMA_INTEGRITY_HOOKED_LIST but has no hooks."
                            : "CurrentConfig::\${$name} has hooks but isn't on SCHEMA_INTEGRITY_HOOKED_LIST -- a plain property should have no room for hidden logic.",
            );

        if (! $shouldHaveHooks) {
            continue;
        }

        $hooks = $property->getHooks();
        if (! isset($hooks['set'])) {
            continue;
        }
        $body = schemaIntegrityHookBody($hooks['set']);
        // chmodValue/recentPostDates/defaultFiltersViews/filterPages write
        // their own private backing field (…Storage), not $this->{name}
        // directly -- see CurrentConfig's own docblocks for why (a fully
        // hooked property can't hold its own storage under its own name).
        $expectedTarget = in_array($name, ['chmodValue', 'recentPostDates', 'defaultFiltersViews', 'filterPages', 'orderBy', 'orderByInsideCategory'], true) ? "{$name}Storage" : $name;
        expect(str_contains($body, "\$this->{$expectedTarget}"))
            ->toBeTrue("CurrentConfig::\${$name}'s set hook doesn't write to its own state.");
    }
});

// config.value is JSON-typed (see ConfigService::encode()/hydrate()).
// This test confirms every scalar/array-typed property's own compiled-in
// default survives a real encode()-then-hydrate() round trip, catching the
// same class of bug two real call sites had: a value of the wrong PHP type
// for its target property (e.g. a real int for a ?string-typed property)
// is caught instead of silently coerced.
// encode() is a pure static (no CurrentConfig state involved); hydrate()
// is an instance method, so this test builds one throwaway CurrentConfig
// + ConfigService pair up front and invokes every property/hydrate() call
// against that same pair -- hydrate() never touches the DB (it only
// writes a CurrentConfig property via reflection), so no Kernel::boot() is
// needed. Skips: keys not in KEY_TO_PROPERTY (property has no DB-backed
// key, e.g. computed properties), non-scalar/array-typed properties (no
// single generic round-trip contract -- also how chmodValue/
// recentPostDates opt out, via hasDefaultValue()/type name respectively),
// and a null default (encode()/hydrate() both take a separate,
// already-covered branch for null).
test('every scalar/array-typed property survives a real encode()/hydrate() round trip', function (): void {
    $keyToProperty = new ReflectionClass(ConfigService::class)->getConstant('KEY_TO_PROPERTY');
    if (! is_array($keyToProperty)) {
        throw new RuntimeException('ConfigService::KEY_TO_PROPERTY is not an array.');
    }
    /** @var array<string, string> $keyToProperty */
    $propertyToKey = array_flip($keyToProperty);

    // Throwaway, no Kernel::boot() needed -- EntityManagerFactory::build()
    // only constructs objects, it never opens a real connection, and
    // hydrate() never reads $this->repo/$this->eventDispatcher, only
    // $this->currentConfig (see this test's own docblock above).
    $currentConfig = new CurrentConfig();
    $configService = new ConfigService(
        EntityManagerFactory::build()->getRepository(ConfigEntry::class),
        $currentConfig,
    );

    $encode = new ReflectionMethod(ConfigService::class, 'encode');
    $hydrate = new ReflectionMethod(ConfigService::class, 'hydrate');

    $originalValues = [];
    $checked = 0;
    try {
        foreach (schemaIntegrityConfigProperties() as $property) {
            $name = $property->getName();
            $key = $propertyToKey[$name] ?? null;
            if ($key === null) {
                continue;
            }

            $paramType = $property->getType();
            $paramTypeName = $paramType instanceof ReflectionNamedType ? $paramType->getName() : null;
            if (! in_array($paramTypeName, ['bool', 'int', 'float', 'string', 'array'], true)) {
                continue;
            }

            if (! $property->hasDefaultValue()) {
                continue;
            }
            $original = $property->getDefaultValue();
            if ($original === null) {
                continue;
            }

            $originalValues[$name] = $property->getValue($currentConfig);

            $encoded = $encode->invoke(null, $key, $original);
            expect($encoded)
                ->toBeString("ConfigService::encode() returned null for config key '{$key}''s non-null default.");

            $hydrate->invoke($configService, $key, $encoded);
            $roundTripped = $property->getValue($currentConfig);

            expect($roundTripped)
                ->toEqual($original, "CurrentConfig::\${$name} (config key '{$key}') did not round-trip through ConfigService::encode()/hydrate(): expected " . var_export($original, true) . ', got ' . var_export($roundTripped, true) . '.');
            $checked++;
        }

        expect($checked)
            ->toBeGreaterThan(0, 'The round-trip sweep skipped every property -- check its skip conditions.');
    } finally {
        foreach ($originalValues as $name => $value) {
            new ReflectionProperty(CurrentConfig::class, $name)->setValue($currentConfig, $value);
        }
    }
});
