<?php

declare(strict_types=1);

namespace Piwigo\Notification;

use Piwigo\Config\Config;
use Piwigo\Core\Kernel;
use Piwigo\Core\StringUtil;
use Piwigo\Template\Template;

/**
 * Typed context for a notification-by-mail dispatch cycle, replacing the
 * legacy $env_nbm global array. Callers — primarily
 * `NotificationAdminService` and `MailService` — read and mutate state via
 * `MailNotificationContext::current()` after a one-time `::init()` at the
 * top of the dispatch cycle.
 */
final class MailNotificationContext
{
    private static ?self $instance = null;

    public float $startTime;
    public float $sendmailTimeout;
    public bool $isSendmailTimeout = false;

    // Set during begin_users_env_nbm
    /** @var array<mixed> */
    public array $saveUser = [];
    public bool $isToSendMail = false;
    public string $emailFormat = '';
    public string $sendAsName = '';
    public string $sendAsMailAddress = '';
    public string $sendAsMailFormated = '';
    public int $errorOnMailCount = 0;
    public int $sentMailCount = 0;
    public string $msgInfo = '';
    public string $msgError = '';

    // Set during set_user_on_env_nbm, cleared by unset_user_on_env_nbm
    public ?Template $mailTemplate = null;

    private function __construct()
    {
        $this->startTime = Kernel::service(StringUtil::class)->getMoment();
        $timeout = (float) intval(ini_get('max_execution_time')) * Config::nbmMaxTreatmentTimeoutPercent();
        $this->sendmailTimeout = $timeout > 0 ? $timeout : Config::nbmTreatmentTimeoutDefault();
    }

    public static function init(): self
    {
        self::$instance = new self();
        return self::$instance;
    }

    public static function current(): self
    {
        if (self::$instance === null) {
            throw new \LogicException('MailNotificationContext not initialised — call MailNotificationContext::init() first.');
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
