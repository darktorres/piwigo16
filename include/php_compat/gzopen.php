<?php

declare(strict_types=1);
if (!function_exists('gzopen') && function_exists('gzopen64')) {
    function gzopen(string $filename, string $mode, ?int $use_include_path = null)
    {
        return gzopen64($filename, $mode, $use_include_path);
    }
}
