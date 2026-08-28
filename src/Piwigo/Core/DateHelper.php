<?php

declare(strict_types=1);

namespace Piwigo\Core;

use DateInterval;
use DateTime;
use DateTimeInterface;
use IntlDateFormatter;
use LogicException;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Config\CurrentConfig;
use Piwigo\Lang\Translator;

final class DateHelper
{
    private static ?Translator $translatorFallback = null;

    /**
     * Resolves via the container rather than being carried as a
     * constructor property: DateHelper is fully static (never `new`'d, no
     * `$this` to hang a resolver off of), and ~30 real call sites across
     * Admin/Controller/Auth rule out threading Lang/Translator through
     * every one as explicit params. Lang has no safe pre-boot fallback
     * (its constructor needs real collaborators), so this always resolves
     * through the container.
     */
    private static function lang(): Lang
    {
        $lang = Kernel::container()->get(Lang::class);
        if (! $lang instanceof Lang) {
            throw new LogicException('Container returned an unexpected type for ' . Lang::class);
        }

        return $lang;
    }

    /**
     * Same container-resolve reasoning as self::lang() above, but unlike
     * Lang, Translator has a safe pre-boot fallback: a fresh, memoized
     * `new Translator(new CurrentConfig(), ...)`.
     */
    private static function translator(): Translator
    {
        if (Kernel::isBooted()) {
            $translator = Kernel::container()->get(Translator::class);
            if (! $translator instanceof Translator) {
                throw new LogicException('Container returned an unexpected type for ' . Translator::class);
            }

            return $translator;
        }

        return self::$translatorFallback ??= new Translator(new CurrentConfig(), new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations')));
    }

    public static function dateDiff(DateTimeInterface $date1, DateTimeInterface $date2): DateInterval
    {
        return $date1->diff($date2);
    }

    /**
     * converts a value into a DateTimeInterface object
     *
     * @param int|string|\DateTimeInterface|false $original timestamp, datetime
     *   string, or an already-converted DateTimeInterface (returned as-is;
     *   callers pass the same value through this method repeatedly) --
     *   false/empty short-circuits to the false/''/0/'0' check below, so
     *   some callers may pass another method's own DateTimeInterface|false
     *   straight through
     * @param string|null $format input format respecting date() syntax
     */
    public static function str2DateTime(int|string|DateTimeInterface|false $original, ?string $format = null): DateTimeInterface|false
    {
        if ($original === false || $original === '' || $original === 0 || $original === '0') {
            return false;
        }

        if ($original instanceof DateTimeInterface) {
            return $original;
        }

        if ($format !== null && $format !== '') {// from known date format
            return DateTime::createFromFormat('!' . $format, (string) $original); // ! char to reset fields to UNIX epoch
        } else {
            $t = trim((string) $original, '0123456789');
            if ($t === '') { // from timestamp
                return new DateTime('@' . $original);
            } else { // from unknown date format (assuming something like Y-m-d H:i:s)
                $ymdhms = [];
                $tok = strtok((string) $original, '- :/');
                while ($tok !== false) {
                    $ymdhms[] = $tok;
                    $tok = strtok('- :/');
                }

                if (count($ymdhms) < 3) {
                    return false;
                }
                if (! isset($ymdhms[3])) {
                    $ymdhms[3] = 0;
                }
                if (! isset($ymdhms[4])) {
                    $ymdhms[4] = 0;
                }
                if (! isset($ymdhms[5])) {
                    $ymdhms[5] = 0;
                }

                $date = new DateTime();
                $date->setDate((int) $ymdhms[0], (int) $ymdhms[1], (int) $ymdhms[2]);
                $date->setTime((int) $ymdhms[3], (int) $ymdhms[4], (int) $ymdhms[5]);
                return $date;
            }
        }
    }

    /**
     * returns a formatted and localized date for display (LEGACY use formatDate)
     *
     * @param int|string|\DateTimeInterface|false $original timestamp, datetime string, or
     *   an already-converted DateTimeInterface/false -- same as formatDate(), since this
     *   re-derives via the same permissive str2DateTime()
     * @param string[]|null $show list of components displayed, default is ['day_name', 'day', 'month', 'year']
     * @param string|null $format input format respecting date() syntax
     */
    public static function formatDateLegacy(int|string|DateTimeInterface|false $original, ?array $show = null, ?string $format = null): string
    {
        $date = self::str2DateTime($original, $format);

        if (! (bool) $date) {
            return self::lang()->t('N/A');
        }

        if ($show === null) {
            $show = ['day_name', 'day', 'month', 'year'];
        }

        $print = '';
        if (in_array('day_name', $show, true)) {
            $print .= self::lang()->day((int) $date->format('w')) . ' ';
        }

        if (in_array('day', $show, true)) {
            $print .= $date->format('j') . ' ';
        }

        if (in_array('month', $show, true)) {
            $print .= self::lang()->month((int) $date->format('n')) . ' ';
        }

        if (in_array('year', $show, true)) {
            $print .= $date->format('Y') . ' ';
        }

        if (in_array('time', $show, true)) {
            $temp = $date->format('H:i');
            if ($temp !== '00:00') {
                $print .= $temp . ' ';
            }
        }

        return trim($print);
    }

    /**
     * returns a formatted and localized date for display
     *
     * @param int|string|\DateTimeInterface|false $original timestamp, datetime string, or
     *   an already-converted DateTimeInterface/false -- formatFromto() passes
     *   str2DateTime()'s own return type straight through; both are handled
     *   gracefully (a DateTimeInterface passes through str2DateTime() as-is, false/empty
     *   short-circuits to the "N/A" string below)
     * @param string[]|null $show list of components displayed, default is ['day_name', 'day', 'month', 'year']
     *    THIS PARAMETER IS PLANNED TO CHANGE
     * @param string|null $format input format respecting date() syntax
     */
    public static function formatDate(int|string|DateTimeInterface|false $original, ?array $show = null, ?string $format = null): string
    {
        $date = self::str2DateTime($original, $format);

        if (! (bool) $date) {
            return self::lang()->t('N/A');
        }

        if ($show === null) {
            $show = ['day_name', 'day', 'month', 'year'];
        }

        // use IntlDateFormatter for proper i18n need pkg php-intl
        if (class_exists('IntlDateFormatter')
          and in_array('year', $show, true)
          and in_array('month', $show, true)
        ) {
            $timeType = in_array('time', $show, true) ? IntlDateFormatter::MEDIUM : IntlDateFormatter::NONE;
            $dateType = IntlDateFormatter::FULL;

            if (! in_array('day_name', $show, true)) {
                $dateType = IntlDateFormatter::LONG;
            }

            $fmt = new IntlDateFormatter(self::lang()->currentUserLanguage() ?? AppInfo::DEFAULT_LANGUAGE, $dateType, $timeType);
            $formatted = $fmt->format($date);
            if ($formatted !== false) {
                return $formatted;
            }
        }

        return self::formatDateLegacy($original, $show, $format);
    }

    /**
     * Format a "From ... to ..." string from two dates
     */
    public static function formatFromto(string $from, string $to, bool $full = false): string
    {
        $fromDate = self::str2DateTime($from);
        $toDate = self::str2DateTime($to);

        if ($fromDate === false || $toDate === false) {
            return self::lang()->t('N/A');
        }

        if ($fromDate->format('Y-m-d') === $toDate->format('Y-m-d')) {
            return self::formatDate($fromDate);
        } else {
            if ($full || $fromDate->format('Y') !== $toDate->format('Y')) {
                $from_str = self::formatDate($fromDate);
            } elseif ($fromDate->format('m') !== $toDate->format('m')) {
                $from_str = self::formatDate($fromDate, ['day_name', 'day', 'month']);
            } else {
                $from_str = self::formatDate($fromDate, ['day_name', 'day']);
            }
            $to_str = self::formatDate($toDate);

            return self::lang()->t('from %s to %s', $from_str, $to_str);
        }
    }

    /**
     * Works out the time since the given date
     *
     * @param int|string $original timestamp or datetime string
     * @param string $stop year,month,week,day,hour,minute,second
     * @param string|null $format input format respecting date() syntax
     * @param bool $with_text append "ago" or "in the future"
     */
    public static function timeSince(int|string $original, string $stop = 'minute', ?string $format = null, bool $with_text = true, bool $with_week = true, bool $only_last_unit = false): string
    {
        $date = self::str2DateTime($original, $format);

        if (! (bool) $date) {
            return self::lang()->t('N/A');
        }

        $now = Env::now();
        $diff = self::dateDiff($now, $date);

        $chunks = [
            'year' => $diff->y,
            'month' => $diff->m,
            'week' => 0,
            'day' => $diff->d,
            'hour' => $diff->h,
            'minute' => $diff->i,
            'second' => $diff->s,
        ];

        // DateInterval does not contain the number of weeks
        if ($with_week) {
            $chunks['week'] = (int) floor($chunks['day'] / 7);
            $chunks['day'] = $chunks['day'] - $chunks['week'] * 7;
        }

        $j = array_search($stop, array_keys($chunks), true);

        $print = '';
        $i = 0;

        if (! $only_last_unit) {
            foreach ($chunks as $name => $value) {
                if ($value !== 0) {
                    $print .= ' ' . self::translator()->plural('%d ' . $name, '%d ' . $name . 's', $value);
                }
                if ($print !== '' && $i >= $j) {
                    break;
                }
                $i++;
            }
        } else {
            $reversed_chunks_names = array_keys($chunks);
            while ($print === '' && $i < count($reversed_chunks_names)) {
                $name = $reversed_chunks_names[$i];
                $value = $chunks[$name];
                if ($value !== 0) {
                    $print = self::translator()->plural('%d ' . $name, '%d ' . $name . 's', $value);
                }
                if ($print !== '' && $i >= $j) {
                    break;
                }
                $i++;
            }
        }

        $print = trim($print);

        if ($with_text) {
            // $diff->invert is 0 both when $date is strictly after $now AND
            // when $date == $now exactly (PHP's own DateInterval semantics: a
            // zero-length interval isn't "negative") -- comparing the DateTime
            // objects directly instead of trusting $diff->invert avoids
            // misreporting an exact-equality result ("0 seconds ago") as "in
            // the future". Real-clock callers essentially never hit this exact
            // tie; a frozen Env::now() test clock hits it whenever the compared
            // timestamp was itself written via Env::now().
            if ($now >= $date) {
                $print = self::lang()->t('%s ago', $print);
            } else {
                $print = self::lang()->t('%s in the future', $print);
            }
        }

        return $print;
    }

    /**
     * transform a date string from a format to another (MySQL to d/M/Y for instance)
     *
     * @param string|null $default returned as-is if $original is empty or unparsable
     */
    public static function transformDate(string $original, string $format_in, string $format_out, ?string $default = null): ?string
    {
        if ($original === '' || $original === '0') {
            return $default;
        }
        $date = self::str2DateTime($original, $format_in);
        if ($date === false) {
            return $default;
        }
        return $date->format($format_out);
    }

    /**
     * Checks if the provided string is valid for a comparison test with a datetime field in MySQL
     *
     * Possible values : YYYY-MM-DD HH:MM:SS or YYYY-MM-DD
     */
    public static function isValidMysqlDatetime(string $datetime): bool
    {
        // first we check the full date+time
        $format = 'Y-m-d H:i:s';
        $date = DateTime::createFromFormat($format, $datetime);
        if ((bool) $date and $date->format($format) === $datetime) {
            return true;
        }

        // in case it fails, let's check with only date and no time
        $format = 'Y-m-d';
        $date = DateTime::createFromFormat($format, $datetime);
        if ((bool) $date and $date->format($format) === $datetime) {
            return true;
        }

        return false;
    }
}
