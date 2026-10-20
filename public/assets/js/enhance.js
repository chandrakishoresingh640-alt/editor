(function () {
  const state = { doc: null, page: 1, fileId: null, busy: false };
  const $ = (id) => document.getElementById(id);

  function options() {
    return {
      level: $("level").value,
      grayscale: $("grayscale").checked,
      brightness: Number($("brightness").value),
      contrast: Number($("contrast").value),
      sharpen: Number($("sharpen").value),
      denoise: $("denoise").checked,
      deskew: $("deskew").checked,
      threshold: $("threshold").checked,
      rotate: Number($("rotate").value),
      searchable: $("searchable").checked,
      language: $("language").value,
    };
  }

  function applyLevel(level) {
    const presets = {
      light: { brightness: 4, contrast: 12, sharpen: 25, denoise: false },
      standard: { brightness: 6, contrast: 22, sharpen: 45, denoise: true },
      strong: { brightness: 8, contrast: 36, sharpen: 70, denoise: true },
    };
    const preset = presets[level];
    if (!preset) return;
    $("brightness").value = preset.brightness;
    $("contrast").value = preset.contrast;
    $("sharpen").value = preset.sharpen;
    $("denoise").checked = preset.denoise;
  }

  function filteredCanvas(source, opts) {
    const canvas = document.createElement("canvas");
    canvas.width = source.width;
    canvas.height = source.height;
    const ctx = canvas.getContext("2d", { willReadFrequently: true });
    const brightness = 1 + opts.brightness / 100;
    const contrast = 1 + opts.contrast / 100;
    if (ctx.filter !== undefined) {
      ctx.filter = (opts.grayscale ? "grayscale(1) " : "") + "brightness(" + brightness + ") contrast(" + contrast + ")" + (opts.denoise ? " blur(0.4px)" : "");
      ctx.drawImage(source, 0, 0);
      ctx.filter = "none";
    } else {
      ctx.drawImage(source, 0, 0);
      paintPixels(ctx, canvas, opts);
    }
    if (opts.threshold) {
      const pixels = ctx.getImageData(0, 0, canvas.width, canvas.height);
      const data = pixels.data;
      for (let i = 0; i < data.length; i += 4) {
        const gray = data[i] * 0.3 + data[i + 1] * 0.59 + data[i + 2] * 0.11;
        const value = gray > 160 ? 255 : 0;
        data[i] = data[i + 1] = data[i + 2] = value;
      }
      ctx.putImageData(pixels, 0, 0);
    }
    if (opts.sharpen > 20) sharpen(ctx, canvas, opts.sharpen);
    if (opts.rotate) {
      return rotateCanvas(canvas, opts.rotate);
    }
    return canvas;
  }

  function paintPixels(ctx, canvas, opts) {
    const pixels = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const data = pixels.data;
    const b = opts.brightness * 2.55;
    const c = opts.contrast / 100;
    for (let i = 0; i < data.length; i += 4) {
      let r = data[i], g = data[i + 1], bl = data[i + 2];
      if (opts.grayscale) r = g = bl = r * 0.3 + g * 0.59 + bl * 0.11;
      r = (r - 128) * (1 + c) + 128 + b;
      g = (g - 128) * (1 + c) + 128 + b;
      bl = (bl - 128) * (1 + c) + 128 + b;
      data[i] = clamp(r); data[i + 1] = clamp(g); data[i + 2] = clamp(bl);
    }
    ctx.putImageData(pixels, 0, 0);
  }

  function sharpen(ctx, canvas, amount) {
    const src = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const out = ctx.createImageData(src);
    const w = canvas.width, h = canvas.height;
    const s = src.data, d = out.data;
    const k = Math.min(0.6, amount / 120);
    for (let y = 1; y < h - 1; y++) {
      for (let x = 1; x < w - 1; x++) {
        const i = (y * w + x) * 4;
        for (let c = 0; c < 3; c++) {
          const center = s[i + c];
          const around = s[i - w * 4 + c] + s[i + w * 4 + c] + s[i - 4 + c] + s[i + 4 + c];
          d[i + c] = clamp(center + k * (center * 4 - around));
        }
        d[i + 3] = 255;
      }
    }
    ctx.putImageData(out, 0, 0);
  }

  function rotateCanvas(source, degrees) {
    const canvas = document.createElement("canvas");
    const swap = degrees === 90 || degrees === 270;
    canvas.width = swap ? source.height : source.width;
    canvas.height = swap ? source.width : source.height;
    const ctx = canvas.getContext("2d");
    ctx.translate(canvas.width / 2, canvas.height / 2);
    ctx.rotate((degrees * Math.PI) / 180);
    ctx.drawImage(source, -source.width / 2, -source.height / 2);
    return canvas;
  }

  function clamp(value) { return Math.max(0, Math.min(255, value)); }

  async function renderPreview() {
    if (!state.doc) return;
    const page = await state.doc.getPage(state.page);
    const base = page.getViewport({ scale: 1 });
    const scale = Math.min(1.4, 900 / base.width);
    const viewport = page.getViewport({ scale });
    const before = $("before");
    before.width = viewport.width;
    before.height = viewport.height;
    await page.render({ canvasContext: before.getContext("2d"), viewport }).promise;
    const after = filteredCanvas(before, options());
    const afterCanvas = $("after");
    afterCanvas.width = after.width;
    afterCanvas.height = after.height;
    afterCanvas.getContext("2d").drawImage(after, 0, 0);
    $("page-label").textContent = "Page " + state.page + " of " + state.doc.numPages;
    syncCompare();
  }

  function syncCompare() {
    const before = $("before");
    const after = $("after");
    if (!before || !after || !before.clientWidth) return;
    after.style.width = before.clientWidth + "px";
    after.style.height = before.clientHeight + "px";
  }

  async function openBytes(bytes) {
    pdfjsLib.GlobalWorkerOptions.workerSrc = Quire.path("/assets/vendor/pdfjs/pdf.worker.min.js");
    state.doc = await pdfjsLib.getDocument({ data: bytes }).promise;
    state.page = 1;
    $("viewer").hidden = false;
    await renderPreview();
  }

  async function processInBrowser() {
    if (!state.doc || state.busy) return;
    state.busy = true;
    setBusy(true);
    const opts = options();
    const total = state.doc.numPages;
    try {
      const pdf = await PDFLib.PDFDocument.create();
      const font = await pdf.embedFont(PDFLib.StandardFonts.Helvetica);
      let worker = null;
      if (opts.searchable && window.Tesseract) {
        $("status").textContent = "Loading browser OCR…";
        worker = await Tesseract.createWorker(opts.language || "eng", 1, {
          workerPath: Quire.path("/assets/vendor/tesseract/worker.min.js"),
          corePath: Quire.path("/assets/vendor/tesseract/core"),
          langPath: Quire.path("/assets/vendor/tesseract/lang"),
          workerBlobURL: false,
        });
      }
      for (let number = 1; number <= total; number++) {
        $("status").textContent = "Enhancing page " + number + " of " + total;
        $("bar").style.width = Math.round((number - 1) / total * 100) + "%";
        const page = await state.doc.getPage(number);
        const viewport = page.getViewport({ scale: 1.5 });
        const canvas = document.createElement("canvas");
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        await page.render({ canvasContext: canvas.getContext("2d"), viewport }).promise;
        const filtered = filteredCanvas(canvas, opts);
        const blob = await new Promise((resolve) => filtered.toBlob(resolve, "image/jpeg", 0.84));
        const bytes = new Uint8Array(await blob.arrayBuffer());
        const image = await pdf.embedJpg(bytes);
        const pdfPage = pdf.addPage([image.width, image.height]);
        pdfPage.drawImage(image, { x: 0, y: 0, width: image.width, height: image.height });
        if (worker) {
          const recognized = await worker.recognize(filtered);
          (recognized.data.words || []).forEach((word) => {
            if (!word.text || word.confidence < 40) return;
            try {
              const size = Math.max(6, word.bbox.y1 - word.bbox.y0);
              pdfPage.drawText(word.text, {
                x: word.bbox.x0,
                y: image.height - word.bbox.y1,
                size: size * 0.8,
                font,
                color: PDFLib.rgb(0, 0, 0),
                opacity: 0,
              });
            } catch (error) { /* skip characters the standard font cannot draw */ }
          });
        }
        await new Promise((resolve) => setTimeout(resolve, 0));
      }
      if (worker) await worker.terminate();
      await QuireClean.download(pdf, "enhanced.pdf");
      $("bar").style.width = "100%";
      $("status").textContent = opts.searchable && !window.Tesseract
        ? "Enhanced PDF downloaded. Browser OCR did not load, so there is no text layer."
        : "Enhanced PDF downloaded. The original upload was not changed.";
      Quire.toast("Enhanced PDF downloaded.");
    } catch (error) {
      $("status").textContent = error.message;
      Quire.toast(error.message);
    } finally {
      state.busy = false;
      setBusy(false);
    }
  }

  async function processOnServer() {
    if (!state.fileId || state.busy) return;
    state.busy = true;
    setBusy(true);
    $("status").textContent = "Processing on the server…";
    try {
      const result = await Quire.api("/api/process-pdf.php", { json: Object.assign({ id: state.fileId, csrf: Quire.csrf }, options()) });
      window.location = Quire.path("/api/download.php?id=" + result.file.id);
    } catch (error) {
      $("status").textContent = error.message;
      Quire.toast(error.message);
      state.busy = false;
      setBusy(false);
    }
  }

  function setBusy(busy) {
    document.querySelectorAll("[data-run]").forEach((button) => { button.disabled = busy; });
  }

  document.addEventListener("DOMContentLoaded", async () => {
    pdfjsLib.GlobalWorkerOptions.workerSrc = Quire.path("/assets/vendor/pdfjs/pdf.worker.min.js");
    const caps = await Quire.capabilities();
    const note = $("capability-note");
    const serverBtn = $("server-process");
    if (!caps.ghostscript || !caps.gd) {
      note.hidden = false;
      note.textContent = "Server enhancement is unavailable because Ghostscript or GD is missing. Browser enhancement still runs on this device. Deskew in the browser is approximate.";
      serverBtn.disabled = true;
    } else {
      note.hidden = false;
      note.classList.add("good");
      note.textContent = "Server enhancement is available (Ghostscript and GD). Browser enhancement also works and keeps the page images on this device until you choose server processing.";
    }
    if (!caps.tesseract) {
      $("server-ocr-note").textContent = "Server searchable PDF needs Tesseract. Browser OCR can add a text layer when you process here.";
    }
    if (!caps.ghostscript || !caps.gd) {
      $("deskew").disabled = true;
      $("deskew").checked = false;
      $("deskew-note").textContent = "Deskew needs Ghostscript and GD on the server, so that control is off. The other filters still run in the browser.";
    } else {
      $("deskew-note").textContent = "Deskew runs during server processing. The browser preview does not tilt the page.";
    }
    const langs = caps.browser_ocr_languages || ["eng"];
    $("language").innerHTML = langs.map((lang) => "<option value=\"" + lang + "\">" + lang + "</option>").join("");
    $("level").addEventListener("change", () => { applyLevel($("level").value); renderPreview(); });
    document.querySelectorAll("[data-preview]").forEach((control) => control.addEventListener("input", () => renderPreview()));
    $("prev").addEventListener("click", () => { if (state.page > 1) { state.page -= 1; renderPreview(); } });
    $("next").addEventListener("click", () => { if (state.doc && state.page < state.doc.numPages) { state.page += 1; renderPreview(); } });
    $("split").addEventListener("input", () => { $("after-clip").style.width = $("split").value + "%"; syncCompare(); });
    window.addEventListener("resize", syncCompare);
    $("browser-process").addEventListener("click", processInBrowser);
    serverBtn.addEventListener("click", processOnServer);
    document.addEventListener("quire:file", async (event) => {
      state.fileId = event.detail.meta.id;
      const buffer = await event.detail.raw.arrayBuffer();
      await openBytes(buffer);
    });
    const existing = new URLSearchParams(location.search).get("file");
    if (existing) {
      state.fileId = existing;
      const blob = await Quire.api("/api/download.php?id=" + encodeURIComponent(existing));
      await openBytes(await blob.arrayBuffer());
    }
  });
})();
