(function () {
  const state = { image: null, lines: [], mode: "extract" };
  const $ = (id) => document.getElementById(id);

  function options() {
    return {
      brightness: Number($("brightness").value),
      contrast: Number($("contrast").value),
      grayscale: $("grayscale").checked,
      threshold: $("threshold").checked,
      rotate: state.rotate || 0,
    };
  }

  function draw() {
    if (!state.image) return;
    const canvas = $("canvas");
    const angle = ((state.rotate || 0) % 360 + 360) % 360;
    const swap = angle === 90 || angle === 270;
    const scale = Math.min(1, 900 / state.image.width);
    const width = state.image.width * scale;
    const height = state.image.height * scale;
    canvas.width = swap ? height : width;
    canvas.height = swap ? width : height;
    const ctx = canvas.getContext("2d", { willReadFrequently: true });
    ctx.save();
    ctx.translate(canvas.width / 2, canvas.height / 2);
    ctx.rotate((angle * Math.PI) / 180);
    const brightness = 1 + options().brightness / 100;
    const contrast = 1 + options().contrast / 100;
    ctx.filter = (options().grayscale ? "grayscale(1) " : "") + "brightness(" + brightness + ") contrast(" + contrast + ")";
    ctx.drawImage(state.image, -width / 2, -height / 2, width, height);
    ctx.restore();
    if (options().threshold) {
      const pixels = ctx.getImageData(0, 0, canvas.width, canvas.height);
      const data = pixels.data;
      for (let i = 0; i < data.length; i += 4) {
        const gray = data[i] * 0.3 + data[i + 1] * 0.59 + data[i + 2] * 0.11;
        const value = gray > Number($("threshold-level").value) ? 255 : 0;
        data[i] = data[i + 1] = data[i + 2] = value;
      }
      ctx.putImageData(pixels, 0, 0);
    }
    if (state.crop) {
      ctx.strokeStyle = "#9a4318";
      ctx.lineWidth = 2;
      ctx.strokeRect(state.crop.x, state.crop.y, state.crop.w, state.crop.h);
    }
  }

  async function recognize() {
    const canvas = $("canvas");
    const language = $("language").value || "eng";
    $("status").textContent = "Reading text…";
    $("run-ocr").disabled = true;
    try {
      if ($("engine").value === "server") {
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", 0.9));
        const body = new FormData();
        body.append("file", blob, "page.jpg");
        body.append("csrf", Quire.csrf);
        body.append("kind", "image");
        const uploaded = await Quire.api("/api/upload.php", { body });
        const result = await Quire.api("/api/process-image.php", { json: { csrf: Quire.csrf, action: "ocr", id: uploaded.file.id, language } });
        applyOcr(result.ocr, canvas.width, canvas.height);
      } else {
        const worker = await Tesseract.createWorker(language, 1, {
          workerPath: Quire.path("/assets/vendor/tesseract/worker.min.js"),
          corePath: Quire.path("/assets/vendor/tesseract/core"),
          langPath: Quire.path("/assets/vendor/tesseract/lang"),
          workerBlobURL: false,
        });
        const recognized = await worker.recognize(canvas);
        await worker.terminate();
        const lines = (recognized.data.lines || []).map((line) => ({
          text: line.text.trim(),
          left: line.bbox.x0,
          top: line.bbox.y0,
          width: line.bbox.x1 - line.bbox.x0,
          height: Math.max(8, line.bbox.y1 - line.bbox.y0),
        })).filter((line) => line.text);
        applyOcr({ text: recognized.data.text || "", lines }, canvas.width, canvas.height);
      }
      $("status").textContent = "Check the text. OCR will not match the picture exactly.";
    } catch (error) {
      $("status").textContent = error.message;
      Quire.toast(error.message);
    } finally {
      $("run-ocr").disabled = false;
    }
  }

  function applyOcr(ocr, width, height) {
    $("text").value = ocr.text || "";
    state.lines = ocr.lines || [];
    state.canvasSize = { width, height };
    renderLines();
  }

  function renderLines() {
    const host = $("lines");
    host.innerHTML = "";
    if (state.mode !== "reconstruct") return;
    const canvas = $("canvas");
    state.lines.forEach((line, index) => {
      const box = document.createElement("div");
      box.className = "line-box";
      box.contentEditable = "true";
      box.textContent = line.text;
      const size = Math.max(12, line.height * (canvas.clientHeight / canvas.height));
      box.style.left = (line.left * (canvas.clientWidth / canvas.width)) + "px";
      box.style.top = (line.top * (canvas.clientHeight / canvas.height)) + "px";
      box.style.width = Math.max(40, line.width * (canvas.clientWidth / canvas.width)) + "px";
      box.style.fontSize = size + "px";
      box.style.lineHeight = "1";
      box.addEventListener("input", () => { state.lines[index].text = box.textContent; });
      host.appendChild(box);
    });
  }

  async function downloadDocx() {
    const blocks = state.mode === "reconstruct" && state.lines.length
      ? state.lines.map((line) => ({ text: line.text, size: Math.max(10, line.height * 0.75) }))
      : $("text").value.split(/\n/).map((text) => ({ text, size: Number($("font-size").value) || 12 }));
    const data = await Quire.api("/api/export-docx.php", { json: { csrf: Quire.csrf, blocks, name: "extracted.docx" } });
    window.location = Quire.path("/api/download.php?id=" + data.file.id);
  }

  async function downloadPdf() {
    const text = state.mode === "reconstruct" && state.lines.length
      ? state.lines.map((line) => line.text).join("\n")
      : $("text").value;
    const data = await Quire.api("/api/export-text-pdf.php", { json: { csrf: Quire.csrf, text, name: "extracted.pdf" } });
    window.location = Quire.path("/api/download.php?id=" + data.file.id);
  }

  async function downloadMatched() {
    if (!state.lines.length) {
      Quire.toast("Run OCR in reconstruction mode first so line sizes are known.");
      return;
    }
    const pdf = await PDFLib.PDFDocument.create();
    const fontBytes = await fetch(Quire.path("/assets/fonts/DejaVuSans.ttf")).then((response) => response.arrayBuffer());
    const font = await pdf.embedFont(fontBytes, { subset: true });
    const canvas = $("canvas");
    const page = pdf.addPage([canvas.width, canvas.height]);
    page.drawRectangle({ x: 0, y: 0, width: canvas.width, height: canvas.height, color: PDFLib.rgb(1, 1, 1) });
    state.lines.forEach((line) => {
      const size = Math.max(8, line.height * 0.85);
      page.drawText(line.text || "", {
        x: line.left,
        y: canvas.height - line.top - size,
        size,
        font,
        color: PDFLib.rgb(0.1, 0.09, 0.08),
      });
    });
    const blob = new Blob([await pdf.save()], { type: "application/pdf" });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = "reconstructed.pdf";
    link.click();
  }

  document.addEventListener("DOMContentLoaded", async () => {
    const caps = await Quire.capabilities();
    const note = $("capability-note");
    note.hidden = false;
    note.textContent = caps.tesseract
      ? "Server OCR and browser OCR are both available. Browser OCR keeps the image on this device. The font size in reconstruction mode is estimated from each line’s height."
      : "Server OCR is not installed. Browser OCR runs on this device and can still extract text. Language files besides English must be added by the site operator.";
    const langs = (caps.browser_ocr_languages || ["eng"]);
    $("language").innerHTML = langs.map((lang) => `<option value="${lang}">${lang}</option>`).join("");
    if (!caps.tesseract) $("engine").querySelector('[value="server"]').disabled = true;
    if (!caps.zip) $("download-docx").disabled = true;

    document.querySelectorAll("[data-preview]").forEach((control) => control.addEventListener("input", draw));
    $("rotate-left").addEventListener("click", () => { state.rotate = (state.rotate || 0) - 90; draw(); });
    $("rotate-right").addEventListener("click", () => { state.rotate = (state.rotate || 0) + 90; draw(); });
    $("apply-crop").addEventListener("click", () => {
      if (!state.crop || state.crop.w < 4) return;
      const canvas = $("canvas");
      const cut = document.createElement("canvas");
      cut.width = state.crop.w;
      cut.height = state.crop.h;
      cut.getContext("2d").drawImage(canvas, state.crop.x, state.crop.y, state.crop.w, state.crop.h, 0, 0, state.crop.w, state.crop.h);
      const image = new Image();
      image.onload = () => { state.image = image; state.rotate = 0; state.crop = null; draw(); };
      image.src = cut.toDataURL("image/png");
    });
    const canvas = $("canvas");
    canvas.addEventListener("pointerdown", (event) => {
      const rect = canvas.getBoundingClientRect();
      const x = (event.clientX - rect.left) * (canvas.width / rect.width);
      const y = (event.clientY - rect.top) * (canvas.height / rect.height);
      state.dragging = { x, y };
      state.crop = { x, y, w: 0, h: 0 };
    });
    canvas.addEventListener("pointermove", (event) => {
      if (!state.dragging) return;
      const rect = canvas.getBoundingClientRect();
      const x = (event.clientX - rect.left) * (canvas.width / rect.width);
      const y = (event.clientY - rect.top) * (canvas.height / rect.height);
      state.crop.w = x - state.dragging.x;
      state.crop.h = y - state.dragging.y;
      draw();
    });
    window.addEventListener("pointerup", () => { state.dragging = null; });
    document.querySelectorAll("[data-mode]").forEach((button) => button.addEventListener("click", () => {
      state.mode = button.dataset.mode;
      document.querySelectorAll("[data-mode]").forEach((other) => other.classList.toggle("secondary", other !== button));
      $("extract-panel").hidden = state.mode !== "extract";
      $("reconstruct-panel").hidden = state.mode !== "reconstruct";
      $("lines").hidden = state.mode !== "reconstruct";
      renderLines();
    }));
    $("lines").hidden = true;
    $("run-ocr").addEventListener("click", recognize);
    $("copy-text").addEventListener("click", async () => {
      try { await navigator.clipboard.writeText($("text").value); Quire.toast("Text copied."); }
      catch (error) { Quire.toast("Copy was blocked. Select the text manually."); }
    });
    $("download-txt").addEventListener("click", () => {
      const blob = new Blob([$("text").value], { type: "text/plain" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.download = "extracted.txt";
      link.click();
    });
    $("download-docx").addEventListener("click", () => downloadDocx().catch((error) => Quire.toast(error.message)));
    $("download-pdf").addEventListener("click", () => downloadPdf().catch((error) => Quire.toast(error.message)));
    $("download-matched").addEventListener("click", () => downloadMatched().catch((error) => Quire.toast(error.message)));
    document.addEventListener("quire:file", (event) => loadImage(event.detail.raw));
    const existing = new URLSearchParams(location.search).get("file");
    if (existing) {
      Quire.api("/api/download.php?id=" + encodeURIComponent(existing))
        .then((blob) => loadImage(blob))
        .catch((error) => Quire.toast(error.message));
    }
  });

  function loadImage(blob) {
    const image = new Image();
    image.onload = () => { state.image = image; state.rotate = 0; draw(); };
    image.src = URL.createObjectURL(blob);
  }
})();
