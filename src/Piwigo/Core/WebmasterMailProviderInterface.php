<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * P23 batch 8f-4: the legacy get_webmaster_mail_address() free function
 * (include/functions.inc.php, now deleted) delegated to
 * `Piwigo\Users\UserRepository::getWebmasterMailAddress()`. Its 2 remaining
 * bare call sites (both inside `Piwigo\Mail\MailService`) were kept
 * unqualified purely so tests/Unit/Mail/MailServiceTest.php and
 * tests/Unit/Job/SendNotificationEmailHandlerTest.php could substitute a
 * fixed address without a DB connection via function stubs -- a test-seam
 * concern, not a deptrac one (Mail is L3Presentation, Users is
 * L2aCoreDomain, a legal downward dependency). This interface replaces
 * that stub mechanism with real DI: `UserRepository implements` it (bound
 * in config/container.php for consistency with the other Core interfaces),
 * MailService takes an optional constructor instance (lazily defaulting to
 * the real UserRepository -- keeps `new MailService()` valid with no args,
 * still relied on by the 2 remaining bare production construction sites
 * plus every unit test's own direct construction, singleton/service-
 * locator elimination campaign Phase 6), and the unit tests pass an
 * anonymous fake instead of shadowing a global function.
 */
interface WebmasterMailProviderInterface
{
    /**
     * Returns the webmaster mail address depending on \Piwigo\Config\CurrentConfig::webmasterId().
     */
    public function getWebmasterMailAddress(): string;
}
