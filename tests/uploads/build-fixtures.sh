#!/usr/bin/env bash
#
# Rebuild tests/Fixtures/uploads/ with the real tools a print shop's
# customers use. Run on macOS (sips and Illustrator are Apple/Adobe tools).
#
#   bash tests/uploads/build-fixtures.sh
#
# Needs: ImageMagick (magick), ffmpeg, rsvg-convert, python3 with
# pyembroidery (pip install pyembroidery), sips (macOS), and Adobe
# Illustrator for the six Illustrator files (skipped if it is not installed).
#
# The committed fixtures are what CI tests; rerun this only to regenerate
# them, then run `php tests/uploads/matrix.php` and the unit suite.

set -euo pipefail

cd "$(dirname "$0")/../.."
G="tests/Fixtures/uploads/genuine"
M="tests/Fixtures/uploads/malicious"
T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT
mkdir -p "$G" "$M"

# ── Genuine ───────────────────────────────────────────────────────────

# Phone photo: a noisy image round-tripped through Apple's HEIC encoder and
# back, as an iPhone does when a site's file field does not accept HEIC.
magick -size 800x600 plasma:fractal -attenuate 0.4 +noise Gaussian -quality 85 "$T/cam.jpg"
sips -s format heic "$T/cam.jpg" --out "$T/cam.heic" >/dev/null
sips -s format jpeg "$T/cam.heic" --out "$G/photo-phone.jpg" >/dev/null

# The same photo with an XMP packet (<?xpacket …?>), which phones and Adobe apps write.
python3 - "$G/photo-phone.jpg" "$G/photo-with-xmp.jpg" <<'EOF'
import sys, struct
d = open(sys.argv[1], 'rb').read()
xmp = (b'http://ns.adobe.com/xap/1.0/\x00'
       b'<?xpacket begin="\xef\xbb\xbf" id="W5M0MpCehiHzreSzNTczkc9d"?>'
       b'<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"/></x:xmpmeta>'
       b'<?xpacket end="w"?>')
open(sys.argv[2], 'wb').write(d[:2] + b'\xff\xe1' + struct.pack('>H', len(xmp) + 2) + xmp + d[2:])
EOF

# Motion photo (JPEG + MP4, as Android and Samsung cameras write) and an
# Ultra HDR-style JPEG (JPEG + gain-map JPEG).
ffmpeg -loglevel error -y -f lavfi -i "mandelbrot=s=480x270:r=24,noise=alls=40:allf=t" -t 1 \
  -c:v libx264 -preset veryfast -pix_fmt yuv420p "$T/motion.mp4"
cat "$G/photo-phone.jpg" "$T/motion.mp4" > "$G/motion-photo.jpg"
magick -size 300x225 gradient:gray10-gray90 -quality 80 "$T/gain.jpg"
cat "$G/photo-phone.jpg" "$T/gain.jpg" > "$G/ultra-hdr-style.jpg"

# Logo PNG; a PNG named .jpg and a JPEG named .png (stored under the right extension).
magick -size 400x300 gradient:'#136'-'#fc3' -fill '#c33' -draw 'circle 200,150 260,210' "$G/logo.png"
cp "$G/logo.png" "$G/png-saved-as.jpg"
magick -size 320x240 plasma:fractal -quality 80 "jpg:$G/jpeg-saved-as.png"

# PDFs: text with embedded fonts (cairo), and a multi-page PDF.
cat > "$T/text.svg" <<'EOF'
<svg xmlns="http://www.w3.org/2000/svg" width="800" height="400"><rect width="800" height="400" fill="#123"/><text x="40" y="200" font-family="Georgia" font-size="72" fill="#fc0">725 Print Lab Tee</text><text x="40" y="300" font-family="Helvetica" font-size="40" fill="#fff">Front chest, 2 colours</text></svg>
EOF
rsvg-convert -f pdf -o "$G/text-with-fonts.pdf" "$T/text.svg"
cp "$T/text.svg" "$G/web-graphic.svg"
magick "$G/logo.png" "$G/jpeg-saved-as.png" "$G/multi-page.pdf"

