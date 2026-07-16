<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Piwigo\Core\MailerInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
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
final class ApiKeyService
{
    public function __construct(
        private readonly MailerInterface $mailer,
    ) {}

    /**
     * @since 16
     * @return array<string, mixed> auth_key / apikey_secret / apikey_name /
     *   user_id / created_on / duration / expired_on / key_type
     */
    public function create(int $userId, ?int $duration, string $keyName): array
    {
        $key_id = 'pkid-' . date('Ymd') . '-' . SessionService::get()->generateKey(20);
        $key_secret = SessionService::get()->generateKey(40);

        $row = pwg_db_fetch_row(pwg_query('SELECT NOW();'));
        assert($row !== null);
        [$dbnow] = $row;

        $key = [
            'auth_key' => $key_id,
            'apikey_secret' => new PasswordService(new PasswordRepository(DbConnection::build()))->hash($key_secret),
            'apikey_name' => $keyName,
            'user_id' => $userId,
            'created_on' => $dbnow,
            'key_type' => 'api_key',
        ];

        $expiration = null;
        if (! empty($duration)) {
            $query = '
SELECT
  ADDDATE(NOW(), INTERVAL ' . ($duration * 60 * 60 * 24) . ' SECOND)
;';
            $row = pwg_db_fetch_row(pwg_query($query));
            assert($row !== null);
            [$expiration] = $row;
            $key['duration'] = $duration;
        }
        $key['expired_on'] = $expiration;

        single_insert(Tables::userAuthKeys(), $key);

        $key['apikey_secret'] = $key_secret;
        return $key;
    }

    /**
     * @since 16
     * @return string|true
     */
    public function revoke(int $userId, string $pkid)
    {
        $query = '
SELECT
  COUNT(*),
  NOW()
  FROM `' . Tables::userAuthKeys() . '`
  WHERE auth_key = "' . $pkid . '"
  AND user_id = ' . $userId . '
;';

        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$key, $now] = $row;
        if ($key == 0) {
            return l10n('API Key not found');
        }

        single_update(
            Tables::userAuthKeys(),
            [
                'revoked_on' => $now,
            ],
            [
                'auth_key' => $pkid,
                'user_id' => $userId,
            ]
        );

        return true;
    }

    /**
     * @since 16
     * @return string|true
     */
    public function edit(int $userId, string $pkid, ?string $apiName)
    {
        $query = '
SELECT
  COUNT(*)
  FROM `' . Tables::userAuthKeys() . '`
  WHERE auth_key = "' . $pkid . '"
  AND user_id = ' . $userId . '
;';

        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$key] = $row;
        if ($key == 0) {
            return l10n('API Key not found');
        }

        single_update(
            Tables::userAuthKeys(),
            [
                'apikey_name' => $apiName,
            ],
            [
                'auth_key' => $pkid,
                'user_id' => $userId,
            ]
        );

        return true;
    }

    /**
     * @since 16
     * @return false|array<int, array<string, mixed>>
     */
    public function get(int $userId): false|array
    {
        $query = '
SELECT *
  FROM `' . Tables::userAuthKeys() . '`
  WHERE user_id = ' . $userId . '
  AND key_type = "api_key"
;';

        // query2array() with no key_name/value_name always returns a
        // sequential list (array<int, mixed>) -- see qsearch_get_images()'s
        // comment in the (now-deleted) functions_search.inc.php for the
        // general pattern; it's already a list, so no array_values()
        // wrapper is needed.
        $api_keys = query2array($query);
        if (! (bool) $api_keys) {
            return false;
        }

        $query = '
SELECT
  NOW()
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$now] = $row;
        assert($now !== null);

        foreach ($api_keys as $i => $api_key) {
            $api_key['apikey_secret'] = str_repeat('*', 40);
            unset($api_key['auth_key_id'], $api_key['user_id'], $api_key['key_type']);

            $api_key['apikey_name'] = stripslashes((string) $api_key['apikey_name']);

            // extracted before any bool value is assigned into $api_key below
            // (e.g. 'is_expired'), which would otherwise widen every sibling
            // key's inferred type for the rest of this loop iteration
            $created_on = $api_key['created_on'];
            assert(is_string($created_on));
            $api_key['created_on_format'] = format_date($created_on, ['day', 'month', 'year']);

            $expired_on_raw = $api_key['expired_on'];
            assert(is_string($expired_on_raw));
            $api_key['expired_on_format'] = format_date($expired_on_raw, ['day', 'month', 'year']);

            // also extracted early, for the same reason -- read again below,
            // after 'is_expired' has already widened $api_key's value type
            $revoked_on = $api_key['revoked_on'];

            $api_key['last_used_on_since'] =
              (bool) $api_key['last_used_on']
              ? time_since($api_key['last_used_on'], 'day')
              : l10n('Never');

            $expired_on = str2DateTime($expired_on_raw);
            $now = str2DateTime($now);
            if ($expired_on === false || $now === false) {
                throw new \Exception('ApiKeyService::get(): str2DateTime() failed on a DB-stored date');
            }

            $api_key['is_expired'] = $expired_on < $now;
            if ($api_key['is_expired']) {
                $api_key['expiration'] = l10n('Expired');
            } else {
                $diff = dateDiff($now, $expired_on);
                if ($diff->days > 0) {
                    $api_key['expiration'] = l10n('%d days', $diff->days);
                } elseif ($diff->h > 0) {
                    $api_key['expiration'] = l10n('%d hours', $diff->h);
                } else {
                    $api_key['expiration'] = l10n('%d minutes', $diff->i);
                }
            }

            $api_key['expired_on_since'] = time_since($expired_on_raw, 'day');

            $api_key['revoked_on_since'] =
              (bool) $revoked_on
              ? time_since($revoked_on, 'day')
              : null;

            $api_key['revoked_on_message'] =
              (bool) $revoked_on
              ? l10n('This API key was manually revoked on %s', format_date($revoked_on, ['day', 'month', 'year']))
              : null;

            $api_keys[$i] = $api_key;
        }

        return $api_keys;
    }

    /**
     * @since 16
     * @return array<int, array<string, mixed>>|false
     */
    public function getAvailable(int $userId): array|false
    {
        $api_keys = $this->get($userId);

        if (! (bool) $api_keys) {
            return false;
        }

        $available = [];
        foreach ($api_keys as $api_key) {
            if (! (bool) $api_key['is_expired'] && empty($api_key['revoked_on'])) {
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
        /** @var array<string, mixed> $conf */
        global $conf;

        $days_left_str = $daysLeft <= 1 ?
          l10n('Your API key will expire in %d day.', $daysLeft)
          : l10n('Your API key will expire in %d days.', $daysLeft);

        $message = '<p style="margin: 20px 0">' . l10n('Hello %s,', $username) . '</p>';
        $message .= '<p style="margin: 20px 0">' . $days_left_str . '</p>';
        $message .= '<p style="margin: 20px 0">' . l10n('To continue using the API, please renew your key before it expires.') . '</p>';
        $message .= '<p style="margin: 20px 0">' . l10n('You can manage your API keys in your <a href="%s">account settings.</a>', get_absolute_root_url() . 'profile.php') . '</p>';

        $gallery_title = $conf['gallery_title'];
        $gallery_title = is_string($gallery_title) ? $gallery_title : '';

        return $this->mailer->mail(
            $email,
            [
                'subject' => '[' . $gallery_title . '] ' . l10n('Your API key will expire soon'),
                'content' => $message,
                'content_format' => 'text/html',
            ]
        );
    }
}
