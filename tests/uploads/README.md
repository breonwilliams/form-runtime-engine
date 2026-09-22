# Upload inspection: fixtures and matrix

What protects the site from uploaded files, how it is measured, and how to
keep it measured. The code is `includes/Uploads/class-fre-upload-inspector.php`.

## Why this exists

Until 1.11.0 every upload was searched for code-like strings, several only 2–3
bytes long (`<%`, `$$`, `<?=`). Compressed image, PDF and Illustrator data
contain those by chance. Measured on 2026-09-21:

| | 1.10.1 | 1.11.0 |
|---|---|---|
| Real artwork from a designer's machine (331 files: AI, EPS, SVG, PDF, PNG, JPG) | **275 refused (83%)** | 0 refused |
| Illustrator exports (72: AI, EPS ±preview, PDF, SVG ±editing data) | **72 refused** | 0 refused |
| Generated files of accepted types, under 25 MB (15) | 8 refused | 0 refused |
| Motion photos under 25 MB (7) | not measured | 0 refused |
| Committed genuine fixtures (17) | **14 refused** | 0 refused |
| Committed attack fixtures (49) | 48 refused | 48 refused |

The 1.10.1 "blocks" of the 24 malicious SVGs were the blanket `.svg` ban, which
also refused every real SVG. A 725 Print Lab customer saw "File contains
potentially dangerous content." for an ordinary 7.8 MB phone photo.

## What protects the site now

1. The extension must be one the field allows. Executable extensions are
   blocked whatever a field says; only SVG may be opted in by a field.
2. The detected content type must match the extension. A PNG named `.jpg` is
   accepted and stored as `.png` when PNG is also allowed (WordPress core does
   the same).
3. The header must match the format (magic bytes, including the DOS EPS
   header Illustrator writes when an EPS has a preview).
4. Raster images must parse as images.
5. Every file: `<?php` followed by whitespace, and the PHAR stub
   `__halt_compiler(`. Neither occurs by chance (0 of 424 genuine files).
6. JPEG/PNG metadata and bytes after the image ends: any PHP open tag,
   including `<?=` and `<? `. Motion-photo video and Ultra HDR gain maps are
   recognised; there only code-shaped PHP with a closing tag is refused,
   because random video bytes contain `<? ` (7 of 7 motion photos otherwise).
7. SVG is parsed as XML and refused if it carries scripts, event handlers,
   `javascript:`/`data:` links, external entities, external `<use>`, or HTML
   in a `foreignObject`. Illustrator's editing data (`<!ENTITY>` namespace
   declarations and an Adobe-only `foreignObject`) is allowed.
8. Stored files get a random name with the (corrected) allowed extension.

Deliberately accepted: JavaScript inside a PDF. It runs in the reader
(sandboxed by Acrobat and browser PDF viewers), never on this server or on
this site's origin, and refusing it would refuse real PDFs with form fields.

Not checked here, and why: `<?=` in the raw bytes of PDF, AI, EPS and DST
files. It appears by chance in real files (8.7% of the corpus, including
code-shaped runs inside ASCII85 EPS data), and it only matters if a separate
flaw makes the server include an uploaded file as PHP — the random stored
name and allowed extension are the protection for that.

## Fixtures (`tests/Fixtures/uploads/`)

Committed, tested in CI by `tests/Unit/UploadInspectorTest.php`, never shipped
(the release excludes `tests/`). Rebuild with `bash tests/uploads/build-fixtures.sh`.

- `genuine/` — made with real tools: Illustrator (fresh artwork drawn by
  `illustrator-artwork.jsx`: AI, EPS with and without a TIFF preview, PDF,
  editable and plain SVG), a photo round-tripped through Apple's HEIC encoder
  (`sips`), the same with an XMP packet, a motion photo (JPEG + MP4 from
  ffmpeg), an Ultra HDR-style JPEG, a PDF with embedded fonts (cairo via
  `rsvg-convert`), a multi-page PDF, a Tajima DST (`pyembroidery`), a plain
  web SVG, and a PNG/JPEG each saved under the other's extension.
- `malicious/` — PHP polyglots in JPEG/PNG metadata, appended after the image,
  after a fake motion-photo box, as short tags and as a PHAR stub; PHP and
  HTML renamed as images and PDFs; mismatched content (GIF as PNG, ZIP as
  PDF); and SVGs with scripts, event handlers (including split across lines),
  `javascript:` links (plain, entity-encoded, tab-split, via an internal
  entity, via `<animate>`/`<set>`), `data:text/html`, nested SVG data,
  `foreignObject` HTML (including inside an Illustrator-style switch),
  external `<use>`, XXE, billion laughs, CSS `@import`, a PHP processing
  instruction, null bytes, gzip content and a namespaced script.

No file here comes from a person's own disk.

## Matrix (`tests/uploads/matrix.php`)

```
php tests/uploads/matrix.php                        # committed fixtures
php tests/uploads/matrix.php --corpus=/path/to/dir  # add a local corpus
php tests/uploads/matrix.php --types=png,jpg,pdf    # a different field
php tests/uploads/matrix.php --verbose              # every file and its time
```

A corpus directory has `genuine/` and/or `malicious/`. Files in `genuine/`
that are not really their format go in `genuine/NOT-REAL.txt` (one name per
line) and must be refused. The script exits 1 on any regression in either
direction. Point it at a folder of real customer artwork before changing the
inspector — the committed fixtures prove the rules, a large real corpus
proves the false-refusal rate.
