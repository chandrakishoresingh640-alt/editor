<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';

render_header('Enhance scanned PDFs', 'tools');
tool_intro(
    'Scanned pages',
    'Enhance scanned PDFs',
    'Improve contrast, grayscale, sharpness, and rotation, then download a new PDF. The upload itself is left as it is.'
);
?>
<section class="section">
  <div class="wrap workspace">
    <aside class="panel card pad">
      <div class="drop" data-dropzone data-kind="pdf" data-accept=".pdf,application/pdf">
        <strong>Drop a scanned PDF</strong>
        <p>JPG scans can be turned into a PDF from the image tool first.</p>
        <label class="btn secondary">Choose a PDF<input class="sr-only" type="file"></label>
        <p data-status class="hint">PDF files only. The original stays in your session.</p>
      </div>
      <?php capability_note('capability-note'); ?>
      <div class="field">
        <label for="level">Enhancement level</label>
        <select id="level">
          <option value="light">Light</option>
          <option value="standard" selected>Standard</option>
          <option value="strong">Strong</option>
          <option value="custom">Custom</option>
        </select>
      </div>
      <div class="checks">
        <label><input id="grayscale" type="checkbox" data-preview checked> Grayscale</label>
        <label><input id="denoise" type="checkbox" data-preview> Reduce noise</label>
        <label><input id="threshold" type="checkbox" data-preview> High-contrast threshold</label>
        <label><input id="deskew" type="checkbox"> Straighten slight tilt</label>
      </div>
      <p id="deskew-note" class="hint"></p>
      <div class="field">
        <label class="range" for="brightness">Brightness <output id="brightness-out" for="brightness">6</output></label>
        <input id="brightness" type="range" min="-40" max="40" value="6" data-preview>
      </div>
      <div class="field">
        <label class="range" for="contrast">Contrast <output id="contrast-out" for="contrast">22</output></label>
        <input id="contrast" type="range" min="-40" max="80" value="22" data-preview>
      </div>
      <div class="field">
        <label class="range" for="sharpen">Sharpen <output id="sharpen-out" for="sharpen">45</output></label>
        <input id="sharpen" type="range" min="0" max="100" value="45" data-preview>
      </div>
      <div class="field">
        <label for="rotate">Page rotation</label>
        <select id="rotate" data-preview>
          <option value="0">No rotation</option>
          <option value="90">90° clockwise</option>
          <option value="180">180°</option>
          <option value="270">90° counter-clockwise</option>
        </select>
      </div>
      <div class="checks">
        <label><input id="searchable" type="checkbox"> Add a searchable text layer when OCR is available</label>
      </div>
      <div class="field">
        <label for="language">OCR language</label>
        <select id="language"><option value="eng">eng</option></select>
      </div>
      <p id="server-ocr-note" class="hint">A searchable PDF places invisible text over the image. It is selectable, and it will not look like a retyped page.</p>
      <div class="btn-row">
        <button id="browser-process" class="btn" type="button" data-run>Enhance in the browser</button>
        <button id="server-process" class="btn secondary" type="button" data-run>Enhance on the server</button>
      </div>
      <div class="progress" aria-hidden="true"><span id="bar"></span></div>
      <p id="status" role="status"></p>
    </aside>
    <div>
      <div id="viewer" class="stage" hidden>
        <div>
          <div class="compare">
            <canvas id="before" aria-label="Original page"></canvas>
            <div class="after" id="after-clip">
              <canvas id="after" aria-label="Enhanced preview"></canvas>
            </div>
          </div>
          <label class="range" for="split">Before and after <input id="split" type="range" min="8" max="92" value="50"></label>
          <div class="btn-row">
            <button id="prev" class="btn ghost" type="button">Previous page</button>
            <span id="page-label"></span>
            <button id="next" class="btn ghost" type="button">Next page</button>
          </div>
        </div>
      </div>
      <p class="hint">Drag the slider to compare the current page. Large files are processed one page at a time.</p>
    </div>
  </div>
</section>
<noscript><p class="wrap">This tool needs JavaScript to preview and process pages.</p></noscript>
<?php
render_footer([
    '/assets/vendor/pdfjs/pdf.min.js',
    '/assets/vendor/pdf-lib/pdf-lib.min.js',
    '/assets/vendor/tesseract/tesseract.min.js',
    '/assets/js/upload.js',
    '/assets/js/enhance.js',
]);
