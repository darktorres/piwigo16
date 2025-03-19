<?php

declare(strict_types=1);

$url = '../';
header('Request-URI: ' . $url);
header('Content-Location: ' . $url);
header('Location: ' . $url);
exit();
