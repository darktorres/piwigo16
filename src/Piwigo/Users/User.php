<?php

declare(strict_types=1);

namespace Piwigo\Users;

use InvalidArgumentException;
use Piwigo\Common\ValueObject\UserId;

/**
 * Typed user entity. `rawAttributes` carries the full legacy `$user` array
 * for P17-23 reads during migration -- retirement gates per the plan doc:
 * P18 records a baseline read-count, P26's arch test enforces zero reads
 * outside `Users/`, P29 deletes the property entirely.
 *
 * `forbiddenCategories`/`level`/`preferences` were promoted from
 * `rawAttributes` to named properties in Legacy Coupling Retirement Track A
 * batch A3 -- a real key-frequency audit of every `global $user; ...
 * $user['key']` read across `src/Piwigo/` found these used 13/9/13 times
 * respectively (vs. id/username/etc.'s existing 124/21/10/43-range
 * frequencies), clearing the same "common enough to warrant a named
 * property" bar those were promoted under. Lower-frequency keys
 * (`recent_period`, `nb_available_tags`, etc., all under 7 reads combined)
 * stay in `rawAttributes`. `cacheUpdateTime` was promoted alongside these 3
 * in the same batch, then demoted back out and deleted entirely in
 * gap-closure Stage 4g (docs/plan/gap-closure-p0-p23.md) once its own
 * `user_cache.cache_update_time`-keyed invalidation pattern was replaced
 * with `CachePools`-backed TTLs (Stage 4a) and no reader remained.
 */
