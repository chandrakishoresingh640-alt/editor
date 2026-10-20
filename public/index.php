<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';

function icon(string $name): string
{
    $paths = [
        'scan' => '<path d="M4 7V5a1 1 0 0 1 1-1h2M16 4h2a1 1 0 0 1 1 1v2M4 13v2a1 1 0 0 0 1 1h2M16 16h2a1 1 0 0 0 1-1v-2"/><path d="M7 12h6"/>',
        'play' => '<path d="M8 6.5v7l6-3.5-6-3.5z"/><rect x="3" y="4" width="14" height="12" rx="2"/>',
        'text' => '<path d="M5 5h10M8 5v10M5 9h6M12 9h3"/>',
        'file' => '<path d="M6 3h6l4 4v10a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M12 3v4h4"/>',
        'edit' => '<path d="M4 16.5V20h3.5L18 9.5 14.5 6 4 16.5z"/><path d="M12.5 8l3 3"/>',
        'lock' => '<rect x="5" y="10" width="10" height="8" rx="1.5"/><path d="M8 10V7a2 2 0 0 1 4 0v3"/>',
    ];
    return '<span class="icon-blob" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' . ($paths[$name] ?? '') . '</svg></span>';
}

$tools = [
    ['enhance scanned pdf ocr deskew contrast', 'scan', 'Enhance scanned PDFs', 'Clean up contrast, grayscale, and sharpness, then download a clearer PDF.', '/tools/enhance-pdf.php'],
    ['youtube transcript summary captions', 'play', 'YouTube summarizer', 'Load public video details and summarize a transcript you already have or own.', '/tools/youtube-summarizer.php'],
    ['image ocr text extract docx', 'text', 'Image to editable text', 'Turn text in a photo into words you can correct, copy, and download.', '/tools/image-to-editable-text.php'],
    ['image pdf searchable google docs', 'file', 'Image to PDF or Docs', 'Combine images into a PDF and, when OCR is available, make the text searchable.', '/tools/image-to-pdf-docs.php'],
    ['edit pdf annotate redact text', 'edit', 'PDF editor', 'Add text, highlights, drawings, and a clearly labelled eraser or permanent redaction.', '/tools/pdf-editor.php'],
    ['unlock password restrictions', 'lock', 'Unlock a PDF', 'Remove a password you already know, or clear permission flags on a file that opens without one.', '/tools/unlock-pdf.php'],
];

render_header('Quire', 'home');
?>
<section class="hero">
  <div class="wrap">
    <p class="kicker">Document workspace</p>
    <h1>Everything You Need to Work with PDFs and Documents.</h1>
    <p class="lede">Enhance scanned files, extract text, edit PDFs, and summarize YouTube videos you are allowed to use. Heavy processing runs in your browser when the server does not have Ghostscript, Tesseract, or qpdf.</p>
    <form class="search" role="search" action="#tools">
      <label class="sr-only" for="tool-search">Search tools</label>
      <input id="tool-search" type="search" placeholder="Search tools — enhance, OCR, unlock…" autocomplete="off">
    </form>
  </div>
</section>
<section class="section" id="tools">
  <div class="wrap">
    <div class="grid">
      <?php foreach ($tools as [$keywords, $icon, $title, $copy, $href]): ?>
        <a class="card" data-tool="<?= e($keywords) ?>" href="<?= e(url_path($href)) ?>">
          <?= icon($icon) ?>
          <h3><?= e($title) ?></h3>
          <p><?= e($copy) ?></p>
          <span class="go">Open tool</span>
        </a>
      <?php endforeach; ?>
    </div>
    <p id="search-empty" class="note" hidden>No tool matches that search.</p>
  </div>
</section>
<section class="section">
  <div class="wrap">
    <div class="drop" id="home-drop">
      <strong>Drop a PDF or image</strong>
      <p>It stays in your session and is offered to the matching tools. Nothing is sent to an outside OCR or AI service from this box.</p>
      <label class="btn secondary">Choose a file<input class="sr-only" type="file"></label>
      <p data-status class="hint">PDF, JPG, PNG, GIF, or WebP.</p>
    </div>
    <div id="home-choice" class="note" hidden></div>
  </div>
</section>
<section class="section" id="how">
  <div class="wrap">
    <p class="kicker">How it works</p>
    <h2>Three steps, then a file you can download.</h2>
    <div class="steps">
      <article class="card"><span class="step-no">01</span><h3>Bring the file or the text</h3><p>Upload a document you are allowed to change, or paste a transcript you already have.</p></article>
      <article class="card"><span class="step-no">02</span><h3>Adjust only what you need</h3><p>Filters, text boxes, and summaries stay under your control. Missing server tools switch to a browser path instead of pretending to work.</p></article>
      <article class="card"><span class="step-no">03</span><h3>Download a new file</h3><p>The original upload is kept until the retention window. Downloads are tied to your session.</p></article>
    </div>
  </div>
</section>
<section class="section" id="features">
  <div class="wrap feature-row">
    <div>
      <p class="kicker">Features</p>
      <h2>Honest about what a shared host can do.</h2>
      <p>Quire is a PHP application. It does not need Node, Docker, or a background worker. Optional tools such as Ghostscript, Tesseract, Poppler, and qpdf are used when they are installed. When they are not, the page says so and offers the browser path.</p>
      <ul class="pill-list">
        <li>Scanned PDF enhancement</li>
        <li>Transcript summaries</li>
        <li>Image OCR</li>
        <li>Searchable PDFs</li>
        <li>Annotations and redaction</li>
        <li>Password removal you are authorized to do</li>
      </ul>
    </div>
    <aside class="card">
      <h3>What is not promised</h3>
      <p>An unpublished YouTube script cannot be fetched from a URL. A white rectangle is not permanent redaction. A password you do not know cannot be discovered. OCR will not clone a typeface.</p>
    </aside>
  </div>
</section>
<section class="section faq" id="faq">
  <div class="wrap">
    <p class="kicker">FAQ</p>
    <h2>Questions people ask first</h2>
    <details>
      <summary>Do my files stay on this server?</summary>
      <p>Uploads are stored outside the public web folder, under a random name, and only your session can download them. They are deleted after the configured retention window, which defaults to 60 minutes.</p>
    </details>
    <details>
      <summary>Will OCR look exactly like the scan?</summary>
      <p>No. OCR reads characters and estimates line height. Reconstruction uses those estimates. It is editable text, not a photograph of the original page.</p>
    </details>
    <details>
      <summary>Can every protected PDF be unlocked?</summary>
      <p>No. If the file needs a password, you must supply the correct one. The tool does not try other passwords. Permission-only files, which open without a password, can have those flags removed when you confirm you are allowed to.</p>
    </details>
    <details>
      <summary>Are documents sent to an AI service?</summary>
      <p>Only if an AI key is configured and you tick the consent box on the summarizer. Browser OCR and the extractive summary do not send the document away.</p>
    </details>
    <details>
      <summary>Does this work on a phone?</summary>
      <p>Yes. The layout collapses to one column. Large PDFs are still limited by the server’s upload size and by the memory of the browser doing the work.</p>
    </details>
  </div>
</section>
<section class="section" id="about">
  <div class="wrap">
    <p class="kicker">About</p>
    <h2>A quiet set of document tools.</h2>
    <p>Quire is meant for people who already have the file or the transcript and need a straightforward way to clean it up, read it, or mark it. Read the <a href="<?= e(url_path('/about.php')) ?>">about page</a> for what runs in the browser and what needs an optional server program.</p>
  </div>
</section>
<?php
render_footer(['/assets/js/upload.js']);
