(function () {
  const $ = (id) => document.getElementById(id);

  function transcript() {
    return $("transcript").value.trim();
  }

  function summaryText(data) {
    const lines = [];
    lines.push(data.overview || "");
    lines.push("", "Key points");
    (data.key_points || []).forEach((point) => lines.push("• " + point));
    lines.push("", "Sections");
    (data.sections || []).forEach((section) => lines.push((section.title || "Section") + "\n" + (section.summary || "")));
    lines.push("", "Takeaways");
    (data.takeaways || []).forEach((point) => lines.push("• " + point));
    lines.push("", "Action items");
    const actions = data.action_items || [];
    if (!actions.length) lines.push("None were detected in the text.");
    actions.forEach((point) => lines.push("• " + point));
    if (data.method) lines.push("", "Summary method: " + data.method);
    return lines.join("\n").trim();
  }

  async function copy(text, label) {
    try {
      await navigator.clipboard.writeText(text);
      Quire.toast(label + " copied.");
    } catch (error) {
      Quire.toast("Copy was blocked by the browser. Select the text and copy it manually.");
    }
  }

  function download(text, name, type) {
    const blob = new Blob([text], { type: type || "text/plain" });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = name;
    link.click();
  }

  document.addEventListener("DOMContentLoaded", async () => {
    const caps = await Quire.capabilities();
    const note = $("capability-note");
    note.hidden = false;
    const parts = [];
    parts.push(caps.youtube_api_configured
      ? "A YouTube Data API key is configured, so public title and description can be loaded."
      : "No YouTube Data API key is configured. Public oEmbed details are used when YouTube provides them.");
    parts.push("Caption download uses the official API only for videos owned by a connected channel. An unpublished script cannot be retrieved from a URL.");
    parts.push(caps.ai_configured
      ? "An AI provider is configured. It is used only if you tick the consent box. Otherwise the summary is built on this server from the text itself."
      : "No AI key is configured. Summaries are extractive: they reuse sentences from the transcript and do not call an outside model.");
    note.textContent = parts.join(" ");
    $("ai-consent-wrap").hidden = !caps.ai_configured;
    if (caps.google_oauth_configured) $("owned-captions").hidden = false;

    $("fetch-info").addEventListener("click", async () => {
      $("fetch-info").disabled = true;
      try {
        const data = await Quire.api("/api/extract-transcript.php", { json: { csrf: Quire.csrf, url: $("video-url").value, action: "metadata" } });
        $("meta").hidden = false;
        $("meta-title").textContent = data.video.title || "Untitled video";
        $("meta-author").textContent = data.video.author || "";
        $("meta-note").textContent = data.video.note || "";
        if (data.video.thumbnail) {
          $("meta-thumb").src = data.video.thumbnail;
          $("meta-thumb").hidden = false;
        }
      } catch (error) {
        Quire.toast(error.message);
      } finally {
        $("fetch-info").disabled = false;
      }
    });

    $("fetch-captions").addEventListener("click", async () => {
      try {
        const data = await Quire.api("/api/extract-transcript.php", { json: { csrf: Quire.csrf, url: $("video-url").value, action: "captions" } });
        if (data.auth_url) {
          window.location = data.auth_url;
          return;
        }
        $("transcript").value = data.transcript.text;
        $("transcript-kind").textContent = data.transcript.label;
      } catch (error) {
        $("transcript-kind").textContent = error.message;
        Quire.toast(error.message);
      }
    });

    $("summarize").addEventListener("click", async () => {
      const text = transcript();
      if (!text) {
        Quire.toast("Add a transcript first. Paste one if captions cannot be fetched.");
        return;
      }
      $("summarize").disabled = true;
      try {
        const data = await Quire.api("/api/summarize.php", {
          json: {
            csrf: Quire.csrf,
            text,
            length: $("length").value,
            use_ai: $("ai-consent")?.checked === true,
          },
        });
        $("summary").value = summaryText(data.summary);
        $("summary-note").textContent = data.summary.method === "ai"
          ? "This summary was written by the configured AI provider from the transcript you submitted."
          : "This is an extractive summary. Sentences are taken from the transcript rather than rewritten by an outside model.";
      } catch (error) {
        Quire.toast(error.message);
      } finally {
        $("summarize").disabled = false;
      }
    });

    $("copy-transcript").addEventListener("click", () => copy(transcript(), "Transcript"));
    $("copy-summary").addEventListener("click", () => copy($("summary").value, "Summary"));
    $("download-txt").addEventListener("click", () => download("Transcript\n\n" + transcript() + "\n\nSummary\n\n" + $("summary").value, "video-notes.txt"));
    $("download-pdf").addEventListener("click", async () => {
      const body = "Transcript\n\n" + transcript() + "\n\nSummary\n\n" + $("summary").value;
      try {
        const data = await Quire.api("/api/export-text-pdf.php", { json: { csrf: Quire.csrf, text: body, name: "video-notes.pdf" } });
        window.location = Quire.path("/api/download.php?id=" + data.file.id);
      } catch (error) {
        Quire.toast(error.message);
      }
    });
  });
})();