final readonly class User
{
    /**
     * $internalStatus has no real writer today (User::fromUserArray()
     * never populates it from `$row['internal_status']`, so it's always
     * the constructor default `[]`), but it IS read externally --
     * Bootstrap\RequestBootstrap.php reads
     * `CurrentUser::get()->internalStatus['guest_must_be_guest']` to
     * decide whether to show a header warning, so this isn't a fully
     * reserved slot the way Core\PageState::$bodyData is.
     * $preferences' values are genuinely arbitrary by design -- see
     * PreferencesService::updateParam()'s own docblock (real callers pass
     * bool/int/array, not just strings).
     *
     * @param array<string, mixed> $internalStatus
     * @param array<string, mixed> $preferences
     * @param array<string, mixed> $rawAttributes
     */
    public function __construct(
        public UserId $id,
        public string $username,
        public string $email,
        public string $language,
        public string $theme,
        public UserStatus $status,
        public bool $enabledHigh,
        public string $forbiddenCategories = '',
        public int $level = 0,
        public array $preferences = [],
        public array $internalStatus = [],
        public array $rawAttributes = [],
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromUserArray(array $row): self
    {
        $status = $row['status'] ?? null;
        $preferences = $row['preferences'] ?? null;

        $id = UserId::tryFrom($row['id'] ?? null);
        if ($id === null) {
            throw new InvalidArgumentException('User::fromUserArray(): missing or invalid id');
        }

        return new self(
            id: $id,
            username: is_string($row['username'] ?? null) ? $row['username'] : '',
            email: is_string($row['email'] ?? null) ? $row['email'] : '',
            language: is_string($row['language'] ?? null) ? $row['language'] : '',
            theme: is_string($row['theme'] ?? null) ? $row['theme'] : '',
            status: is_string($status) ? (UserStatus::tryFrom($status) ?? UserStatus::Guest) : UserStatus::Guest,
            enabledHigh: (bool) ($row['enabled_high'] ?? false),
            forbiddenCategories: is_string($row['forbidden_categories'] ?? null) ? $row['forbidden_categories'] : '',
            level: is_numeric($row['level'] ?? null) ? (int) $row['level'] : 0,
            preferences: is_array($preferences) ? array_filter($preferences, is_string(...), ARRAY_FILTER_USE_KEY) : [],
            rawAttributes: $row,
        );
    }

    /**
     * The inverse of fromUserArray() -- rawAttributes already carries the
     * full legacy array and every wither above keeps it in sync with this
     * object's own named properties, but this overlays them explicitly
     * anyway so the guarantee holds regardless of construction path (e.g.
     * a direct `new self(...)` call that didn't go through a wither).
     *
     * @return array<string, mixed>
     */
    public function toUserArray(): array
    {
        return array_merge($this->rawAttributes, [
            'id' => $this->id->value,
            'username' => $this->username,
            'email' => $this->email,
            'language' => $this->language,
            'theme' => $this->theme,
            'status' => $this->status->value,
            'enabled_high' => $this->enabledHigh,
            'forbidden_categories' => $this->forbiddenCategories,
            'level' => $this->level,
            'preferences' => $this->preferences,
        ]);
    }

    public function withLanguage(string $language): self
    {
        $rawAttributes = $this->rawAttributes;
        $rawAttributes['language'] = $language;

        return new self(
            id: $this->id,
            username: $this->username,
            email: $this->email,
            language: $language,
            theme: $this->theme,
            status: $this->status,
            enabledHigh: $this->enabledHigh,
            forbiddenCategories: $this->forbiddenCategories,
            level: $this->level,
            preferences: $this->preferences,
            internalStatus: $this->internalStatus,
            rawAttributes: $rawAttributes,
        );
    }

    public function withUsername(string $username): self
    {
        $rawAttributes = $this->rawAttributes;
        $rawAttributes['username'] = $username;

        return new self(
            id: $this->id,
            username: $username,
            email: $this->email,
            language: $this->language,
            theme: $this->theme,
            status: $this->status,
            enabledHigh: $this->enabledHigh,
            forbiddenCategories: $this->forbiddenCategories,
            level: $this->level,
            preferences: $this->preferences,
            internalStatus: $this->internalStatus,
            rawAttributes: $rawAttributes,
        );
    }

    public function withLevel(int $level): self
    {
        $rawAttributes = $this->rawAttributes;
        $rawAttributes['level'] = $level;

        return new self(
            id: $this->id,
            username: $this->username,
            email: $this->email,
            language: $this->language,
            theme: $this->theme,
            status: $this->status,
            enabledHigh: $this->enabledHigh,
            forbiddenCategories: $this->forbiddenCategories,
            level: $level,
            preferences: $this->preferences,
            internalStatus: $this->internalStatus,
            rawAttributes: $rawAttributes,
        );
    }

    /**
     * @param array<string, mixed> $preferences
     */
    public function withPreferences(array $preferences): self
    {
        $rawAttributes = $this->rawAttributes;
        $rawAttributes['preferences'] = $preferences;

        return new self(
            id: $this->id,
            username: $this->username,
            email: $this->email,
            language: $this->language,
            theme: $this->theme,
            status: $this->status,
            enabledHigh: $this->enabledHigh,
            forbiddenCategories: $this->forbiddenCategories,
            level: $this->level,
            preferences: $preferences,
            internalStatus: $this->internalStatus,
            rawAttributes: $rawAttributes,
        );
    }

    public function withEnabledHigh(bool $enabledHigh): self
    {
        $rawAttributes = $this->rawAttributes;
        $rawAttributes['enabled_high'] = $enabledHigh;

        return new self(
            id: $this->id,
            username: $this->username,
            email: $this->email,
            language: $this->language,
            theme: $this->theme,
            status: $this->status,
            enabledHigh: $enabledHigh,
            forbiddenCategories: $this->forbiddenCategories,
            level: $this->level,
            preferences: $this->preferences,
            internalStatus: $this->internalStatus,
            rawAttributes: $rawAttributes,
        );
    }

    public function withStatus(UserStatus $status): self
    {
        $rawAttributes = $this->rawAttributes;
        $rawAttributes['status'] = $status->value;

        return new self(
            id: $this->id,
            username: $this->username,
            email: $this->email,
            language: $this->language,
            theme: $this->theme,
            status: $status,
            enabledHigh: $this->enabledHigh,
            forbiddenCategories: $this->forbiddenCategories,
            level: $this->level,
            preferences: $this->preferences,
            internalStatus: $this->internalStatus,
            rawAttributes: $rawAttributes,
        );
    }

    /**
     * For genuinely non-promoted keys only -- use withLanguage()/withLevel()/
     * withPreferences()/withEnabledHigh()/withUsername() for the named
     * properties instead; those keep rawAttributes in sync too, this one
     * doesn't (it has no way to know a $key it's given aliases one).
     */
    public function withRawAttribute(string $key, mixed $value): self
    {
        $rawAttributes = $this->rawAttributes;
        $rawAttributes[$key] = $value;

        return new self(
            id: $this->id,
            username: $this->username,
            email: $this->email,
            language: $this->language,
            theme: $this->theme,
            status: $this->status,
            enabledHigh: $this->enabledHigh,
            forbiddenCategories: $this->forbiddenCategories,
            level: $this->level,
            preferences: $this->preferences,
            internalStatus: $this->internalStatus,
            rawAttributes: $rawAttributes,
        );
    }
}
