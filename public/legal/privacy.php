<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';
$minutes = (int) config('retention_minutes');
render_header('Privacy Policy');
?>
<article class="legal wrap">
  <p class="kicker">Privacy</p>
  <h1>Privacy Policy</h1>
  <p>This page describes how this installation of Quire handles files and messages. It is not a certification, and it does not claim GDPR, HIPAA, or SOC compliance.</p>
  <h2>Files you upload</h2>
  <p>PDF and image uploads are stored outside the public web directory when the host allows that layout. Each file gets a random name and is tied to your browser session. Another visitor cannot download it by guessing the name. Files in the upload and processed folders are deleted about <?= e((string) $minutes) ?> minutes after they were written. You can also remove a file from a tool when a delete control is offered.</p>
  <p>Quire does not use the contents of your documents to train a model, and it does not write document text or passwords into the application log.</p>
  <h2>Processing that stays here</h2>
  <p>Browser OCR, PDF viewing, and browser PDF editing run on your device. Server filters, Ghostscript rendering, Tesseract, and qpdf run on this server and do not send the file to a third party.</p>
  <h2>New files</h2>
  <p>PDFs and images this site creates — enhanced pages, edited PDFs, unlocked copies, image exports, and text exports — are written without document info, XMP, EXIF, IPTC, or comments. The original upload is kept as you sent it until it expires or you delete it.</p>
  <h2>Optional outside services</h2>
  <p>These run only when the site owner has configured them and you take the matching action:</p>
  <ul>
    <li>YouTube oEmbed and, if a key is set, the YouTube Data API receive the video ID so public details can be shown. They do not receive your documents.</li>
    <li>Google OAuth and the YouTube Captions API run only if you connect Google to fetch captions for a video your channel owns.</li>
    <li>Google Docs receives text only when you press the export button after connecting Google.</li>
    <li>The configured AI provider receives the transcript only when you tick the consent box. The API key stays on the server.</li>
  </ul>
  <p>Browser OCR may download an English language file from this same website. It does not upload the image to that request.</p>
  <h2>Task list</h2>
  <p>Tasks on the Lists page stay in this browser’s local storage. They are not sent to the server while you add, edit, or tick them. If you download the list as a PDF, that export is stored like any other temporary download and deleted on the same schedule.</p>
  <h2>Contact messages</h2>
  <p>Messages from the contact form are stored in a private folder for the site owner. If an email address is configured and the host allows mail, a copy is sent there. Contact messages are not part of the short document-retention window.</p>
  <h2>Cookies</h2>
  <p>The site sets one session cookie so uploads, the CSRF token, and an optional Google access token stay with your browser. See the Cookie Policy.</p>
  <h2>Deletion requests</h2>
  <p>Temporary documents expire on their own. To ask about a contact message or a configured integration, use the contact page. Include the approximate time of the upload. The operator cannot recover a file after the retention job has deleted it.</p>
</article>
<?php render_footer();