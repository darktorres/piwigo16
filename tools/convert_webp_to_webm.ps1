Get-ChildItem -Path ./ -Recurse -Filter *.webp | Sort-Object Length -Descending | ForEach-Object {
    $filePath = $_.FullName

    # Use ImageMagick to get the number of frames in the WebP
    $frameCount = (magick identify -format %n $filePath)

    if ($frameCount -gt 1) {
        $outputFilePath = [System.IO.Path]::ChangeExtension($filePath, "webm")
        $tempGif = [System.IO.Path]::ChangeExtension($filePath, "gif")

        # FFmpeg can't demux animated WebP — convert to GIF first via ImageMagick
        magick $filePath $tempGif

        # Convert GIF to WebM
        ffmpeg -y -i $tempGif -c:v libvpx-vp9 -pix_fmt yuva420p -threads 12 -row-mt 1 -slices 4 -qmin 28 -crf 30 -qmax 32 -qcomp 1 -b:v 0 $outputFilePath

        Remove-Item -LiteralPath $tempGif -Force
        Remove-Item -LiteralPath $filePath -Force
    }
}
