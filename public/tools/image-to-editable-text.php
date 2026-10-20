<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';

render_header('Image to editable text', 'tools');
tool_intro(
    'OCR',
    'Image to editable text',
    'Read the words in a photo, correct them, and download the text. OCR estimates line size. It does not recreate the exact look of the picture.'
);
?>
<section class="section">
  <div class="wrap workspace">
    <aside class="panel card pad">
      <div class="drop" data-dropzone data-kind="image" data-accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif">
        <strong>Drop an image</strong>
        <p>JPG, PNG, GIF, and WebP when this server can read it.</p>
        <label class="btn secondary">Choose an image<input class="sr-only" type="file"></label>
        <p data-status class="hint">The picture stays in this browser until you choose server OCR.</p>
      </div>
      <?php capability_note('capability-note'); ?>
      <div class="choice" role="group" aria-label="Output mode">
        <button class="btn" type="button" data-mode="extract">Text extraction</button>
        <button class="btn secondary" type="button" data-mode="reconstruct">Document reconstruction</button>
      </div>
      <div class="field">
        <label for="brightness">Brightness</label>
        <input id="brightness" type="range" min="-40" max="40" value="0" data-preview>
      </div>
      <div class="field">
        <label for="contrast">Contrast</label>
        <input id="contrast" type="range" min="-40" max="80" value="10" data-preview>
      </div>
      <div class="checks">
        <label><input id="grayscale" type="checkbox" data-preview> Grayscale</label>
        <label><input id="threshold" type="checkbox" data-preview> Threshold</label>
      </div>
      <div class="field">
        <label for="threshold-level">Threshold level</label>
        <input id="threshold-level" type="range" min="40" max="220" value="160" data-preview>
      </div>
      <div class="btn-row">
        <button id="rotate-left" class="btn ghost" type="button">Rotate left</button>
        <button id="rotate-right" class="btn ghost" type="button">Rotate right</button>
        <button id="apply-crop" class="btn ghost" type="button">Apply crop</button>
      </div>
      <p class="hint">Drag on the picture to mark a crop, then apply it.</p>
      <div class="field">
        <label for="engine">OCR engine</label>
        <select id="engine">
          <option value="browser">Browser OCR</option>
          <option value="server">Server OCR (Tesseract)</option>
        </select>
      </div>
      <div class="field">
        <label for="language">Language</label>
        <select id="language"><option value="eng">eng</option></select>
      </div>
      <button id="run-ocr" class="btn" type="button">Run OCR</button>
      <p id="status" role="status"></p>
    </aside>
    <div>
      <div class="canvas-wrap">
        <canvas id="canvas" aria-label="Image preview"></canvas>
        <div id="lines" class="lines"></div>
      </div>
      <div id="extract-panel">
        <div class="field">
          <label for="text">Editable text</label>
          <textarea id="text" spellcheck="true"></textarea>
        </div>
        <div class="field">
          <label for="font-size">Font size for the downloaded document</label>
          <input id="font-size" type="number" min="8" max="48" value="12">
        </div>
      </div>
      <div id="reconstruct-panel" hidden>
        <p class="hint">Reconstruction places each OCR line in its own box, sized from that line’s height. Edit a box, then download the matched layout. Paragraphs are the lines OCR grouped, not a perfect copy of the original design.</p>
      </div>
      <div class="btn-row">
        <button id="copy-text" class="btn ghost" type="button">Copy text</button>
        <button id="download-txt" class="btn ghost" type="button">Download TXT</button>
        <button id="download-docx" class="btn ghost" type="button">Download DOCX</button>
        <button id="download-pdf" class="btn ghost" type="button">Download PDF</button>
        <button id="download-matched" class="btn secondary" type="button">Download matched layout</button>
      </div>
    </div>
  </div>
</section>
<noscript><p class="wrap">This tool needs JavaScript for the image editor and browser OCR.</p></noscript>
<?php
render_footer([
    '/assets/vendor/pdf-lib/pdf-lib.min.js',
    '/assets/vendor/tesseract/tesseract.min.js',
    '/assets/js/clean.js',
    '/assets/js/upload.js',
    '/assets/js/image-text.js',
]);
