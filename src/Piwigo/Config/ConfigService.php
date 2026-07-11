<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * Deliberately minimal this phase. The reference implementation's
 * ConfigService is 100% DB-gated: loadConfFromDb(), confUpdateParam(),
 * confDeleteParam(), pwgIsDbconfWriteable() all need
 * Piwigo\Db\DbConnection + a ConfigRepository -- Doctrine DBAL, which is
 * explicitly P14's own deliverable ("DB layer + Doctrine ORM"), not built
 * yet. Every other reference ConfigService method
 * (showMobileAppBannerInGallery, standardPagesSelectedLogo, countOrphans,
 * pdfJpgQuality, ...) backs admin UI / domain features that don't exist
 * until P17-23/P29. Same precedent as P11's whole-file Messenger deferral.
 *
 * confGetParam() is the one piece that's genuinely DB-independent (a pure
 * Config::all() lookup for dynamic, non-SCHEMA keys) -- built for real.
 * The rest lands in P14 once ConfigRepository/DbConnection exist.
 */
final class ConfigService
{
    /**
     * Generic dynamic-key reader for conf rows that legitimately lack a
     * SCHEMA entry. For SCHEMA-backed keys, prefer the typed Config::xxx()
     * accessors.
     *
     * @param array<mixed>|string|int|float|bool|null $defaultValue
     */
    public function confGetParam(string $param, array|string|int|float|bool|null $defaultValue = null): mixed
    {
        return Config::all()[$param] ?? $defaultValue;
    }
}
