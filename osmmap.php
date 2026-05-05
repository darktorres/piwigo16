<?php

define('PHPWG_ROOT_PATH', './');
if (isset($_GET['v']) and $_GET['v'] == 1) {
    require_once(PHPWG_ROOT_PATH. 'plugins/piwigo-openstreetmap/osmmap.php');
} elseif (isset($_GET['v']) and $_GET['v'] == 2) {
    require_once(PHPWG_ROOT_PATH. 'plugins/piwigo-openstreetmap/osmmap2.php');
} elseif (isset($_GET['v']) and $_GET['v'] == 3) {
    require_once(PHPWG_ROOT_PATH. 'plugins/piwigo-openstreetmap/osmmap3.php');
} elseif (isset($_GET['v']) and $_GET['v'] == 4) {
    require_once(PHPWG_ROOT_PATH. 'plugins/piwigo-openstreetmap/osmmap4.php');
} else {
    require_once(PHPWG_ROOT_PATH. 'plugins/piwigo-openstreetmap/osmmap3.php');
}
