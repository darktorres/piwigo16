<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Users;

use Piwigo\Ws\WsParams;

/**
 * `pwg.users.setInfo` input DTO. `userIds` is mandatory
 * (`ParamDefinition::required('user_id', ..., FORCE_ARRAY)`); every other
 * field is `optionalFlag()`-registered -- null means genuinely absent
 * from the request, matching `UserService::checkAndSaveUserInfos()`'s own
 * `UserInfoUpdateInput` (which this maps onto 1:1 in `SetInfoHandler`).
 */
final readonly class SetInfoParams implements WsParams
{
    /**
     * @param list<int> $userIds
     * @param list<int>|null $groupIds
     */
    public function __construct(
        public string $pwgToken,
        public array $userIds,
        public ?string $username = null,
        public ?string $password = null,
        public ?string $email = null,
        public ?string $status = null,
        public ?int $level = null,
        public ?string $language = null,
        public ?string $theme = null,
        public ?array $groupIds = null,
        public ?int $nbImagePage = null,
        public ?int $recentPeriod = null,
        public ?bool $expand = null,
        public ?bool $showNbComments = null,
        public ?bool $showNbHits = null,
        public ?bool $enabledHigh = null,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $pwgToken = $raw['pwg_token'] ?? null;

        $userIds = [];
        if (is_array($raw['user_id'] ?? null)) {
            foreach ($raw['user_id'] as $rawUserId) {
                if (is_int($rawUserId) || (is_string($rawUserId) && is_numeric($rawUserId))) {
                    $userIds[] = (int) $rawUserId;
                }
            }
        }

        $username = $raw['username'] ?? null;
        $password = $raw['password'] ?? null;
        $email = $raw['email'] ?? null;
        $status = $raw['status'] ?? null;
        $level = $raw['level'] ?? null;
        $language = $raw['language'] ?? null;
        $theme = $raw['theme'] ?? null;

        $groupIds = null;
        if (is_array($raw['group_id'] ?? null)) {
            $groupIds = [];
            foreach ($raw['group_id'] as $rawGroupId) {
                if (is_int($rawGroupId) || (is_string($rawGroupId) && is_numeric($rawGroupId))) {
                    $groupIds[] = (int) $rawGroupId;
                }
            }
        }

        $nbImagePage = $raw['nb_image_page'] ?? null;
        $recentPeriod = $raw['recent_period'] ?? null;
        $expand = $raw['expand'] ?? null;
        $showNbComments = $raw['show_nb_comments'] ?? null;
        $showNbHits = $raw['show_nb_hits'] ?? null;
        $enabledHigh = $raw['enabled_high'] ?? null;

        return new self(
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
            userIds: $userIds,
            username: is_string($username) ? $username : null,
            password: is_string($password) ? $password : null,
            email: is_string($email) ? $email : null,
            status: is_string($status) ? $status : null,
            level: is_int($level) ? $level : null,
            language: is_string($language) ? $language : null,
            theme: is_string($theme) ? $theme : null,
            groupIds: $groupIds,
            nbImagePage: is_int($nbImagePage) ? $nbImagePage : null,
            recentPeriod: is_int($recentPeriod) ? $recentPeriod : null,
            expand: is_bool($expand) ? $expand : null,
            showNbComments: is_bool($showNbComments) ? $showNbComments : null,
            showNbHits: is_bool($showNbHits) ? $showNbHits : null,
            enabledHigh: is_bool($enabledHigh) ? $enabledHigh : null,
        );
    }
}
