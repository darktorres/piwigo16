<?php

declare(strict_types=1);

use Piwigo\Auth\CookieService;

function cookie_path(): ?string { return CookieService::cookiePath(); }
