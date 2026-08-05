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
 * MailService takes an optional constructor param (lazily defaulting to
 * the real UserRepository when unit tests don't need to substitute a fixed
 * address), and the unit tests pass an anonymous fake instead of
 * shadowing a global function. MailService's OTHER constructor params
 * became required in Phase 11 sub-phase 11E (it no longer has a no-arg
 * constructor), but this one stays optional-with-lazy-default on its own
 * merits -- a genuine test seam, not a too-many-call-sites exception.
 */
interface WebmasterMailProviderInterface
{
    /**
     * Returns the webmaster mail address depending on \Piwigo\Config\CurrentConfig::webmasterId().
     */
    public function getWebmasterMailAddress(): string;
}
