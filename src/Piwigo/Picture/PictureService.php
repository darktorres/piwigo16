<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Image\ImageRepository;

final class PictureService
{
    public function __construct(
        private readonly ImageRepository $imageRepo,
    ) {}

    /** @return array<string, mixed> */
    public function getDefaultSlideshowParams(): array
    {
        return [
            'period' => Config::slideshowPeriod(),
            'repeat' => Config::slideshowRepeat(),
            'play'   => true,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function correctSlideshowParams(array $params = []): array
    {
        $period = is_numeric($params['period'] ?? null) ? (float) $params['period'] : null;
        if ($period !== null) {
            if ($period < Config::slideshowPeriodMin()) {
                $params['period'] = Config::slideshowPeriodMin();
            } elseif ($period > Config::slideshowPeriodMax()) {
                $params['period'] = Config::slideshowPeriodMax();
            }
        }
        return $params;
    }

    /** @return array<string, mixed> */
    public function decodeSlideshowParams(?string $encoded = null): array
    {
        $result = $this->getDefaultSlideshowParams();

        if (is_numeric($encoded)) {
            $result['period'] = $encoded;
        } else {
            $matches = [];
            if (preg_match_all('/([a-z]+)-(\d+)/', (string) $encoded, $matches)) {
                $matchcount = count($matches[1]);
                for ($i = 0; $i < $matchcount; $i++) {
                    $result[$matches[1][$i]] = $matches[2][$i];
                }
            }

            if (preg_match_all('/([a-z]+)-(true|false)/', (string) $encoded, $matches)) {
                $matchcount = count($matches[1]);
                for ($i = 0; $i < $matchcount; $i++) {
                    $result[$matches[1][$i]] = BoolUtil::fromMixed($matches[2][$i]);
                }
            }
        }

        return $this->correctSlideshowParams($result);
    }

    /** @param array<string, mixed> $params */
    public function encodeSlideshowParams(array $params = []): string
    {
        $corrected = $this->correctSlideshowParams($params);
        $default   = $this->getDefaultSlideshowParams();
        $result    = '';

        foreach ($corrected as $name => $value) {
            if (array_key_exists($name, $default) && $default[$name] === $value) {
                continue;
            }
            $bool_val = is_bool($value) ? $value : (is_string($value) ? $value : '');
            $result .= '+' . $name . '-' . BoolUtil::toString($bool_val);
        }

        return $result;
    }

    public function increaseImageVisitCounter(int $imageId): void
    {
        $this->imageRepo->incrementHit($imageId);
    }

    public function countPdfPages(string $pdfPath): int|false
    {
        $pdftext = file_get_contents($pdfPath);
        if ($pdftext === false) {
            return false;
        }
        $matches = [];
        $num = preg_match_all('/\/Page\W/', $pdftext, $matches);
        return $num;
    }
}
