$file = "C:\temp\query2array_results.txt"
$lines = @(Get-Content $file)

$patternA = @()
$patternB = @()
$patternC = @()
$patternD = @()
$unknown = @()

foreach ($line in $lines) {
    # Extract the call part - everything after query2array(
    if ($line -match 'query2array\((.*?)\)') {
        $args = $matches[1].Trim()
        
        # Try to match pattern variations
        if ($args -match '^\$.*?$') {
            # Pattern A: query2array($q)
            $patternA += $line
        }
        elseif ($args -match '^\$.*?,\s*null,\s*[''"][\w_]+[''"]$') {
            # Pattern B: query2array($q, null, 'col')
            $patternB += $line
        }
        elseif ($args -match '^\$.*?,\s*[''"][\w_]+[''"]$') {
            # Pattern C: query2array($q, 'key')
            $patternC += $line
        }
        elseif ($args -match '^\$.*?,\s*[''"][\w_]+[''"],\s*[''"][\w_]+[''"]$') {
            # Pattern D: query2array($q, 'key', 'val')
            $patternD += $line
        }
        elseif ($args -match "^'SELECT.*?,\s*null,\s*'[\w_]+'$") {
            # Pattern B with direct SQL
            $patternB += $line
        }
        elseif ($args -match "^'SELECT.*?,\s*'[\w_]+',\s*'[\w_]+'$") {
            # Pattern D with direct SQL
            $patternD += $line
        }
        elseif ($args -match "^'SELECT.*?,\s*'[\w_]+'$") {
            # Pattern C with direct SQL
            $patternC += $line
        }
        elseif ($args -match "^'SELECT.*?'$") {
            # Pattern A with direct SQL
            $patternA += $line
        }
        else {
            $unknown += $line
        }
    }
}

Write-Host "Pattern A: $($patternA.Count)"
Write-Host "Pattern B: $($patternB.Count)"
Write-Host "Pattern C: $($patternC.Count)"
Write-Host "Pattern D: $($patternD.Count)"
Write-Host "Unknown: $($unknown.Count)"
