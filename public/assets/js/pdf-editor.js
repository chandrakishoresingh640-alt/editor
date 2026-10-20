(function () {
  const state = {
    bytes: null,
    doc: null,
    page: 1,
    scale: 1,
    fit: true,
    rotations: {},
    annotations: [],
    selected: null,
    undo: [],
    redo: [],
    tool: "select",
    drawing: null,
  };
  const $ = (id) => document.getElementById(id);

  function snapshot() {
    state.undo.push(JSON.stringify({ annotations: state.annotations, rotations: state.rotations }));
    if (state.undo.length > 40) state.undo.shift();
    state.redo = [];
  }
  function restore(raw) {
    const data = JSON.parse(raw);
    state.annotations = data.annotations;
    state.rotations = data.rotations;
    state.selected = null;
    renderPage();
  }

  function pageRotation(pageNumber) {
    return ((state.rotations[pageNumber] || 0) % 360 + 360) % 360;
  }

  async function openBytes(bytes) {
    state.bytes = bytes.slice(0);
    pdfjsLib.GlobalWorkerOptions.workerSrc = Quire.path("/assets/vendor/pdfjs/pdf.worker.min.js");
    state.doc = await pdfjsLib.getDocument({ data: bytes.slice(0) }).promise;
    state.page = 1;
    $("viewer").hidden = false;
    await renderThumbs();
    await renderPage();
  }

  async function renderThumbs() {
    const host = $("thumbs");
    host.innerHTML = "";
    for (let number = 1; number <= state.doc.numPages; number++) {
      const page = await state.doc.getPage(number);
      const viewport = page.getViewport({ scale: 0.18 });
      const canvas = document.createElement("canvas");
      canvas.width = viewport.width;
      canvas.height = viewport.height;
      await page.render({ canvasContext: canvas.getContext("2d"), viewport }).promise;
      const button = document.createElement("button");
      button.type = "button";
      button.dataset.page = String(number);
      button.appendChild(canvas);
      button.addEventListener("click", () => { state.page = number; renderPage(); });
      host.appendChild(button);
    }
  }

  async function renderPage() {
    const page = await state.doc.getPage(state.page);
    const unscaled = page.getViewport({ scale: 1, rotation: pageRotation(state.page) });
    if (state.fit) {
      const width = $("stage").clientWidth - 32;
      state.scale = Math.max(0.4, Math.min(2.5, width / unscaled.width));
    }
    const viewport = page.getViewport({ scale: state.scale, rotation: pageRotation(state.page) });
    const canvas = $("pdf");
    canvas.width = viewport.width;
    canvas.height = viewport.height;
    await page.render({ canvasContext: canvas.getContext("2d"), viewport }).promise;
    $("frame").style.width = viewport.width + "px";
    $("frame").style.height = viewport.height + "px";
    state.viewport = viewport;
    $("page-label").textContent = state.page + " / " + state.doc.numPages;
    document.querySelectorAll("#thumbs button").forEach((button) => {
      button.classList.toggle("active", Number(button.dataset.page) === state.page);
    });
    drawOverlay();
  }

  function drawOverlay() {
    const svg = $("overlay");
    const viewport = state.viewport;
    svg.setAttribute("viewBox", "0 0 " + viewport.width + " " + viewport.height);
    svg.innerHTML = "";
    const host = $("text-layer");
    host.innerHTML = "";
    state.annotations.filter((item) => item.page === state.page).forEach((item) => {
      if (item.type === "text") {
        const box = document.createElement("div");
        box.className = "text-box" + (state.selected === item.id ? " selected" : "");
        box.contentEditable = "true";
        box.textContent = item.text || "Text";
        box.style.fontSize = (item.fontSize * state.scale) + "px";
        box.style.color = item.color;
        const point = viewport.convertToViewportPoint(item.x, item.y + item.fontSize);
        box.style.left = point[0] + "px";
        box.style.top = point[1] + "px";
        box.style.minWidth = (item.w * state.scale) + "px";
        box.addEventListener("pointerdown", (event) => { event.stopPropagation(); select(item.id); startMove(event, item); });
        box.addEventListener("input", () => { item.text = box.textContent; });
        host.appendChild(box);
        return;
      }
      const node = shapeNode(item, viewport);
      if (node) svg.appendChild(node);
    });
    if (state.drawing && state.drawing.points) svg.appendChild(inkNode(state.drawing, viewport));
  }

  function shapeNode(item, viewport) {
    const el = document.createElementNS("http://www.w3.org/2000/svg", item.type === "ink" || item.type === "line" ? "polyline" : "rect");
    if (item.type === "ink" || item.type === "line") {
      el.setAttribute("points", (item.points || []).map((point) => {
        const view = viewport.convertToViewportPoint(point.x, point.y);
        return view[0] + "," + view[1];
      }).join(" "));
      el.setAttribute("fill", "none");
      el.setAttribute("stroke", item.color || "#1b1714");
      el.setAttribute("stroke-width", String((item.stroke || 2) * state.scale));
      el.setAttribute("stroke-linecap", "round");
    } else {
      const a = viewport.convertToViewportPoint(item.x, item.y + item.h);
      const b = viewport.convertToViewportPoint(item.x + item.w, item.y);
      const x = Math.min(a[0], b[0]);
      const y = Math.min(a[1], b[1]);
      el.setAttribute("x", String(x));
      el.setAttribute("y", String(y));
      el.setAttribute("width", String(Math.abs(b[0] - a[0])));
      el.setAttribute("height", String(Math.abs(b[1] - a[1])));
      const colors = { highlight: "rgba(244, 208, 63, 0.45)", eraser: "white", redact: "rgba(27,23,20,0.85)", rect: "none", ellipse: "none" };
      el.setAttribute("fill", item.type === "rect" ? "none" : (colors[item.type] || "none"));
      el.setAttribute("stroke", item.type === "rect" ? (item.color || "#9a4318") : "none");
      el.setAttribute("stroke-width", "2");
    }
    el.addEventListener("pointerdown", (event) => { event.stopPropagation(); select(item.id); });
    return el;
  }

  function inkNode(item, viewport) { return shapeNode(item, viewport); }

  function select(id) {
    state.selected = id;
    const item = state.annotations.find((entry) => entry.id === id);
    $("selection-note").textContent = item ? (item.type + " selected") : "Nothing selected";
    drawOverlay();
  }

  function startMove(event, item) {
    if (state.tool !== "select") return;
    const origin = state.viewport.convertToPdfPoint(event.offsetX, event.offsetY);
    const start = { x: item.x, y: item.y };
    function move(ev) {
      const point = state.viewport.convertToPdfPoint(ev.offsetX, ev.offsetY);
      item.x = start.x + (point[0] - origin[0]);
      item.y = start.y + (point[1] - origin[1]);
      drawOverlay();
    }
    function up() {
      window.removeEventListener("pointermove", move);
      window.removeEventListener("pointerup", up);
    }
    window.addEventListener("pointermove", move);
    window.addEventListener("pointerup", up);
  }

  function pointerPdf(event) {
    const rect = $("pdf").getBoundingClientRect();
    const x = (event.clientX - rect.left) * ($("pdf").width / rect.width);
    const y = (event.clientY - rect.top) * ($("pdf").height / rect.height);
    const point = state.viewport.convertToPdfPoint(x, y);
    return { x: point[0], y: point[1] };
  }

  function addAnnotation(partial) {
    snapshot();
    const item = Object.assign({ id: crypto.randomUUID(), page: state.page, color: $("color").value, fontSize: Number($("font-size").value) || 18 }, partial);
    state.annotations.push(item);
    select(item.id);
  }

  async function exportPdf() {
    $("save").disabled = true;
    $("status").textContent = "Building the PDF…";
    try {
      const original = await PDFLib.PDFDocument.load(state.bytes);
      const output = await PDFLib.PDFDocument.create();
      const fontName = $("font").value;
      let font;
      if (fontName === "DejaVu") {
        const bytes = await fetch(Quire.path("/assets/fonts/DejaVuSans.ttf")).then((response) => response.arrayBuffer());
        font = await output.embedFont(bytes, { subset: true });
      }
      const standard = {
        Helvetica: PDFLib.StandardFonts.Helvetica,
        Times: PDFLib.StandardFonts.TimesRoman,
        Courier: PDFLib.StandardFonts.Courier,
      };
      if (!font) font = await output.embedFont(standard[fontName] || PDFLib.StandardFonts.Helvetica);
      for (let index = 0; index < original.getPageCount(); index++) {
        const pageNumber = index + 1;
        const redact = state.annotations.some((item) => item.page === pageNumber && (item.type === "redact" || ($("flatten").checked && item.type === "eraser")));
        if (redact) {
          const image = await rasterize(pageNumber);
          const embedded = await output.embedJpg(image.bytes);
          const page = output.addPage([image.width, image.height]);
          page.drawImage(embedded, { x: 0, y: 0, width: image.width, height: image.height });
        } else {
          const [copied] = await output.copyPages(original, [index]);
          const embeddedFont = fontName === "DejaVu" ? await output.embedFont(await fetch(Quire.path("/assets/fonts/DejaVuSans.ttf")).then((r) => r.arrayBuffer()), { subset: true }) : font;
          drawOnPage(copied, pageNumber, embeddedFont);
          const rotation = (copied.getRotation().angle + pageRotation(pageNumber)) % 360;
          copied.setRotation(PDFLib.degrees(rotation));
          output.addPage(copied);
        }
      }
      await QuireClean.download(output, "edited.pdf");
      $("status").textContent = "Edited PDF downloaded. It has no document info or image metadata. White overlay eraser does not delete underlying text unless you chose permanent redaction or flattening.";
      Quire.toast("Edited PDF downloaded.");
    } catch (error) {
      $("status").textContent = error.message;
      Quire.toast(error.message);
    } finally {
      $("save").disabled = false;
    }
  }

  function drawOnPage(page, pageNumber, font) {
    const { rgb } = PDFLib;
    state.annotations.filter((item) => item.page === pageNumber && item.type !== "redact").forEach((item) => {
      const color = hex(item.color || "#1b1714");
      if (item.type === "text") {
        page.drawText(item.text || "", { x: item.x, y: item.y, size: item.fontSize || 18, font, color: rgb(color.r, color.g, color.b) });
      } else if (item.type === "highlight") {
        page.drawRectangle({ x: item.x, y: item.y, width: item.w, height: item.h, color: rgb(0.98, 0.85, 0.25), opacity: 0.4 });
      } else if (item.type === "eraser") {
        page.drawRectangle({ x: item.x, y: item.y, width: item.w, height: item.h, color: rgb(1, 1, 1) });
      } else if (item.type === "rect") {
        page.drawRectangle({ x: item.x, y: item.y, width: item.w, height: item.h, borderColor: rgb(color.r, color.g, color.b), borderWidth: 2 });
      } else if (item.type === "line" && item.points?.length === 2) {
        page.drawLine({ start: item.points[0], end: item.points[1], thickness: item.stroke || 2, color: rgb(color.r, color.g, color.b) });
      } else if (item.type === "ink") {
        const points = item.points || [];
        for (let i = 1; i < points.length; i++) {
          page.drawLine({ start: points[i - 1], end: points[i], thickness: item.stroke || 2, color: rgb(color.r, color.g, color.b) });
        }
      }
    });
  }

  async function rasterize(pageNumber) {
    const page = await state.doc.getPage(pageNumber);
    const viewport = page.getViewport({ scale: 2, rotation: pageRotation(pageNumber) });
    const canvas = document.createElement("canvas");
    canvas.width = viewport.width;
    canvas.height = viewport.height;
    const ctx = canvas.getContext("2d");
    await page.render({ canvasContext: ctx, viewport }).promise;
    state.annotations.filter((item) => item.page === pageNumber).forEach((item) => {
      const a = viewport.convertToViewportPoint(item.x, item.y + (item.h || item.fontSize || 0));
      const b = viewport.convertToViewportPoint(item.x + (item.w || 80), item.y);
      const x = Math.min(a[0], b[0]);
      const y = Math.min(a[1], b[1]);
      const w = Math.abs(b[0] - a[0]);
      const h = Math.abs(b[1] - a[1]);
      if (item.type === "redact" || item.type === "eraser") {
        ctx.fillStyle = "white";
        ctx.fillRect(x, y, w, h);
      } else if (item.type === "highlight") {
        ctx.fillStyle = "rgba(244,208,63,0.45)";
        ctx.fillRect(x, y, w, h);
      } else if (item.type === "text") {
        ctx.fillStyle = item.color || "#1b1714";
        ctx.font = ((item.fontSize || 18) * 2) + "px sans-serif";
        ctx.fillText(item.text || "", a[0], a[1] + (item.fontSize || 18) * 2);
      } else if (item.type === "rect") {
        ctx.strokeStyle = item.color || "#9a4318";
        ctx.lineWidth = 3;
        ctx.strokeRect(x, y, w, h);
      } else if ((item.type === "line" || item.type === "ink") && item.points?.length) {
        ctx.strokeStyle = item.color || "#1b1714";
        ctx.lineWidth = (item.stroke || 2) * 2;
        ctx.lineCap = "round";
        ctx.beginPath();
        item.points.forEach((point, index) => {
          const view = viewport.convertToViewportPoint(point.x, point.y);
          if (index === 0) ctx.moveTo(view[0], view[1]);
          else ctx.lineTo(view[0], view[1]);
        });
        ctx.stroke();
      }
    });
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", 0.86));
    return { bytes: new Uint8Array(await blob.arrayBuffer()), width: viewport.width / 2, height: viewport.height / 2 };
  }

  function hex(value) {
    const raw = value.replace("#", "");
    return {
      r: parseInt(raw.slice(0, 2), 16) / 255,
      g: parseInt(raw.slice(2, 4), 16) / 255,
      b: parseInt(raw.slice(4, 6), 16) / 255,
    };
  }

  async function textToBoxes() {
    const page = await state.doc.getPage(state.page);
    const content = await page.getTextContent();
    if (!content.items.length) {
      Quire.toast("This page has no embedded text. Use OCR page for a scan.");
      return;
    }
    snapshot();
    content.items.forEach((item) => {
      if (!item.str || !item.str.trim()) return;
      const x = item.transform[4];
      const y = item.transform[5];
      state.annotations.push({
        id: crypto.randomUUID(),
        type: "text",
        page: state.page,
        x, y,
        w: item.width || 80,
        h: item.height || 14,
        text: item.str,
        fontSize: Math.max(8, item.height || 12),
        color: "#1b1714",
      });
    });
    $("status").textContent = "Text boxes were copied from the PDF’s text layer. Export with “replace this page” off keeps the original underneath. Use permanent redaction over the old text if you need it gone.";
    drawOverlay();
  }

  async function ocrPage() {
    $("status").textContent = "Reading this page in the browser…";
    const page = await state.doc.getPage(state.page);
    const viewport = page.getViewport({ scale: 2 });
    const canvas = document.createElement("canvas");
    canvas.width = viewport.width;
    canvas.height = viewport.height;
    await page.render({ canvasContext: canvas.getContext("2d"), viewport }).promise;
    const worker = await Tesseract.createWorker("eng", 1, {
      workerPath: Quire.path("/assets/vendor/tesseract/worker.min.js"),
      corePath: Quire.path("/assets/vendor/tesseract/core"),
      langPath: Quire.path("/assets/vendor/tesseract/lang"),
      workerBlobURL: false,
    });
    const recognized = await worker.recognize(canvas);
    await worker.terminate();
    snapshot();
    const base = page.getViewport({ scale: 1 });
    (recognized.data.lines || []).forEach((line) => {
      const text = line.text.trim();
      if (!text) return;
      state.annotations.push({
        id: crypto.randomUUID(),
        type: "text",
        page: state.page,
        x: line.bbox.x0 / 2,
        y: base.height - line.bbox.y1 / 2,
        w: (line.bbox.x1 - line.bbox.x0) / 2,
        h: (line.bbox.y1 - line.bbox.y0) / 2,
        text,
        fontSize: Math.max(8, (line.bbox.y1 - line.bbox.y0) / 2),
        color: "#1b1714",
      });
    });
    drawOverlay();
    $("status").textContent = "OCR text boxes were added. They are an estimate, not a perfect copy of the scan.";
  }

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("[data-tool]").forEach((button) => button.addEventListener("click", () => {
      state.tool = button.dataset.tool;
      document.querySelectorAll("[data-tool]").forEach((other) => other.classList.toggle("secondary", other !== button));
      button.classList.remove("secondary");
    }));
    $("overlay").addEventListener("pointerdown", (event) => {
      if (state.tool === "select") { select(null); return; }
      const point = pointerPdf(event);
      if (state.tool === "text") {
        addAnnotation({ type: "text", x: point.x, y: point.y, w: 140, h: Number($("font-size").value) || 18, text: "Text" });
        return;
      }
      if (state.tool === "ink") {
        snapshot();
        state.drawing = { id: crypto.randomUUID(), type: "ink", page: state.page, points: [point], color: $("color").value, stroke: 2 };
        return;
      }
      state.drawing = { id: crypto.randomUUID(), type: state.tool, page: state.page, x: point.x, y: point.y, w: 0, h: 0, points: [point], color: $("color").value };
    });
    $("overlay").addEventListener("pointermove", (event) => {
      if (!state.drawing) return;
      const point = pointerPdf(event);
      if (state.drawing.type === "ink") state.drawing.points.push(point);
      else if (state.drawing.type === "line") state.drawing.points = [state.drawing.points[0], point];
      else {
        state.drawing.w = point.x - state.drawing.x;
        state.drawing.h = point.y - state.drawing.y;
      }
      drawOverlay();
    });
    window.addEventListener("pointerup", () => {
      if (!state.drawing) return;
      const item = state.drawing;
      state.drawing = null;
      if (item.type !== "ink") snapshot();
      if (item.type !== "ink" && item.type !== "line") {
        if (item.w < 0) { item.x += item.w; item.w = Math.abs(item.w); }
        if (item.h < 0) { item.y += item.h; item.h = Math.abs(item.h); }
      }
      state.annotations.push(item);
      select(item.id);
    });
    $("prev").addEventListener("click", () => { if (state.page > 1) { state.page -= 1; renderPage(); } });
    $("next").addEventListener("click", () => { if (state.doc && state.page < state.doc.numPages) { state.page += 1; renderPage(); } });
    $("zoom-in").addEventListener("click", () => { state.fit = false; state.scale = Math.min(3, state.scale + 0.2); renderPage(); });
    $("zoom-out").addEventListener("click", () => { state.fit = false; state.scale = Math.max(0.4, state.scale - 0.2); renderPage(); });
    $("fit").addEventListener("click", () => { state.fit = true; renderPage(); });
    $("rotate").addEventListener("click", () => { state.rotations[state.page] = pageRotation(state.page) + 90; renderPage(); });
    $("undo").addEventListener("click", () => { const raw = state.undo.pop(); if (raw) { state.redo.push(JSON.stringify({ annotations: state.annotations, rotations: state.rotations })); restore(raw); } });
    $("redo").addEventListener("click", () => { const raw = state.redo.pop(); if (raw) { state.undo.push(JSON.stringify({ annotations: state.annotations, rotations: state.rotations })); restore(raw); } });
    $("delete").addEventListener("click", () => {
      if (!state.selected) return;
      snapshot();
      state.annotations = state.annotations.filter((item) => item.id !== state.selected);
      state.selected = null;
      drawOverlay();
    });
    $("reset").addEventListener("click", () => {
      if (!confirm("Remove all edits on this document?")) return;
      snapshot();
      state.annotations = [];
      state.rotations = {};
      renderPage();
    });
    $("save").addEventListener("click", exportPdf);
    $("original").addEventListener("click", () => {
      if (!state.bytes) return;
      const link = document.createElement("a");
      link.href = URL.createObjectURL(new Blob([state.bytes], { type: "application/pdf" }));
      link.download = "original.pdf";
      link.click();
    });
    $("to-boxes").addEventListener("click", textToBoxes);
    $("ocr-page").addEventListener("click", () => ocrPage().catch((error) => Quire.toast(error.message)));
    window.addEventListener("keydown", (event) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "z") {
        event.preventDefault();
        (event.shiftKey ? $("redo") : $("undo")).click();
      }
      if (event.key === "Delete" && state.selected && document.activeElement === document.body) $("delete").click();
    });
    document.addEventListener("quire:file", async (event) => {
      await openBytes(await event.detail.raw.arrayBuffer());
    });
    const existing = new URLSearchParams(location.search).get("file");
    if (existing) {
      Quire.api("/api/download.php?id=" + encodeURIComponent(existing)).then(async (blob) => openBytes(await blob.arrayBuffer())).catch((error) => Quire.toast(error.message));
    }
  });
})();
