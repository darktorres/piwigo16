// IE PNG transparency fix — kept for legacy IE users only.
// Modern browsers skip the filter block entirely.
function correctPNG(): void {
    for (let i = 0; i < document.images.length; i++) {
        const img = document.images[i];
        const imgName = img.src.toUpperCase();
        if (imgName.substring(imgName.length - 3) === 'PNG') {
            const imgID = img.id ? "id='" + img.id + "' " : '';
            const imgClass = img.className ? "class='" + img.className + "' " : '';
            const imgTitle = img.title ? 'title="' + img.title + '" ' : '';
            const imgAlt = img.alt ? 'alt="' + img.alt + '" ' : '';
            let imgStyle = 'display:inline-block;' + img.style.cssText;
            if (img.align === 'left') imgStyle = 'float:left;' + imgStyle;
            if (img.align === 'right') imgStyle = 'float:right;' + imgStyle;
            const anchor = img.parentElement as HTMLAnchorElement | null;
            if (anchor?.href !== undefined && anchor.href !== '')
                imgStyle = 'cursor:hand;' + imgStyle;
            const strNewHTML =
                '<span ' +
                imgID +
                imgClass +
                imgTitle +
                imgAlt +
                ' style="width:' +
                img.width +
                'px; height:' +
                img.height +
                'px;' +
                imgStyle +
                ';' +
                "filter:progid:DXImageTransform.Microsoft.AlphaImageLoader(src='" +
                img.src +
                "', sizingMethod='scale');\">" +
                '</span>';
            img.outerHTML = strNewHTML;
            i -= 1;
        }
    }
}

window.addEventListener('load', correctPNG);

export {};
