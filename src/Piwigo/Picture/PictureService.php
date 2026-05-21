<?php

declare(strict_types=1);

namespace Piwigo\Picture;

use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Image\ImageRepository;

final readonly class PictureService
{
    public function __construct(
        private ImageRepository $imageRepo,
    ) {
    }

    public function getDefaultSlideshowParams(): SlideshowParams
    {
        return new SlideshowParams(
            period: Config::slideshowPeriod(),
            repeat: Config::slideshowRepeat(),
            play:   true,
        );
    }

    public function correctSlideshowParams(SlideshowParams $params): SlideshowParams
    {
        $period = $params->period;
        if ($period < Config::slideshowPeriodMin()) {
            $period = Config::slideshowPeriodMin();
        } elseif ($period > Config::slideshowPeriodMax()) {
            $period = Config::slideshowPeriodMax();
        }
        if ($period === $params->period) {
            return $params;
        }
        return new SlideshowParams($period, $params->repeat, $params->play);
    }

    public function decodeSlideshowParams(string $encoded = ''): SlideshowParams
    {
        $period = Config::slideshowPeriod();
        $repeat = Config::slideshowRepeat();
        $play   = true;

        if (is_numeric($encoded)) {
            $period = (float) $encoded;
        } else {
            $matches = [];
            if (preg_match_all('/([a-z]+)-(\d+)/', $encoded, $matches) > 0) {
                $matchcount = count($matches[1]);
                for ($i = 0; $i < $matchcount; $i++) {
                    if ($matches[1][$i] === 'period') {
                        $period = (float) $matches[2][$i];
                    }
                }
            }
            if (preg_match_all('/([a-z]+)-(true|false)/', $encoded, $matches) > 0) {
                $matchcount = count($matches[1]);
                for ($i = 0; $i < $matchcount; $i++) {
                    match ($matches[1][$i]) {
                        'repeat' => $repeat = BoolUtil::fromMixed($matches[2][$i]),
                        'play'   => $play   = BoolUtil::fromMixed($matches[2][$i]),
                        default  => null,
                    };
                }
            }
        }

        return $this->correctSlideshowParams(new SlideshowParams($period, $repeat, $play));
    }

    public function encodeSlideshowParams(SlideshowParams $params): string
    {
        $default = $this->getDefaultSlideshowParams();
        $result  = '';

        if ($params->period !== $default->period) {
            $result .= '+period-' . (string) $params->period;
        }
        if ($params->repeat !== $default->repeat) {
            $result .= '+repeat-' . ($params->repeat ? 'true' : 'false');
        }
        if ($params->play !== $default->play) {
            $result .= '+play-' . ($params->play ? 'true' : 'false');
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
