<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';

render_header('PDF editor', 'tools');
tool_intro(
    'Markup and export',
    'PDF editor',
    'Add text, highlights, drawings, and shapes. A white overlay covers content visually. Permanent redaction rebuilds the page as an image so the covered text is no longer in the file.'
);
?>
<section class="section">
  <div class="wrap page-pad">
    <div class="drop" data-dropzone data-kind="pdf" data-accept=".pdf,application/pdf">
      <strong>Drop a PDF</strong>
      <p>Existing text can be copied into editable boxes. A scanned page needs OCR. Arbitrary text inside the original file is not rewritten in place.</p>
      <label class="btn secondary">Choose a PDF<input class="sr-only" type="file"></label>
      <p data-status class="hint">Edits stay in this browser until you download the new PDF.</p>
    </div>
    <div id="viewer" hidden>
      <div class="thumbs" id="thumbs" aria-label="Page thumbnails"></div>
      <div class="editor-shell">
        <div class="toolbar" role="toolbar" aria-label="Editing tools">
          <button class="btn" type="button" data-tool="select">Select</button>
          <button class="btn secondary" type="button" data-tool="text">Text</button>
          <button class="btn secondary" type="button" data-tool="highlight">Highlight</button>
          <button class="btn secondary" type="button" data-tool="ink">Draw</button>
          <button class="btn secondary" type="button" data-tool="line">Line</button>
          <button class="btn secondary" type="button" data-tool="rect">Shape</button>
          <button class="btn secondary" type="button" data-tool="eraser">Overlay eraser</button>
          <button class="btn secondary" type="button" data-tool="redact">Permanent redaction</button>
          <span class="sep" aria-hidden="true"></span>
          <label for="color">Colour <input id="color" type="color" value="#1b1714"></label>
          <label for="font-size">Size <input id="font-size" class="num" type="number" min="8" max="72" value="18"></label>
          <label for="font">Font
            <select id="font">
              <option value="Helvetica">Helvetica</option>
              <option value="Times">Times</option>
              <option value="Courier">Courier</option>
              <option value="DejaVu">DejaVu</option>
            </select>
          </label>
          <label class="inline"><input id="flatten" type="checkbox"> Flatten overlay eraser into the page image</label>
        </div>
        <div class="stage" id="stage">
          <div class="page-frame" id="frame">
            <canvas id="pdf" class="pdf" aria-label="PDF page"></canvas>
            <svg id="overlay" class="overlay" aria-hidden="true"></svg>
            <div id="text-layer" class="overlay"></div>
          </div>
        </div>
        <div class="btn-row">
          <button id="prev" class="btn ghost" type="button">Previous</button>
          <span id="page-label"></span>
          <button id="next" class="btn ghost" type="button">Next</button>
          <button id="zoom-out" class="btn ghost" type="button">Zoom out</button>
          <button id="zoom-in" class="btn ghost" type="button">Zoom in</button>
          <button id="fit" class="btn ghost" type="button">Fit to page</button>
          <button id="rotate" class="btn ghost" type="button">Rotate page</button>
        </div>
        <div class="btn-row">
          <button id="undo" class="btn ghost" type="button">Undo</button>
          <button id="redo" class="btn ghost" type="button">Redo</button>
          <button id="delete" class="btn ghost" type="button">Delete</button>
          <button id="reset" class="btn ghost" type="button">Reset changes</button>
          <button id="to-boxes" class="btn ghost" type="button">Copy existing text into boxes</button>
          <button id="ocr-page" class="btn ghost" type="button">OCR this page</button>
          <button id="original" class="btn secondary" type="button">Download original PDF</button>
          <button id="save" class="btn" type="button">Download edited PDF</button>
        </div>
        <p id="selection-note" class="hint">Nothing selected</p>
        <p id="status" role="status"></p>
        <div class="note">
          <p><strong>Overlay eraser</strong> paints a white rectangle on top. The original text can still be selected unless you also tick “Flatten overlay eraser”, which rebuilds that page as an image.</p>
          <p><strong>Permanent redaction</strong> rebuilds the page as a picture with the marked area painted white, so the covered text is not left underneath.</p>
          <p>The edited download is a new file with no document info, XMP, or image metadata. The original upload is unchanged.</p>
        </div>
      </div>
    </div>
  </div>
</section>
<noscript><p class="wrap">This editor needs JavaScript and runs in the browser.</p></noscript>
<?php
render_footer([
    '/assets/vendor/pdfjs/pdf.min.js',
    '/assets/vendor/pdf-lib/pdf-lib.min.js',
    '/assets/vendor/tesseract/tesseract.min.js',
    '/assets/js/clean.js',
    '/assets/js/upload.js',
    '/assets/js/pdf-editor.js',
]);
