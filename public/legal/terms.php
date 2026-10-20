<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';
render_header('Terms of Service');
?>
<article class="legal wrap">
  <p class="kicker">Terms</p>
  <h1>Terms of Service</h1>
  <p>By using Quire you agree to process only documents and transcripts you own or have permission to modify. You are responsible for the files you upload and the text you paste.</p>
  <h2>Acceptable use</h2>
  <ul>
    <li>Do not upload malware or files you are not allowed to possess.</li>
    <li>Do not attempt to remove a password you were not given. The unlock tool accepts one password per request and is rate limited. It is not a password search.</li>
    <li>Do not use the YouTube tools to obtain a script or caption track the rights holder has not made available to you.</li>
    <li>Do not rely on OCR, summaries, or redaction for a legal filing without checking the result yourself.</li>
  </ul>
  <h2>No warranty</h2>
  <p>The tools are provided as they run on this server. A filter, OCR line, or summary can be wrong. A visual eraser can leave text underneath unless you use permanent redaction. Encrypted files this server cannot read will be refused with an error rather than a broken download.</p>
  <h2>Limitation</h2>
  <p>To the extent the law allows, the operator is not liable for lost files, incorrect text, or decisions you make from a summary. Temporary files are deleted on a schedule and may be deleted sooner if the host clears storage.</p>
  <h2>Changes</h2>
  <p>The operator may change limits, retention, or optional integrations. The privacy page describes the current data handling.</p>
</article>
<?php render_footer();