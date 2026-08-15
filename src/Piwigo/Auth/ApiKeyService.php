<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Exception;
use Piwigo\Auth\Projection\ApiKeyCreated;
use Piwigo\Auth\Projection\ApiKeySummary;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\DateHelper;
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
 * Constructor-injects `MailerInterface` rather than
 * `Piwigo\Mail\MailService` directly: `MailService` is `L3Presentation`
 * and this class is `L2aCoreDomain`, which can't depend on it.
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
        private readonly CurrentConfig $currentConfig,
    ) {}

    public function create(int $userId, int $duration, string $keyName): ApiKeyCreated
    {
        $key_id = 'pkid-' . date('Ymd') . '-' . $this->sessionService->generateKey(20);
        $key_secret = $this->sessionService->generateKey(40);

        $now = Env::now();
        $expiration = (clone $now)->modify('+' . ($duration * 60 * 60 * 24) . ' seconds')->format('Y-m-d H:i:s');
        $createdOn = $now->format('Y-m-d H:i:s');

        $key = [
            'auth_key' => $key_id,
            'apikey_secret' => $this->passwordService->hash($key_secret),
            'apikey_name' => $keyName,
            'user_id' => $userId,
            'created_on' => $createdOn,
            'duration' => $duration,
            'key_type' => 'api_key',
            'expired_on' => $expiration,
        ];

        $this->repo->insert($key);

        return new ApiKeyCreated(
            authKey: $key_id,
            apikeySecret: $key_secret,
            apikeyName: $keyName,
            userId: $userId,
            createdOn: $createdOn,
            duration: $duration,
            keyType: 'api_key',
            expiredOn: $expiration,
        );
    }

    public function revoke(int $userId, string $pkid): string|true
    {
        if ($this->repo->countByAuthKeyAndUser($pkid, $userId) === 0) {
            return $this->lang->t('API Key not found');
        }

        $this->repo->revoke($pkid, $userId, Env::now());

        return true;
    }

    public function edit(int $userId, string $pkid, ?string $apiName): string|true
    {
        if ($this->repo->countByAuthKeyAndUser($pkid, $userId) === 0) {
            return $this->lang->t('API Key not found');
        }

        $this->repo->updateName($pkid, $userId, $apiName);

        return true;
    }

    /**
     * @return false|list<ApiKeySummary>
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
            $apikey_name = $api_key_row->apikeyName ?? '';

            // created_on/expired_on are real NOT NULL columns -- Projection\
            // ApiKey::fromRow() already guarantees a string, no assert() needed.
            $created_on = $api_key_row->createdOn;
            $created_on_format = DateHelper::formatDate($created_on, ['day', 'month', 'year']);

            $expired_on_raw = $api_key_row->expiredOn;
            $expired_on_format = DateHelper::formatDate($expired_on_raw, ['day', 'month', 'year']);

            $revoked_on = $api_key_row->revokedOn;

            $last_used_on = $api_key_row->lastUsedOn;
            $last_used_on_since =
              $last_used_on !== null
              ? DateHelper::timeSince($last_used_on, 'day')
              : $this->lang->t('Never');

            $expired_on = DateHelper::str2DateTime($expired_on_raw);
            $now = DateHelper::str2DateTime($now);
            if ($expired_on === false || $now === false) {
                throw new Exception('ApiKeyService::get(): str2DateTime() failed on a DB-stored date');
            }

            $is_expired = $expired_on < $now;
            if ($is_expired) {
                $expiration = $this->lang->t('Expired');
            } else {
                $diff = DateHelper::dateDiff($now, $expired_on);
                if ($diff->days > 0) {
                    $expiration = $this->lang->t('%d days', $diff->days);
                } elseif ($diff->h > 0) {
                    $expiration = $this->lang->t('%d hours', $diff->h);
                } else {
                    $expiration = $this->lang->t('%d minutes', $diff->i);
                }
            }

            $expired_on_since = DateHelper::timeSince($expired_on_raw, 'day');

            $revoked_on_since =
              (bool) $revoked_on
              ? DateHelper::timeSince($revoked_on, 'day')
              : null;

            $revoked_on_message =
              (bool) $revoked_on
              ? $this->lang->t('This API key was manually revoked on %s', DateHelper::formatDate($revoked_on, ['day', 'month', 'year']))
              : null;

            $results[] = new ApiKeySummary(
                authKey: $api_key_row->authKey,
                apikeySecret: str_repeat('*', 40),
                apikeyName: $apikey_name,
                createdOn: $created_on,
                duration: $api_key_row->duration,
                expiredOn: $expired_on_raw,
                revokedOn: $revoked_on,
                lastUsedOn: $last_used_on,
                lastNotifiedOn: $api_key_row->lastNotifiedOn,
                createdOnFormat: $created_on_format,
                expiredOnFormat: $expired_on_format,
                lastUsedOnSince: $last_used_on_since,
                isExpired: $is_expired,
                expiration: $expiration,
                expiredOnSince: $expired_on_since,
                revokedOnSince: $revoked_on_since,
                revokedOnMessage: $revoked_on_message,
            );
        }

        return $results;
    }

    /**
     * @return list<ApiKeySummary>|false
     */
    public function getAvailable(int $userId): array|false
    {
        $api_keys = $this->get($userId);

        if (! (bool) $api_keys) {
            return false;
        }

        $available = [];
        foreach ($api_keys as $api_key) {
            if (! $api_key->isExpired && in_array($api_key->revokedOn, [null, '0', ''], true)) {
                $available[] = $api_key;
            }
        }

        return count($available) > 0 ? $available : false;
    }

    /**
     * Is connected with pwg_ui (identification.php).
     */
    public function connectedWithPwgUi(): bool
    {
        // You can manage your api key only if you are connected via identification.php
        return isset($_SESSION['connected_with']) && $_SESSION['connected_with'] === 'pwg_ui';
    }

    /**
     * Notify a user when their api key is about to expire.
     */
    public function notifyExpiration(Username $username, Email $email, int $daysLeft): bool
    {

        $days_left_str = $daysLeft <= 1 ?
          $this->lang->t('Your API key will expire in %d day.', $daysLeft)
          : $this->lang->t('Your API key will expire in %d days.', $daysLeft);

        $message = '<p style="margin: 20px 0">' . $this->lang->t('Hello %s,', $username->value) . '</p>';
        $message .= '<p style="margin: 20px 0">' . $days_left_str . '</p>';
        $message .= '<p style="margin: 20px 0">' . $this->lang->t('To continue using the API, please renew your key before it expires.') . '</p>';
        $message .= '<p style="margin: 20px 0">' . $this->lang->t('You can manage your API keys in your <a href="%s">account settings.</a>', $this->urlService->getAbsoluteRootUrl() . 'profile.php') . '</p>';

        $gallery_title = $this->currentConfig->galleryTitle;

        return $this->mailer->mail(
            $email->value,
            [
                'subject' => '[' . $gallery_title . '] ' . $this->lang->t('Your API key will expire soon'),
                'content' => $message,
                'content_format' => 'text/html',
            ]
        );
    }
}
