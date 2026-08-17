#!/usr/bin/env python3
# Rebuilds docs/blog/piwigo-in-modern-php.html from the template in this
# directory, embedding each font in fonts/ as a base64 data URI.
#
# Usage: python3 docs/blog/src/build.py

import base64
from pathlib import Path

SRC_DIR = Path(__file__).parent
TEMPLATE = SRC_DIR / "piwigo-in-modern-php.template.html"
FONTS_DIR = SRC_DIR / "fonts"
OUTPUT = SRC_DIR.parent / "piwigo-in-modern-php.html"

FONT_PLACEHOLDERS = {
    "{{SERIF_400}}": "plex-serif-400.woff2",
    "{{SERIF_700}}": "plex-serif-700.woff2",
    "{{SANS_400}}": "plex-sans-400.woff2",
    "{{SANS_600}}": "plex-sans-600.woff2",
    "{{MONO_400}}": "plex-mono-400.woff2",
    "{{MONO_500}}": "plex-mono-500.woff2",
}


def main() -> None:
    html = TEMPLATE.read_text()

    for placeholder, filename in FONT_PLACEHOLDERS.items():
        count = html.count(placeholder)
        if count != 1:
            raise SystemExit(f"expected exactly 1 occurrence of {placeholder}, found {count}")
        font_bytes = (FONTS_DIR / filename).read_bytes()
        html = html.replace(placeholder, base64.b64encode(font_bytes).decode("ascii"))

    OUTPUT.write_text(html)
    print(f"wrote {OUTPUT} ({len(html):,} bytes)")


if __name__ == "__main__":
    main()
