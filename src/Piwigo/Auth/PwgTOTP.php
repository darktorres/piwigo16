<?php

declare(strict_types=1);

namespace Piwigo\Auth;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;

final class PwgTOTP
{
    /**
     * Generate a Base32 secret for TOTP
     *
     * @param string $secret Base32-encoded secret
     * @return string TOTP Code
     */
    private static function generateCodeFromTimestamp(string $secret, float $timestamp): string
    {
        $key = PwgBase32::decode($secret);

        $msg = pack('N*', 0) . pack('N*', $timestamp); // hash_hmac need this form
        $hash = hash_hmac('sha1', $msg, (string) $key, true);

        // RFC 4226, section 5.3
        $offset = ord(substr($hash, -1)[0]) & 0x0F;
        $part = substr($hash, $offset, 4);
        $unpacked = unpack('N', $part);
        $rawNum = $unpacked !== false ? $unpacked[1] : 0;
        $number = (is_int($rawNum) ? $rawNum : 0) & 0x7FFFFFFF;

        $code = $number % 1000000; // code 6 digits $number % 10^6
        return str_pad((string)$code, 6, '0', STR_PAD_LEFT); // 123 become 000123
    }

    /**
     * Generate a Base32 secret for TOTP
     *
     * @param int $length Length in bytes (default: 20)
     * @return string Base32-encoded secret
     */
    public static function generateSecret($length = 20): string
    {
        $random = random_bytes(max(1, $length));
        return PwgBase32::encode($random, false);
    }

    /**
     * Get Otp auth url
     *
     * @param string $secret Encoded base32 secret
     * @return string otpauth://totp/ url
     */
    public static function getOtpAuthUrl(string $secret): string
    {
        $url = substr(UrlService::getAbsoluteRootUrl(), 0, -1);
        return 'otpauth://totp/'.CurrentUser::get()->username.':'.$url.'?secret='.$secret.'&issuer=Piwigo&algorithm=sha1&digits=6&period=30';
    }

    /**
     * Get Qr Code
     *
     * @param string $secret Encoded base32 secret
     * @return string data:image/png;base64..
     */
    public static function getQrCode(string $secret): string
    {
        $otp_url = self::getOtpAuthUrl($secret);

        $qrCode = new QrCode($otp_url);
        $writer = new PngWriter();
        $result = $writer->write($qrCode);
        $qrcode_image = $result->getString();
        $base64_qrcode = base64_encode($qrcode_image);
        return 'data:image/png;base64,' . $base64_qrcode;
    }

    /**
     * Generate a TOTP Code
     *
     * @param string $secret Encoded base32 secret
     * @return string 6 digits TOTP code
     */
    public static function generateCode(string $secret, int $timestamp = 30): string
    {
        $timestamp = floor(time() / $timestamp); // e.g 58338889 > 30-second intervals since 1970 at the moment T
        return self::generateCodeFromTimestamp($secret, $timestamp);
    }

    /**
     * Verify TOTP Code
     *
     * @param string $secret Encoded base32 secret
     * @param int $check_interval Number of 30s steps to check before/after current (default: 1)
     */
    public static function verifyCode(string $code, string $secret, int $timestamp = 30, int $check_interval = 1): bool
    {
        $timestamp = (int) floor(time() / $timestamp);

        // generate a totp code for 30s intervals
        // following or preceding the current one and check it
        for ($i = -$check_interval; $i <= $check_interval; $i++) {
            $interval_timestamp = $timestamp + $i;
            $generated_code = self::generateCodeFromTimestamp($secret, $interval_timestamp);
            if (hash_equals($generated_code, $code)) {
                return true;
            }
        }

        return false;
    }
}
