<?php

declare(strict_types=1);


// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\calendar
 */

/** URL keyword for list view */
define('CAL_VIEW_LIST', 'list');
/** URL keyword for calendar view */
define('CAL_VIEW_CALENDAR', 'calendar');

/** Calendar date array indices */
define('CYEAR', 0);
define('CMONTH', 1);
define('CDAY', 2);
define('CWEEK', 1);

function initialize_calendar(): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Calendar\CalendarService::class)->initializeCalendar();
}
