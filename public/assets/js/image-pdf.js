(function () {
  const pages = [];
  const $ = (id) => document.getElementById(id);

  function renderList() {
    const list = $("page-list");
    list.innerHTML = "";
    pages.forEach((page, index) => {
      const row = document.createElement("div");
      row.className = "page-chip";
      const img = document.createElement("img");
      img.src = page.url;
      img.alt = "";
      const label = document.createElement("span");
      label.textContent = "Page " + (index + 1);
      const rotate = document.createElement("button");
      rotate.type = "button";
      rotate.className = "btn ghost";
      rotate.textContent = "Rotate";
      rotate.addEventListener("click", () => { page.rotate = (page.rotate + 90) % 360; drawPreview(); });
      const up = document.createElement("button");
      up.type = "button";
      up.className = "btn ghost";
      up.textContent = "Up";
      up.disabled = index === 0;
      up.addEventListener("click", () => { pages.splice(index - 1, 0, pages.splice(index, 1)[0]); renderList(); drawPreview(); });
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "btn ghost";
      remove.textContent = "Remove";
      remove.addEventListener("click", () => { pages.splice(index, 1); renderList(); drawPreview(); });
      row.append(img, label, rotate, up, remove);
      list.appendChild(row);
    });
    $("build").disabled = pages.length === 0;
  }

  function drawPreview() {
    const canvas = $("preview");
    const page = pages[0];
    if (!page) { canvas.hidden = true; return; }
    canvas.hidden = false;
    const image = page.image;
    const angle = page.rotate;
    const swap = angle === 90 || angle === 270;
    const scale = 280 / Math.max(image.width, image.height);
    canvas.width = (swap ? image.height : image.width) * scale;
    canvas.height = (swap ? image.width : image.height) * scale;
    const ctx = canvas.getContext("2d");
    ctx.fillStyle = "white";
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.save();
    ctx.translate(canvas.width / 2, canvas.height / 2);
    ctx.rotate((angle * Math.PI) / 180);
    ctx.drawImage(image, -image.width * scale / 2, -image.height * scale / 2, image.width * scale, image.height * scale);
    ctx.restore();
  }

  function readImage(file) {
    return new Promise((resolve, reject) => {
      const image = new Image();
      const url = URL.createObjectURL(file);
      image.onload = () => resolve({ image, url, rotate: 0, file });
      image.onerror = () => reject(new Error("One of the images could not be previewed."));
      image.src = url;
    });
  }

  async function build() {
    $("build").disabled = true;
    $("status").textContent = "Building PDF…";
    try {
      const pdf = await PDFLib.PDFDocument.create();
      const searchable = $("searchable").checked;
      let font = null;
      let worker = null;
      if (searchable && window.Tesseract) {
        const fontBytes = await fetch(Quire.path("/assets/fonts/DejaVuSans.ttf")).then((response) => response.arrayBuffer());
        font = await pdf.embedFont(fontBytes, { subset: true });
        worker = await Tesseract.createWorker($("language").value || "eng", 1, {
          workerPath: Quire.path("/assets/vendor/tesseract/worker.min.js"),
          corePath: Quire.path("/assets/vendor/tesseract/core"),
          langPath: Quire.path("/assets/vendor/tesseract/lang"),
          workerBlobURL: false,
        });
      }
      const pageSize = $("page-size").value;
      const orientation = $("orientation").value;
      const margin = Number($("margin").value) || 0;
      const sizes = { a4: [595.28, 841.89], letter: [612, 792] };
      for (const source of pages) {
        const angle = source.rotate;
        const swap = angle === 90 || angle === 270;
        const canvas = document.createElement("canvas");
        canvas.width = swap ? source.image.height : source.image.width;
        canvas.height = swap ? source.image.width : source.image.height;
        const ctx = canvas.getContext("2d");
        ctx.fillStyle = "white";
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.save();
        ctx.translate(canvas.width / 2, canvas.height / 2);
        ctx.rotate((angle * Math.PI) / 180);
        ctx.drawImage(source.image, -source.image.width / 2, -source.image.height / 2);
        ctx.restore();
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", 0.86));
        const image = await pdf.embedJpg(new Uint8Array(await blob.arrayBuffer()));
        let pw, ph, x, y, dw, dh;
        if (pageSize === "fit") {
          pw = image.width; ph = image.height; x = 0; y = 0; dw = pw; dh = ph;
        } else {
          [pw, ph] = sizes[pageSize] || sizes.a4;
          if (orientation === "landscape") [pw, ph] = [ph, pw];
          const maxW = pw - margin * 2;
          const maxH = ph - margin * 2;
          const scale = Math.min(maxW / image.width, maxH / image.height);
          dw = image.width * scale;
          dh = image.height * scale;
          x = (pw - dw) / 2;
          y = (ph - dh) / 2;
        }
        const page = pdf.addPage([pw, ph]);
        page.drawImage(image, { x, y, width: dw, height: dh });
        if (worker && font) {
          const recognized = await worker.recognize(canvas);
          const sx = dw / canvas.width;
          const sy = dh / canvas.height;
          (recognized.data.words || []).forEach((word) => {
            if (!word.text || word.confidence < 35) return;
            try {
              const size = Math.max(4, (word.bbox.y1 - word.bbox.y0) * sy * 0.85);
              page.drawText(word.text, {
                x: x + word.bbox.x0 * sx,
                y: y + dh - word.bbox.y1 * sy,
                size,
                font,
                opacity: 0,
              });
            } catch (error) { /* a word may contain a character the subset cannot map */ }
          });
        }
      }
      if (worker) await worker.terminate();
      const bytes = await pdf.save();
      const link = document.createElement("a");
      link.href = URL.createObjectURL(new Blob([bytes], { type: "application/pdf" }));
      link.download = "images.pdf";
      link.click();
      $("status").textContent = searchable
        ? "PDF downloaded. The text layer is invisible and follows the words OCR found. It will not be perfect."
        : "PDF downloaded. It contains the images only.";
    } catch (error) {
      $("status").textContent = error.message;
      Quire.toast(error.message);
    } finally {
      $("build").disabled = pages.length === 0;
    }
  }

  document.addEventListener("DOMContentLoaded", async () => {
    const caps = await Quire.capabilities();
    const langs = caps.browser_ocr_languages || ["eng"];
    $("language").innerHTML = langs.map((lang) => `<option value="${lang}">${lang}</option>`).join("");
    $("capability-note").hidden = false;
    const notes = [];
    notes.push(caps.gd
      ? "Server PDF building is available."
      : "Server PDF building needs the GD image extension. The browser can still build the PDF on this device.");
    notes.push(caps.tesseract
      ? "Server OCR can add a searchable text layer."
      : "Server OCR is not installed. Tick searchable PDF to run OCR in the browser instead.");
    notes.push(caps.google_oauth_configured
      ? "Google Docs export is configured. It sends the text you choose to your own Google account."
      : "Google Docs export is not configured. PDF download still works.");
    $("capability-note").textContent = notes.join(" ");
    $("docs").disabled = !caps.google_oauth_configured;
    $("server-build").disabled = !caps.gd;
    const input = $("images");
    input.addEventListener("change", async () => {
      for (const file of input.files) pages.push(await readImage(file));
      input.value = "";
      renderList();
      drawPreview();
    });
    $("build").addEventListener("click", build);
    $("server-build").addEventListener("click", async () => {
      if (!pages.length) return;
      $("status").textContent = "Uploading images for server PDF…";
      try {
        const ids = [];
        for (const page of pages) {
          const body = new FormData();
          const blob = await new Promise((resolve) => {
            const canvas = document.createElement("canvas");
            canvas.width = page.image.width; canvas.height = page.image.height;
            canvas.getContext("2d").drawImage(page.image, 0, 0);
            canvas.toBlob(resolve, "image/jpeg", 0.9);
          });
          body.append("file", blob, "page.jpg");
          body.append("csrf", Quire.csrf);
          body.append("kind", "image");
          const uploaded = await Quire.api("/api/upload.php", { body });
          ids.push(uploaded.file.id);
        }
        const result = await Quire.api("/api/process-image.php", {
          json: {
            csrf: Quire.csrf,
            action: "pdf",
            ids,
            page_size: $("page-size").value,
            orientation: $("orientation").value,
            margin: Number($("margin").value),
            searchable: $("searchable").checked,
            language: $("language").value,
            rotations: pages.map((page) => page.rotate),
          },
        });
        if (result.notice) Quire.toast(result.notice);
        window.location = Quire.path("/api/download.php?id=" + result.file.id);
      } catch (error) {
        $("status").textContent = error.message;
      }
    });
    const existing = new URLSearchParams(location.search).get("file");
    if (existing) {
      try {
        const blob = await Quire.api("/api/download.php?id=" + encodeURIComponent(existing));
        const file = new File([blob], "upload.jpg", { type: blob.type || "image/jpeg" });
        pages.push(await readImage(file));
        renderList();
        drawPreview();
      } catch (error) {
        Quire.toast(error.message);
      }
    }
    $("docs").addEventListener("click", async () => {
      const text = $("docs-text").value.trim();
      if (!text) { Quire.toast("Paste or extract the text to send to Google Docs."); return; }
      try {
        const data = await Quire.api("/api/export-google-docs.php", { json: { csrf: Quire.csrf, title: "Scanned notes", text } });
        if (data.auth_url) { window.location = data.auth_url; return; }
        window.open(data.url, "_blank", "noopener");
      } catch (error) {
        Quire.toast(error.message);
      }
    });
  });
})();
