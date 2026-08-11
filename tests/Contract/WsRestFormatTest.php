<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

/**
 * Ws\Protocol\RestEncoder -- reached via ws.php?format=rest instead of
 * the suite's usual format=json (see WsInitializer's format switch).
 * WsImagesGetInfoAndCommentTest's own rest-format test already exercises
 * encodeResponse()'s success path plus encodeStruct()/encodeArray() via
 * NamedStruct/NamedArray (derivatives/categories/tags/rates); this
 * file covers what that one doesn't: encode()'s 'boolean' gettype() case,
 * a null struct value being skipped (encodeStruct()'s two null-skip
 * checks), and a plain (non-NamedArray/NamedStruct) associative PHP
 * array hitting encode()'s bare 'array' => encodeStruct() branch (as
 * opposed to the object-based encodeArray()/encodeStruct() calls the
 * NamedArray/NamedStruct branches already prove).
 *
 * Not chased here (no real WS response exercises these): encode()'s
 * 'NULL' gettype() case (only reachable for an element of a real list --
 * a PHP null skips straight past every real encodeStruct() call before
 * ever reaching encode() itself), the generic get_object_vars() object
 * fallback (every real response object is a NamedArray/NamedStruct/
 * PwgError), and the `default` resource/unknown-type trigger_error()
 * branch (no real WS method ever returns a resource).
 */
final class WsRestFormatTest extends ContractTestCase
{
    private function restBody(string $method): string
    {
        $url = $this->baseUrl . '/ws.php?format=rest';
        $ch = curl_init($url);
        self::assertNotFalse($ch);

        $cookieJar = $this->cookieJar();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'method' => $method,
        ]));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

        $body = curl_exec($ch);
        unset($ch);

        self::assertIsString($body);

        return $body;
    }

    public function testCheckUploadEncodesATrueBooleanAs1AndOmitsANullMessage(): void
    {
        $this->loginAsAdmin();
        $body = $this->restBody('pwg.images.checkUpload');

        self::assertStringContainsString('<ready_for_upload>1</ready_for_upload>', $body);
        // message is null -- encodeStruct()'s own null-value skip means
        // the element never appears at all, not an empty one.
        self::assertStringNotContainsString('<message>', $body);
    }

    public function testGetCacheSizeEncodesAPlainNestedArrayAsAStructAndSkipsANullLeaf(): void
    {
        $this->loginAsAdmin();
        $body = $this->restBody('pwg.getCacheSize');

        // 'infos' itself is a NamedArray of ['name' => ..., 'value' => ...]
        // items (same "item/name/value" shape the tsizes assertion below
        // relies on) -- msizes' *value* is what's a genuinely plain PHP
        // array<string, int> (not a NamedArray/NamedStruct):
        // array_is_list() is false (string keys), so encode()'s bare
        // 'array' case routes it through encodeStruct(), rendering each
        // key as its own XML element directly inside <value> (confirmed
        // live -- there is no literal <msizes> tag anywhere in the body).
        self::assertMatchesRegularExpression('/<name>msizes<\/name>\s*<value>.*<square>\d+<\/square>.*<\/value>/s', $body);
        self::assertMatchesRegularExpression('/<name>msizes<\/name>\s*<value>.*<all>\d+<\/all>.*<\/value>/s', $body);

        // Real bug fixed (see PwgCore::getCacheSize()'s own docblock):
        // tsizes/msizes/cache_size
        // used to `du`/scan CurrentConfig::dataLocation() ('_data/') as a
        // bare relative path, which resolves against the front-controller
        // script's own cwd (public/) under a real Apache request -- an
        // unrelated, near-empty public/_data/ stub -- not the real _data/
        // tree this suite's own compiled templates actually live under, so
        // tsizes was always null here. Now that it's prefixed with
        // the live, container-bound Paths->root, `du` finds this suite's own real
        // compiled Smarty templates under _data/templates_c/ and tsizes is
        // a real, present, non-null byte count -- same "item/name/value"
        // shape as the msizes assertions above, not the previous
        // no-sibling-<value> shape.
        self::assertMatchesRegularExpression('/<name>tsizes<\/name>\s*<value>\d+<\/value>/', $body);
    }
}
