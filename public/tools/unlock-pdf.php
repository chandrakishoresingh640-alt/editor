<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';

render_header('Unlock a PDF', 'tools');
tool_intro(
    'Files you are allowed to change',
    'Unlock a protected PDF',
    'Remove a password you already have, or clear permission flags on a PDF that opens without a password. This page does not try other passwords and cannot break strong encryption.'
);
?>
<section class="section">
  <div class="wrap workspace">
    <aside class="panel card pad">
      <div class="drop" data-dropzone data-kind="pdf" data-accept=".pdf,application/pdf">
        <strong>Drop a PDF</strong>
        <p>The original file is kept. A new file is created only after you confirm you may change it.</p>
        <label class="btn secondary">Choose a PDF<input class="sr-only" type="file"></label>
        <p data-status class="hint">PDF files only.</p>
      </div>
      <div class="note">
        <p>Supported protection is the standard PDF password handler. If the file needs a password to open, that password has to be the one you type. An empty user password means the file already opens, and only the restriction flags can be cleared.</p>
        <p>The unlocked download is a new PDF with no document info, XMP, or image metadata. The file you uploaded is kept until it expires.</p>
      </div>
    </aside>
    <div>
      <article id="result" class="card pad" hidden>
        <h2 id="result-title"></h2>
        <p id="result-copy"></p>
        <div id="password-wrap" class="field" hidden>
          <label for="password">Password</label>
          <input id="password" type="password" autocomplete="off">
        </div>
        <div id="confirm-wrap" class="checks" hidden>
          <label><input id="confirm" type="checkbox"> <span id="confirm-label">I own this PDF or have permission to change it.</span></label>
        </div>
        <button id="unlock" class="btn" type="button" hidden>Create an unlocked PDF</button>
      </article>
      <p id="status" role="status"></p>
    </div>
  </div>
</section>
<noscript><p class="wrap">This tool needs JavaScript to upload the PDF and check its protection.</p></noscript>
<?php
render_footer([
    '/assets/js/upload.js',
    '/assets/js/unlock.js',
]);
