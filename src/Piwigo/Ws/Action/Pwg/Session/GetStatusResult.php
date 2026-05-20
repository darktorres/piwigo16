<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Session;

use Piwigo\Common\Enum\UserStatus;
use Piwigo\Ws\WsResult;

/**
 * `pwg.session.getStatus` output DTO — request/user snapshot.
 *
 * Two omitted-key carve-outs are part of the wire contract: the
 * PiwigoRemoteSync client expects the `save_visits` / `connected_with`
 * keys absent (toArray() drops them when its flags are null), and the
 * Apache-HttpClient client expects `available_sizes` absent.
 * `upload_file_types` / `upload_form_chunk_size` appear only for
 * admin requests.
 */
final readonly class GetStatusResult implements WsResult
{
    /**
     * @param list<string>|null  $availableSizes
     * @param string|null        $uploadFileTypes
     */
    public function __construct(
        public string $username,
        public UserStatus $status,
        public string $theme,
        public string $language,
        public string $pwgToken,
        public string $charset,
        public string $currentDatetime,
        public string $version,
        public ?bool $saveVisits,
        public ?string $connectedWith,
        public ?array $availableSizes,
        public ?string $uploadFileTypes,
        public ?int $uploadFormChunkSize,
    ) {
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        $res = [
            'username'         => $this->username,
            'status'           => $this->status->value,
            'theme'            => $this->theme,
            'language'         => $this->language,
            'pwg_token'        => $this->pwgToken,
            'charset'          => $this->charset,
            'current_datetime' => $this->currentDatetime,
            'version'          => $this->version,
        ];
        if ($this->saveVisits !== null) {
            $res['save_visits'] = $this->saveVisits;
        }
        if ($this->connectedWith !== null) {
            $res['connected_with'] = $this->connectedWith;
        }
        if ($this->availableSizes !== null) {
            $res['available_sizes'] = $this->availableSizes;
        }
        if ($this->uploadFileTypes !== null) {
            $res['upload_file_types'] = $this->uploadFileTypes;
        }
        if ($this->uploadFormChunkSize !== null) {
            $res['upload_form_chunk_size'] = $this->uploadFormChunkSize;
        }
        return $res;
    }
}
