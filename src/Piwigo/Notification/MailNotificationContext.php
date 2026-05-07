<?php

declare(strict_types=1);

namespace Piwigo\Notification;

use Piwigo\Config\Config;
use Piwigo\Core\StringUtil;
use Piwigo\Template\Template;

/**
 * Typed context for a notification-by-mail dispatch cycle, replacing the
 * $env_nbm global array.
 *
 * Lifecycle: MailNotificationContext::init() at the top of
 * functions_notification_by_mail.inc.php replaces the $env_nbm = [...] literal.
 * All functions in that file and admin/notification_by_mail.php call current()
 * to access and mutate state.
 */
final class MailNotificationContext
{
    private static ?self $instance = null;

    public float $startTime;
    public float $sendmailTimeout;
    public bool $isSendmailTimeout = false;

    // Set during begin_users_env_nbm
    /** @var array<string, mixed> */
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
        $this->startTime = StringUtil::get()->getMoment();
        $timeout = intval(ini_get('max_execution_time')) * Config::nbmMaxTreatmentTimeoutPercent();
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
