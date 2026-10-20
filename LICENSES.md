# Licences

Quire’s own PHP, CSS, and JavaScript are provided with this repository for you to deploy. Third-party pieces keep their own licences. Read those licences before you redistribute a modified build.

## Included in the repository

| Component | Where | Licence |
| --- | --- | --- |
| PDF.js 3.11.174 | `public/assets/vendor/pdfjs/` | Apache License 2.0 |
| pdf-lib | `public/assets/vendor/pdf-lib/` | MIT. The minified build also embeds TypeScript helper code under the Apache License 2.0. |
| tesseract.js 5.1.1 and its WASM core | `public/assets/vendor/tesseract/` | Apache License 2.0 |
| English traineddata used by tesseract.js | `public/assets/vendor/tesseract/lang/eng.traineddata.gz` | Apache License 2.0 (Tesseract OCR project) |
| DejaVu Sans | `public/assets/fonts/DejaVuSans.ttf` | Bitstream Vera licence, with DejaVu changes in the public domain. See `public/assets/fonts/LICENSE-DejaVu.txt`. Do not rename the font to include “Bitstream” or “Vera” if you modify the glyphs. |
| Fraunces | `public/assets/fonts/fraunces-*.woff2` | SIL Open Font License 1.1 |
| Outfit | `public/assets/fonts/outfit-*.woff2` | SIL Open Font License 1.1 |

PDF.js, pdf-lib, and tesseract.js are served as already-built files. Deploying Quire does not require Node.js or a frontend build step.

## Optional programs on the server

These are not bundled. The application detects them and keeps working when they are absent.

| Program | Typical licence | Used for |
| --- | --- | --- |
| Ghostscript (`gs`) | AGPL-3.0 for current upstream releases | Rendering PDF pages to images for enhancement and server OCR |
| Tesseract OCR | Apache License 2.0 | Server-side OCR and searchable PDF text layers |
| Poppler (`pdfinfo`, `pdftotext`, `pdftoppm`) | GPL-2.0 or later | Page counts and diagnostics |
| qpdf | Apache License 2.0 | Inspecting and removing supported PDF passwords and permission flags |

Installing Ghostscript or Poppler on a server can bring AGPL or GPL obligations for that installation. Quire invokes them as separate processes. It does not copy their source into this repository. If you cannot accept those licences, leave the binaries uninstalled. Browser PDF.js, pdf-lib, and tesseract.js still cover viewing, editing, image PDFs, and OCR on the visitor’s device.

## PHP extensions

GD, fileinfo, mbstring, json, session, and the standard library are part of PHP and follow the PHP Licence. Zip, curl, and intl are optional and follow the same licence when they ship with PHP.
