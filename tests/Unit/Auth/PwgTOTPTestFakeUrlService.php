<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Auth;

use LogicException;
use Override;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;

/**
 * getOtpAuthUrl()/getQrCode() had zero coverage before this file: only
 * generateCodeFromTimestamp()/generateSecret()/generateCode()/verifyCode()
 * were exercised above. Only getAbsoluteRootUrl() is ever called by
 * either method -- every other UrlServiceInterface method throws so a
 * regression that starts reaching one is caught immediately, matching
 * DerivativeImageTestFakeUrlService's own established shape.
 */
final class PwgTOTPTestFakeUrlService implements UrlServiceInterface
{
    #[Override]
    public function getRootUrl(): string
    {
        throw new LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[Override]
    public function getAbsoluteRootUrl(bool $withScheme = true): string
    {
        return 'https://gallery.example.test/piwigo/';
    }

    #[Override]
    public function addUrlParams(string $url, array $params, string $argSeparator = '&amp;'): string
    {
        throw new LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[Override]
    public function makeIndexUrl(array $params = []): string
    {
        throw new LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[Override]
    public function duplicateIndexUrl(array $redefined = [], array $removed = []): string
    {
        throw new LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[Override]
    public function duplicatePictureUrl(array $redefined = [], array $removed = []): string
    {
        throw new LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[Override]
    public function makePictureUrl(array $params): string
    {
        throw new LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[Override]
    public function parseSectionUrl(array $tokens, &$nextToken, RedirectServiceInterface $redirectService): array
    {
        throw new LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[Override]
    public function parseWellKnownParamsUrl(array $tokens, int &$i): array
    {
        throw new LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[Override]
    public function getActionUrl($id, $whatPart, bool $download): string
    {
        throw new LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[Override]
    public function getElementUrl(array $elementInfo): string
    {
        throw new LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[Override]
    public function setMakeFullUrl(): void
    {
        throw new LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[Override]
    public function unsetMakeFullUrl(): void
    {
        throw new LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[Override]
    public function embellishUrl(string $url): string
    {
        throw new LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[Override]
    public function getGalleryHomeUrl(): string
    {
        throw new LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[Override]
    public function getQueryStringDiff(array $rejects = [], bool $escape = true): string
    {
        throw new LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[Override]
    public function urlIsRemote(string $url): bool
    {
        throw new LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }

    #[Override]
    public function getUserFavorites(): array
    {
        throw new LogicException('not used by getOtpAuthUrl()/getQrCode()');
    }
}