# Tajima DST embroidery.
python3 - "$G/embroidery.dst" <<'EOF'
import sys, math, pyembroidery
p = pyembroidery.EmbPattern()
for c in range(3):
    p.add_thread({'color': 0x112233 * (c + 1)})
    p.add_block([(math.cos(t / 30) * (200 + c * 50), math.sin(t / 27) * (180 + c * 40)) for t in range(3000)], c)
pyembroidery.write_dst(p, sys.argv[1])
EOF

# Illustrator: AI, EPS with and without preview, PDF, editable and plain SVG,
# drawn fresh (shapes, text, an embedded raster). The embedded image is placed
# from /tmp/art so no local path ends up in the files' link metadata.
if [ -d "/Applications/Adobe Illustrator 2026" ] || ls /Applications | grep -q "Adobe Illustrator"; then
  mkdir -p /tmp/art
  magick -size 240x160 gradient:'#c33'-'#fc0' -fill white -draw 'rectangle 20,60 220,100' /tmp/art/logo-mark.png
  sed "s#__OUT__#$(pwd)/$G/#" tests/uploads/illustrator-artwork.jsx > "$T/art.jsx"
  osascript -e "tell application \"Adobe Illustrator\" to do javascript (read POSIX file \"$T/art.jsx\" as «class utf8»)"
else
  echo "Illustrator not installed: keeping the committed Illustrator fixtures."
fi

# ── Malicious ─────────────────────────────────────────────────────────
# Built on the genuine fixtures above. The hand-written SVG/PDF/HTML samples
# are committed as-is (they are text; see each file).

B="$G/photo-phone.jpg"; BP="$G/logo.png"; P='<?php system($_GET["c"]); ?>'
magick "$B"  -set comment "$P"                         "$M/php-in-jpeg-comment.jpg"
magick "$B"  -set comment '<?=`$_GET[0]`?>'            "$M/php-shortecho-jpeg.jpg"
magick "$B"  -set comment '<? system($_GET[0]); ?>'    "$M/php-shorttag-jpeg-comment.jpg"
magick "$BP" -set comment "$P"                         "$M/php-in-png-text.png"
{ cat "$B";  printf '%s' "$P"; }                     > "$M/php-appended-jpeg.jpg"
{ cat "$BP"; printf '%s' "$P"; }                     > "$M/php-appended-png.png"
{ cat "$B";  printf '<?=`id`?>'; }                   > "$M/php-shortecho-after-eoi.jpg"
{ cat "$BP"; printf '<?=`id`?>'; }                   > "$M/php-shortecho-after-iend.png"
{ cat "$B";  printf '<?php __HALT_COMPILER(); ?>'; } > "$M/phar-halt-jpeg.jpg"
{ cat "$B";  printf '\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom<?=`$_GET[0]`?>'; }           > "$M/fake-motion-photo-php.jpg"
{ cat "$B";  printf '\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom<? system($_GET[0]); ?>'; } > "$M/fake-motion-photo-shorttag.jpg"
cp "$M/php-in-png-text.png" "$M/png-polyglot-named-jpg.jpg"
printf '%s\n' "$P"                                   > "$M/php-renamed.jpg"
printf 'GIF89a%s' "$P"                               > "$M/gif-header-php.png"
printf '\xff\xd8\xff\xe0%s' "$P"                     > "$M/jpeg-magic-then-php.jpg"
printf '<html><script>fetch("/wp-admin/")</script></html>' > "$M/html-renamed.png"
magick "$BP" "$T/g.gif" && cp "$T/g.gif" "$M/gif-content.png"

echo "Fixtures rebuilt. Now: php tests/uploads/matrix.php && composer run test:unit"
