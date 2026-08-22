<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api;

/**
 * {@see SessionStatusPresenter::present()}'s own response body --
 * `uploadFileTypes`/`uploadFormChunkSize` are admin-only, both included
 * together or not at all.
 */
final readonly class SessionStatus
{
    /**
     * @param list<string> $availableSizes
     * @param ?list<string> $uploadFileTypes
     */
    public function __construct(
        public string $username,
        public string $status,
        public string $theme,
        public string $language,
        public string $pwgToken,
        public string $charset,
        public string $currentDatetime,
        public string $version,
        public bool $saveVisits,
        public ?string $connectedWith,
        public array $availableSizes,
        public ?array $uploadFileTypes = null,
        public ?int $uploadFormChunkSize = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'username' => $this->username,
            'status' => $this->status,
            'theme' => $this->theme,
            'language' => $this->language,
            'pwgToken' => $this->pwgToken,
            'charset' => $this->charset,
            'currentDatetime' => $this->currentDatetime,
            'version' => $this->version,
            'saveVisits' => $this->saveVisits,
            'connectedWith' => $this->connectedWith,
            'availableSizes' => $this->availableSizes,
        ];

        if ($this->uploadFileTypes !== null) {
            $result['uploadFileTypes'] = $this->uploadFileTypes;
            $result['uploadFormChunkSize'] = $this->uploadFormChunkSize;
        }

        return $result;
    }
}
