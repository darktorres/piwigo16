<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// P23 batch 8c: Piwigo\Image\ImageStdParams::SQUARE/etc. (src/Piwigo/,
// SEC-60-compliant real class constants) are now the canonical values --
// these global constants are thin aliases, kept for the ~90 real call
// sites across files outside this pass's own scope (i.php, install/db/
// historical migration scripts, the not-yet-migrated WS layer) rather
// than forcing a sprawling retarget disproportionate to a pure data
// constant with no function-delegation of its own. Not a "delegator" in
// the finding-4 sense -- a single canonical value with 2 names, not
// duplicated business logic. Each real caller retargets to the class
// constant directly as its own owning file gets migrated in a later
// batch (8e's WS files, primarily).
use Piwigo\Image\ImageStdParams;

define('IMG_SQUARE', ImageStdParams::SQUARE);
define('IMG_THUMB', ImageStdParams::THUMB);
define('IMG_XXSMALL', ImageStdParams::XXSMALL);
define('IMG_XSMALL', ImageStdParams::XSMALL);
define('IMG_SMALL', ImageStdParams::SMALL);
define('IMG_MEDIUM', ImageStdParams::MEDIUM);
define('IMG_LARGE', ImageStdParams::LARGE);
define('IMG_XLARGE', ImageStdParams::XLARGE);
define('IMG_XXLARGE', ImageStdParams::XXLARGE);
define('IMG_3XLARGE', ImageStdParams::THREE_XLARGE);
define('IMG_4XLARGE', ImageStdParams::FOUR_XLARGE);
define('IMG_CUSTOM', ImageStdParams::CUSTOM);
