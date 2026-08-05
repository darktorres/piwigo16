<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Piwigo\Core\Env;
use Piwigo\Core\Lang;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Session\SessionService;

/**
 * Personal API key (pkid-...) lifecycle: create/revoke/edit/list, plus the
 * "connected via identification.php" guard WS methods use to gate API key
 * self-management, and the expiration-notice email.
 *
 * P23 batch 8d: ported from include/functions_user.inc.php's
 * create_api_key()/revoke_api_key()/edit_api_key()/get_api_key()/
 * get_available_api_key()/connected_with_pwg_ui()/
 * notification_api_key_expiration(). Constructor-injects MailerInterface
 * (same reason as UserService/CommentService, P23 batch 8c finding 8 --
 * Piwigo\Mail\MailService is L3Presentation, this class is
 * L2aCoreDomain).
 */
final readonly class ApiKeyService
{
    public function __construct(
        private Lang $lang,
        private MailerInterface $mailer,
        private ApiKeyRepository $repo,
        private PasswordService $passwordService,
        private UrlServiceInterface $urlService,
        private SessionService $sessionService,
        private readonly \Piwigo\Config\CurrentConfig $currentConfig,
    ) {}

    /**
     * @since 16
     * @return array{auth_key: string, apikey_secret: string, apikey_name: string, user_id: int, created_on: string, duration: int, key_type: string, expired_on: string}
     */
    public function create(int $userId, int $duration, string $keyName): array
    {
        $key_id = 'pkid-' . date('Ymd') . '-' . $this->sessionService->generateKey(20);
        $key_secret = $this->sessionService->generateKey(40);

        $now = Env::now();
        $expiration = (clone $now)->modify('+' . ($duration * 60 * 60 * 24) . ' seconds')->format('Y-m-d H:i:s');

        $key = [
            'auth_key' => $key_id,
            'apikey_secret' => $this->passwordService->hash($key_secret),
            'apikey_name' => $keyName,
            'user_id' => $userId,
            'created_on' => $now->format('Y-m-d H:i:s'),
            'duration' => $duration,
            'key_type' => 'api_key',
            'expired_on' => $expiration,
        ];

        $this->repo->insert($key);

        $key['apikey_secret'] = $key_secret;
        return $key;
    }

    /**
     * @since 16
     */
    public function revoke(int $userId, string $pkid): string|true
    {
        if ($this->repo->countByAuthKeyAndUser($pkid, $userId) === 0) {
            return $this->lang->t('API Key not found');
        }

        $this->repo->revoke($pkid, $userId, Env::now());

        return true;
    }

    /**
     * @since 16
     */
    public function edit(int $userId, string $pkid, ?string $apiName): string|true
    {
        if ($this->repo->countByAuthKeyAndUser($pkid, $userId) === 0) {
            return $this->lang->t('API Key not found');
        }

        $this->repo->updateName($pkid, $userId, $apiName);

        return true;
    }

    /**
     * @since 16
     * @return false|list<array{auth_key: string, apikey_secret: string, apikey_name: string, created_on: string, duration: ?int, expired_on: string, revoked_on: ?string, last_used_on: ?string, last_notified_on: ?string, created_on_format: string, expired_on_format: string, last_used_on_since: string, is_expired: bool, expiration: string, expired_on_since: string, revoked_on_since: ?string, revoked_on_message: ?string}>
     */
    public function get(int $userId): false|array
    {
        $api_keys = $this->repo->findByUser($userId);
        if (! (bool) $api_keys) {
            return false;
        }

        $now = Env::now()->format('Y-m-d H:i:s');

        $results = [];
        foreach ($api_keys as $api_key_row) {
            $api_key = $api_key_row->toArray();
            $api_key['apikey_secret'] = str_repeat('*', 40);
            unset($api_key['auth_key_id'], $api_key['user_id'], $api_key['key_type']);

            $api_key['apikey_name'] = stripslashes($api_key_row->apikeyName ?? '');

            // created_on/expired_on are real NOT NULL columns -- Projection\
            // ApiKey::fromRow() already guarantees a string, no assert() needed.
            $created_on = $api_key_row->createdOn;
            $api_key['created_on_format'] = \Piwigo\Core\DateHelper::formatDate($created_on, ['day', 'month', 'year']);

            $expired_on_raw = $api_key_row->expiredOn;
            $api_key['expired_on_format'] = \Piwigo\Core\DateHelper::formatDate($expired_on_raw, ['day', 'month', 'year']);

            $revoked_on = $api_key_row->revokedOn;

            $last_used_on = $api_key_row->lastUsedOn;
            $api_key['last_used_on_since'] =
              $last_used_on !== null
              ? \Piwigo\Core\DateHelper::timeSince($last_used_on, 'day')
              : $this->lang->t('Never');

            $expired_on = \Piwigo\Core\DateHelper::str2DateTime($expired_on_raw);
            $now = \Piwigo\Core\DateHelper::str2DateTime($now);
            if ($expired_on === false || $now === false) {
                throw new \Exception('ApiKeyService::get(): str2DateTime() failed on a DB-stored date');
            }

            $api_key['is_expired'] = $expired_on < $now;
            if ($api_key['is_expired']) {
                $api_key['expiration'] = $this->lang->t('Expired');
            } else {
                $diff = \Piwigo\Core\DateHelper::dateDiff($now, $expired_on);
                if ($diff->days > 0) {
                    $api_key['expiration'] = $this->lang->t('%d days', $diff->days);
                } elseif ($diff->h > 0) {
                    $api_key['expiration'] = $this->lang->t('%d hours', $diff->h);
                } else {
                    $api_key['expiration'] = $this->lang->t('%d minutes', $diff->i);
                }
            }

            $api_key['expired_on_since'] = \Piwigo\Core\DateHelper::timeSince($expired_on_raw, 'day');

            $api_key['revoked_on_since'] =
              (bool) $revoked_on
              ? \Piwigo\Core\DateHelper::timeSince($revoked_on, 'day')
              : null;

            $api_key['revoked_on_message'] =
              (bool) $revoked_on
              ? $this->lang->t('This API key was manually revoked on %s', \Piwigo\Core\DateHelper::formatDate($revoked_on, ['day', 'month', 'year']))
              : null;

            $results[] = $api_key;
        }

        return $results;
    }

    /**
     * @since 16
     * @return list<array{auth_key: string, apikey_secret: string, apikey_name: string, created_on: string, duration: ?int, expired_on: string, revoked_on: ?string, last_used_on: ?string, last_notified_on: ?string, created_on_format: string, expired_on_format: string, last_used_on_since: string, is_expired: bool, expiration: string, expired_on_since: string, revoked_on_since: ?string, revoked_on_message: ?string}>|false
     */
    public function getAvailable(int $userId): array|false
    {
        $api_keys = $this->get($userId);

        if (! (bool) $api_keys) {
            return false;
        }

        $available = [];
        foreach ($api_keys as $api_key) {
            if (! $api_key['is_expired'] && in_array($api_key['revoked_on'], [null, false, 0, '0', '', []], true)) {
                $available[] = $api_key;
            }
        }

        return count($available) > 0 ? $available : false;
    }

    /**
     * Is connected with pwg_ui (identification.php).
     *
     * @since 16
     */
    public function connectedWithPwgUi(): bool
    {
        // You can manage your api key only if you are connected via identification.php
        return isset($_SESSION['connected_with']) && $_SESSION['connected_with'] === 'pwg_ui';
    }

    /**
     * Notify a user when their api key is about to expire.
     *
     * @since 16
     */
    public function notifyExpiration(string $username, string $email, int $daysLeft): bool
    {

        $days_left_str = $daysLeft <= 1 ?
          $this->lang->t('Your API key will expire in %d day.', $daysLeft)
          : $this->lang->t('Your API key will expire in %d days.', $daysLeft);

        $message = '<p style="margin: 20px 0">' . $this->lang->t('Hello %s,', $username) . '</p>';
        $message .= '<p style="margin: 20px 0">' . $days_left_str . '</p>';
        $message .= '<p style="margin: 20px 0">' . $this->lang->t('To continue using the API, please renew your key before it expires.') . '</p>';
        $message .= '<p style="margin: 20px 0">' . $this->lang->t('You can manage your API keys in your <a href="%s">account settings.</a>', $this->urlService->getAbsoluteRootUrl() . 'profile.php') . '</p>';

        $gallery_title = $this->currentConfig->galleryTitle();

        return $this->mailer->mail(
            $email,
            [
                'subject' => '[' . $gallery_title . '] ' . $this->lang->t('Your API key will expire soon'),
                'content' => $message,
                'content_format' => 'text/html',
            ]
        );
    }
}
