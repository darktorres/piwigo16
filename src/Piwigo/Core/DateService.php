<?php

declare(strict_types=1);

namespace Piwigo\Core;

use DateTime;
use IntlDateFormatter;
use Piwigo\Lang\Translator;
use Piwigo\Users\CurrentUser;

final class DateService
{
    public function dateDiff(\DateTime $date1, \DateTime $date2): \DateInterval
    {
        return $date1->diff($date2);
    }

    public function str2DateTime(int|string|null $original, string|null $format = null): \DateTime|false
    {
        if ($original === null || $original === '' || $original === 0) {
            return false;
        }
        if ($format !== null && $format !== '') {
            return DateTime::createFromFormat('!' . $format, (string) $original);
        }
        $t = trim((string) $original, '0123456789');
        if (empty($t)) {
            return new DateTime('@' . $original);
        }
        $ymdhms = [];
        $tok    = strtok((string) $original, '- :/');
        while ($tok !== false) {
            $ymdhms[] = $tok;
            $tok      = strtok('- :/');
        }
        if (count($ymdhms) < 3) {
            return false;
        }
        $ymdhms[3] ??= 0;
        $ymdhms[4] ??= 0;
        $ymdhms[5] ??= 0;
        $date      = new DateTime();
        $date->setDate((int) $ymdhms[0], (int) $ymdhms[1], (int) $ymdhms[2]);
        $date->setTime((int) $ymdhms[3], (int) $ymdhms[4], (int) $ymdhms[5]);
        return $date;
    }

    /** @param string[]|true|null $show */
    public function formatDateLegacy(int|string|\DateTime|null $original, array|bool|null $show = null, ?string $format = null): string
    {
        $date = ($original instanceof \DateTime) ? $original : $this->str2DateTime($original, $format);
        if (!$date) {
            return Lang::t('N/A');
        }
        if ($show === null || $show === true) {
            $show = ['day_name', 'day', 'month', 'year'];
        }
        $print = '';
        if (in_array('day_name', $show)) {
            $print .= Lang::day((int) $date->format('w')) . ' ';
        }
        if (in_array('day', $show)) {
            $print .= $date->format('j') . ' ';
        }
        if (in_array('month', $show)) {
            $print .= Lang::month((int) $date->format('n')) . ' ';
        }
        if (in_array('year', $show)) {
            $print .= $date->format('Y') . ' ';
        }
        if (in_array('time', $show)) {
            $temp = $date->format('H:i');
            if ($temp !== '00:00') {
                $print .= $temp . ' ';
            }
        }
        return trim($print);
    }

    /** @param string[]|true|null $show */
    public function formatDate(int|string|\DateTime|null $original, array|bool|null $show = null, ?string $format = null): string
    {
        $userLanguage = CurrentUser::isInitialized()
            ? CurrentUser::get()->language
            : 'en_UK';

        $date = ($original instanceof \DateTime) ? $original : $this->str2DateTime($original, $format);
        if (!$date) {
            return Lang::t('N/A');
        }
        if ($show === null || $show === true) {
            $show = ['day_name', 'day', 'month', 'year'];
        }
        if (class_exists('IntlDateFormatter') && in_array('year', $show) && in_array('month', $show)) {
            $timeType = in_array('time', $show) ? IntlDateFormatter::MEDIUM : IntlDateFormatter::NONE;
            $dateType = in_array('day_name', $show) ? IntlDateFormatter::FULL : IntlDateFormatter::LONG;
            $fmt      = new IntlDateFormatter($userLanguage, $dateType, $timeType);
            $formatted = $fmt->format($date);
            return $formatted !== false ? $formatted : Lang::t('N/A');
        }
        return $this->formatDateLegacy($original, $show, $format);
    }

    public function formatFromto(int|string|\DateTime|null $from, int|string|\DateTime|null $to, bool $full = false): string
    {
        $from = ($from instanceof \DateTime) ? $from : $this->str2DateTime(is_scalar($from) ? $from : null);
        $to   = ($to instanceof \DateTime) ? $to : $this->str2DateTime(is_scalar($to) ? $to : null);
        if ($from === false || $to === false) {
            return Lang::t('N/A');
        }
        if ($from->format('Y-m-d') === $to->format('Y-m-d')) {
            return $this->formatDate($from);
        }
        if ($full || $from->format('Y') !== $to->format('Y')) {
            $from_str = $this->formatDate($from);
        } elseif ($from->format('m') !== $to->format('m')) {
            $from_str = $this->formatDate($from, ['day_name', 'day', 'month']);
        } else {
            $from_str = $this->formatDate($from, ['day_name', 'day']);
        }
        return Lang::t('from %s to %s', $from_str, $this->formatDate($to));
    }

    public function timeSince(int|string|null $original, string $stop = 'minute', ?string $format = null, bool $withText = true, bool $withWeek = true, bool $onlyLastUnit = false): string
    {
        $date = $this->str2DateTime($original, $format);
        if (!$date) {
            return Lang::t('N/A');
        }
        $now  = new DateTime();
        $diff = $this->dateDiff($now, $date);

        $chunks = [
            'year'   => $diff->y,
            'month'  => $diff->m,
            'week'   => 0,
            'day'    => $diff->d,
            'hour'   => $diff->h,
            'minute' => $diff->i,
            'second' => $diff->s,
        ];
        if ($withWeek) {
            $chunks['week'] = (int) floor($chunks['day'] / 7);
            $chunks['day']  = $chunks['day'] - $chunks['week'] * 7;
        }
        $j     = array_search($stop, array_keys($chunks));
        $print = '';
        $i     = 0;
        if (!$onlyLastUnit) {
            foreach ($chunks as $name => $value) {
                if ($value != 0) {
                    $print .= ' ' . Translator::get()->plural('%d ' . $name, '%d ' . $name . 's', $value);
                }
                if (!empty($print) && $i >= $j) {
                    break;
                }
                $i++;
            }
        } else {
            $keys = array_keys($chunks);
            while ($print === '' && $i < count($keys)) {
                $name  = $keys[$i];
                $value = $chunks[$name];
                if ($value != 0) {
                    $print = Translator::get()->plural('%d ' . $name, '%d ' . $name . 's', $value);
                }
                if (!empty($print) && $i >= $j) {
                    break;
                }
                $i++;
            }
        }
        $print = trim($print);
        if ($withText) {
            $print = $diff->invert ? Lang::t('%s ago', $print) : Lang::t('%s in the future', $print);
        }
        return $print;
    }

    public function transformDate(mixed $original, mixed $formatIn, mixed $formatOut, mixed $default = null): ?string
    {
        if ($original === null || $original === '' || $original === 0) {
            return is_string($default) ? $default : null;
        }
        $date = $this->str2DateTime(is_int($original) || is_string($original) ? $original : null, is_string($formatIn) ? $formatIn : null);
        if ($date === false) {
            return is_string($default) ? $default : null;
        }
        return $date->format(is_scalar($formatOut) ? (string) $formatOut : '');
    }
}
