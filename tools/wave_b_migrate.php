<?php

/**
 * Wave B migration: $page['bucket'][] = EXPR → PageState::current()->addBucket(EXPR)
 *
 * Usage: php tools/wave_b_migrate.php [--dry-run] file1.php [file2.php ...]
 */

declare(strict_types=1);

$dry_run = in_array('--dry-run', $argv);
$files = array_filter(array_slice($argv, 1), fn ($a) => $a !== '--dry-run');

$method_map = [
    'errors'   => 'addError',
    'warnings' => 'addWarning',
    'messages' => 'addMessage',
    'infos'    => 'addInfo',
];

// Matches: indent $page['bucket'][] = rest
$open_re = '/^(\s*)\$page\[\'(errors|warnings|messages|infos)\'\]\[\]\s*=\s*(.*)$/';

$total = 0;

foreach ($files as $path) {
    $lines = file($path);
    if ($lines === false) {
        echo "Cannot read: $path\n";
        continue;
    }

    $out = [];
    $changed = false;
    $n = count($lines);
    $i = 0;

    while ($i < $n) {
        $raw = $lines[$i];
        $stripped = rtrim($raw); // no trailing newline/spaces

        if (!preg_match($open_re, $stripped, $m)) {
            $out[] = $raw;
            $i++;
            continue;
        }

        $indent = $m[1];
        $bucket = $m[2];
        $rest   = $m[3]; // content after "= "
        $method = $method_map[$bucket];
        $fq     = $indent . '\\Piwigo\\Core\\PageState::current()->' . $method . '(';

        if ($rest === '') {
            // Pattern B: assignment is empty, value starts on next line
            // $page['bucket'][] =
            //     expr;       ← may span multiple lines
            $i++;
            $value_lines = [];
            while ($i < $n) {
                $vraw = $lines[$i];
                $vs = rtrim($vraw);
                $value_lines[] = ['raw' => $vraw, 'stripped' => $vs];
                $i++;
                if (str_ends_with($vs, ';')) {
                    break;
                }
            }
            // Rewrite last line: strip trailing ; and close with );
            $last = &$value_lines[count($value_lines) - 1];
            $last['stripped'] = rtrim(substr($last['stripped'], 0, -1));

            $eol = detect_eol($raw);
            $out[] = $fq . $eol;
            foreach ($value_lines as $idx => $vl) {
                if ($idx === count($value_lines) - 1) {
                    $out[] = $vl['stripped'] . ');' . $eol;
                } else {
                    $out[] = $vl['raw'];
                }
            }
            $changed = true;
            continue;
        }

        if (str_ends_with($rest, ';')) {
            // Single-line: $page['bucket'][] = expr;
            $expr = rtrim(substr($rest, 0, -1));
            $eol = detect_eol($raw);
            $out[] = $fq . $expr . ');' . $eol;
            $changed = true;
            $i++;
            continue;
        }

        // Multi-line: $page['bucket'][] = func_call_start(
        $parts = [['stripped' => $rest, 'raw' => $rest]];
        $i++;
        while ($i < $n) {
            $vraw = $lines[$i];
            $vs = rtrim($vraw);
            $parts[] = ['raw' => $vraw, 'stripped' => $vs];
            $i++;
            if (str_ends_with($vs, ';')) {
                break;
            }
        }

        // Fix last line: ); → )); or ; → );
        $last_idx = count($parts) - 1;
        $ls = $parts[$last_idx]['stripped'];
        if (str_ends_with($ls, ');')) {
            $parts[$last_idx]['stripped'] = rtrim(substr($ls, 0, -2)) . '));';
        } elseif (str_ends_with($ls, ';')) {
            $parts[$last_idx]['stripped'] = rtrim(substr($ls, 0, -1)) . ');';
        }

        $eol = detect_eol($raw);
        $out[] = $fq . $parts[0]['stripped'] . $eol;
        for ($j = 1; $j < count($parts); $j++) {
            if ($j === $last_idx) {
                $out[] = $parts[$j]['stripped'] . $eol;
            } else {
                $out[] = $parts[$j]['raw'];
            }
        }
        $changed = true;
    }

    if ($changed) {
        $new = implode('', $out);
        if ($dry_run) {
            echo "DRY RUN: $path\n";
            // Show diff summary
            $orig_count = substr_count(implode('', $lines), '$page[\'');
            $new_count = substr_count($new, '$page[\'');
            echo "  push sites before: $orig_count, after: $new_count\n";
        } else {
            file_put_contents($path, $new);
            echo "Migrated: $path\n";
        }
        $total++;
    }
}

echo "Files: $total\n";

function detect_eol(string $line): string
{
    if (str_ends_with($line, "\r\n")) {
        return "\r\n";
    }
    if (str_ends_with($line, "\n")) {
        return "\n";
    }
    return "\n";
}
