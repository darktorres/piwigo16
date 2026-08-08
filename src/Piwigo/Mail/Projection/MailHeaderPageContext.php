<?php

declare(strict_types=1);

namespace Piwigo\Mail\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The header template variable set assigned by
 * {@see \Piwigo\Mail\MailService::mail()} the first time a given
 * content-type/language/theme cache key is parsed.
 */
final readonly class MailHeaderPageContext implements TemplatePageContext
{
    public function __construct(
        public string $galleryUrl,
        public string $galleryTitle,
        public string $version,
        public string $phpwgUrl,
        public string $contentEncoding,
        public string $contactMail,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'GALLERY_URL' => $this->galleryUrl,
            'GALLERY_TITLE' => $this->galleryTitle,
            'VERSION' => $this->version,
            'PHPWG_URL' => $this->phpwgUrl,
            'CONTENT_ENCODING' => $this->contentEncoding,
            'CONTACT_MAIL' => $this->contactMail,
        ];
    }
}
