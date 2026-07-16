<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// P23 batch 8f-1: this file's last remaining content (the
// PHOTOS_ADD_BASE_URL define()) relocated to
// Piwigo\Admin\PhotosAddDirectPageRenderer::baseUrl() -- get_root_url()
// is a request-time value, not a compile-time constant expression, so it
// couldn't become a real class `const` (and src/Piwigo/ forbids define()
// outright, SEC-60). The file itself is kept as a deliberately empty
// stub, not deleted: install/db/119-database.php and
// install/upgrade_1.4.0.php (frozen historical upgrade scripts, out of
// P23's migration scope -- see p23/04-batch8f-overview.md's exclusions)
// still unconditionally `include_once` this exact path; deleting it
// outright would fatal ("Failed opening required") if either of those
// scripts ever ran. Every other real caller's `include_once` of this
// path (all now genuinely dead, the file has no functions left) is
// removed at each of those call sites instead of relying on this stub
// staying empty forever.
