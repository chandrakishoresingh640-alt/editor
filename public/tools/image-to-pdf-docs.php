<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';

render_header('Image to PDF or Docs', 'tools');
tool_intro(
    'Pages from pictures',
    'Image to searchable PDF',
    'Combine images into one PDF with no document or image metadata. When OCR is available, an invisible text layer sits over the picture so the page can be searched. The photo stays visible.'
);
?>
<section class="section">
  <div class="wrap workspace">
    <aside class="panel card pad">
      <?php capability_note('capability-note'); ?>
      <div class="field">
        <label for="images">Images</label>
        <input id="images" type="file" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif" multiple>
      </div>
      <div id="page-list" class="pages" aria-label="Page order"></div>
      <div class="field">
        <label for="page-size">Page size</label>
        <select id="page-size">
          <option value="a4">A4</option>
          <option value="letter">Letter</option>
          <option value="fit">Fit to image</option>
        </select>
      </div>
      <div class="field">
        <label for="orientation">Orientation</label>
        <select id="orientation">
          <option value="portrait">Portrait</option>
          <option value="landscape">Landscape</option>
        </select>
      </div>
      <div class="field">
        <label for="margin">Margin (points)</label>
        <input id="margin" type="number" min="0" max="144" value="36">
      </div>
      <div class="checks">
        <label><input id="searchable" type="checkbox"> Searchable text layer</label>
      </div>
      <div class="field">
        <label for="language">OCR language</label>
        <select id="language"><option value="eng">eng</option></select>
      </div>
      <div class="btn-row">
        <button id="build" class="btn" type="button" disabled>Download PDF</button>
        <button id="server-build" class="btn secondary" type="button">Build on the server</button>
      </div>
      <p id="status" role="status"></p>
      <h2>Google Docs</h2>
      <p class="hint">Docs export sends only the text in the box below, after you click the button. Images are not uploaded to Google from this page.</p>
      <div class="field">
        <label for="docs-text">Text for Google Docs</label>
        <textarea id="docs-text"></textarea>
      </div>
      <button id="docs" class="btn secondary" type="button">Create a Google Doc</button>
    </aside>
    <div class="stage">
      <canvas id="preview" aria-label="First page preview" hidden></canvas>
    </div>
  </div>
</section>
<noscript><p class="wrap">This tool needs JavaScript to arrange images and build a PDF.</p></noscript>
<?php
render_footer([
    '/assets/vendor/pdf-lib/pdf-lib.min.js',
    '/assets/vendor/tesseract/tesseract.min.js',
    '/assets/js/clean.js',
    '/assets/js/image-pdf.js',
]);
