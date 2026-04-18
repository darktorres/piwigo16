Get-ChildItem -Path ./ -Recurse -Filter *.webp | ForEach-Object {
    $frameCount = (magick identify -format %n $_.FullName)
    if ($frameCount -gt 1) {
        $_.FullName
    }
} | Out-File -FilePath "animated_webps.txt" -Encoding utf8
