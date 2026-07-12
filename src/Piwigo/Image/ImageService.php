<?php

declare(strict_types=1);

namespace Piwigo\Image;

/**
 * Pure computation ported from `include/functions_picture.inc.php` --
 * slideshow param encode/decode/correct and PDF page counting, none of
 * which touch the DB (that's {@see ImageRepository}'s one method).
 */
final class ImageService
{
    /**
     * @return array{period: mixed, repeat: mixed, play: bool}
     */
    public function getDefaultSlideshowParams(): array
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        return [
            'period' => $conf['slideshow_period'],
            'repeat' => $conf['slideshow_repeat'],
            'play' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function correctSlideshowParams(array $params = []): array
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $period = $params['period'] ?? 0;
        $min = $conf['slideshow_period_min'];
        $max = $conf['slideshow_period_max'];

        if ($period < $min) {
            $params['period'] = $min;
        } elseif ($period > $max) {
            $params['period'] = $max;
        }

        return $params;
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeSlideshowParams(?string $encodeParams = null): array
    {
        $result = $this->getDefaultSlideshowParams();

        if ($encodeParams !== null && is_numeric($encodeParams)) {
            $result['period'] = $encodeParams;
        } else {
            $matches = [];
            if ((bool) preg_match_all('/([a-z]+)-(\d+)/', (string) $encodeParams, $matches)) {
                $matchCount = count($matches[1]);
                for ($i = 0; $i < $matchCount; $i++) {
                    $result[$matches[1][$i]] = $matches[2][$i];
                }
            }

            if ((bool) preg_match_all('/([a-z]+)-(true|false)/', (string) $encodeParams, $matches)) {
                $matchCount = count($matches[1]);
                for ($i = 0; $i < $matchCount; $i++) {
                    $result[$matches[1][$i]] = get_boolean($matches[2][$i]);
                }
            }
        }

        return $this->correctSlideshowParams($result);
    }

    /**
     * @param  array<string, mixed>  $decodeParams
     */
    public function encodeSlideshowParams(array $decodeParams = []): string
    {
        // decodeSlideshowParams()/correctSlideshowParams() only ever
        // populate scalar values (period as int|numeric-string, play and
        // the regex-matched flags as bool|string); filter defensively so
        // array_diff_assoc() only ever compares string-castable values.
        $corrected = array_filter($this->correctSlideshowParams($decodeParams), is_scalar(...));
        $defaults = array_filter($this->getDefaultSlideshowParams(), is_scalar(...));
        $params = array_diff_assoc($corrected, $defaults);
        $result = '';

        // $params' keys are always string: correctSlideshowParams() and
        // getDefaultSlideshowParams() both declare array<string, mixed>.
        foreach ($params as $name => $value) {
            $value = boolean_to_string($value);
            if (! is_scalar($value)) {
                continue;
            }

            $result .= '+' . $name . '-' . $value;
        }

        return $result;
    }

    /**
     * Returns the number of pages of a PDF file, or false if it can't be
     * read.
     */
    public function countPdfPages(string $pdfPath): int|false
    {
        $pdfText = file_get_contents($pdfPath);
        if ($pdfText === false) {
            return false;
        }

        return preg_match_all('/\/Page\W/', $pdfText);
    }
}
