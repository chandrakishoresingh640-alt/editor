<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';
render_header('About', 'about');
?>
<article class="legal wrap">
  <p class="kicker">About</p>
  <h1>What Quire does, and what it refuses to pretend.</h1>
  <p>Quire is a document workspace for PDFs and images you are allowed to process. It is a PHP application with a browser layer for viewing, drawing, and OCR, so it can be copied onto Apache shared hosting.</p>
  <h2>Where the work happens</h2>
  <p>PDF viewing uses PDF.js in your browser. New PDFs can be built in the browser with pdf-lib, or on the server with PHP when GD is available. Image filters use the canvas on your device and, when you ask for server processing, PHP’s GD library. Ghostscript is used only to render PDF pages on the server. Tesseract is used only when you run server OCR. qpdf is used for password removal when it is installed; otherwise a built-in reader handles common Standard-security PDFs.</p>
  <h2>YouTube</h2>
  <p>A video URL can provide public title and author through YouTube’s oEmbed endpoint, and richer details when a YouTube Data API key is configured. Captions are requested only through the official Captions API after you sign in with Google, and only for videos that channel owns. If that fails, paste a transcript you already have. The original unpublished script is not available from the URL.</p>
  <h2>Editing and erasing</h2>
  <p>Added text, highlights, and shapes are written into a new PDF. The overlay eraser paints an opaque cover and can leave the original text underneath. Permanent redaction rebuilds that page as an image so the covered content is not still selectable. The two actions are labelled differently.</p>
</article>
<?php render_footer();