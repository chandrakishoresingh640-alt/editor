<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';

render_header('YouTube summarizer', 'tools');
tool_intro(
    'Transcripts you can use',
    'YouTube video notes',
    'Load public video details, fetch captions only when you own the video, and summarize a transcript. An unpublished script is not available from a URL.'
);
?>
<section class="section">
  <div class="wrap tool-narrow">
    <?php capability_note('capability-note'); ?>
    <div class="field">
      <label for="video-url">YouTube URL</label>
      <input id="video-url" type="url" inputmode="url" placeholder="https://www.youtube.com/watch?v=…" autocomplete="off">
    </div>
    <div class="btn-row">
      <button id="fetch-info" class="btn" type="button">Fetch video information</button>
      <button id="fetch-captions" class="btn secondary" type="button">Fetch transcript</button>
    </div>
    <article id="meta" class="card pad" hidden>
      <img id="meta-thumb" class="thumb" alt="" hidden>
      <h2 id="meta-title"></h2>
      <p id="meta-author"></p>
      <p id="meta-note" class="hint"></p>
    </article>
    <div id="owned-captions" class="note" hidden>
      Caption download uses the official YouTube API and only works for videos on a channel you connect with Google. Other videos need a transcript you paste yourself.
    </div>
    <div class="field">
      <label for="transcript">Transcript</label>
      <p id="transcript-kind" class="hint">Paste a caption transcript if one cannot be fetched. This box is the text that will be summarized. It is not claimed to be the creator’s unpublished script.</p>
      <textarea id="transcript" spellcheck="true"></textarea>
    </div>
    <div class="field">
      <label for="length">Summary length</label>
      <select id="length">
        <option value="short">Short</option>
        <option value="medium" selected>Medium</option>
        <option value="detailed">Detailed</option>
      </select>
    </div>
    <div id="ai-consent-wrap" class="checks" hidden>
      <label><input id="ai-consent" type="checkbox"> Send this transcript to the configured AI provider. Leave this off to keep the summary on this server.</label>
    </div>
    <div class="btn-row">
      <button id="summarize" class="btn" type="button">Summarize video</button>
      <button id="copy-transcript" class="btn ghost" type="button">Copy transcript</button>
      <button id="copy-summary" class="btn ghost" type="button">Copy summary</button>
      <button id="download-txt" class="btn ghost" type="button">Download as TXT</button>
      <button id="download-pdf" class="btn ghost" type="button">Download as PDF</button>
    </div>
    <div class="field">
      <label for="summary">Summary</label>
      <p id="summary-note" class="hint">Key points, sections, takeaways, and action items appear here.</p>
      <textarea id="summary" spellcheck="true"></textarea>
    </div>
  </div>
</section>
<noscript><p class="wrap">This tool needs JavaScript to contact the server.</p></noscript>
<?php
render_footer(['/assets/js/youtube.js']);
