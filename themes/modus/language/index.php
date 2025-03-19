<?php

declare(strict_types=1);

// Recursive call
$url = '../';
header('Request-URI: ' . $url);
header('Content-Location: ' . $url);
header('Location: ' . $url);
exit();
