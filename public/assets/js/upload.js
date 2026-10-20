(function () {
  function bindDropzone(root, options) {
    const input = root.querySelector('input[type="file"]');
    const status = root.querySelector("[data-status]");
    const accept = options.accept || "";
    if (input && accept) input.accept = accept;

    function setStatus(message) {
      if (status) status.textContent = message;
    }

    async function send(file) {
      if (!file || !window.Quire) return;
      const limit = options.maxBytes || 26 * 1024 * 1024;
      if (file.size > limit) {
        Quire.toast("That file is larger than the upload limit.");
        setStatus("File is too large.");
        return;
      }
      const body = new FormData();
      body.append("file", file);
      body.append("csrf", Quire.csrf);
      body.append("kind", options.kind || "any");
      setStatus("Uploading " + file.name + "…");
      root.classList.add("busy");
      try {
        const result = await Quire.api("/api/upload.php", { body });
        setStatus(result.file.name + " · " + Quire.formatBytes(result.file.size));
        if (options.onFile) options.onFile(result.file, file);
      } catch (error) {
        setStatus(error.message);
        Quire.toast(error.message);
      } finally {
        root.classList.remove("busy");
      }
    }

    input?.addEventListener("change", () => {
      if (input.files && input.files[0]) send(input.files[0]);
    });
    ["dragenter", "dragover"].forEach((name) => root.addEventListener(name, (event) => {
      event.preventDefault();
      root.classList.add("drag");
    }));
    ["dragleave", "drop"].forEach((name) => root.addEventListener(name, (event) => {
      event.preventDefault();
      root.classList.remove("drag");
    }));
    root.addEventListener("drop", (event) => {
      const file = event.dataTransfer?.files?.[0];
      if (file) send(file);
    });
    return { send };
  }

  function homeChooser(file) {
    const box = document.getElementById("home-choice");
    if (!box) return;
    const pdf = file.kind === "pdf";
    const links = pdf
      ? [
          ["/tools/enhance-pdf.php?file=", "Enhance scan"],
          ["/tools/pdf-editor.php?file=", "Edit PDF"],
          ["/tools/unlock-pdf.php?file=", "Check protection"],
        ]
      : [
          ["/tools/image-to-editable-text.php?file=", "Extract text"],
          ["/tools/image-to-pdf-docs.php?file=", "Make a PDF"],
        ];
    box.innerHTML = "<p><strong>" + file.name + "</strong> is ready. Choose a tool.</p>";
    const row = document.createElement("div");
    row.className = "btn-row";
    links.forEach(([href, label]) => {
      const a = document.createElement("a");
      a.className = "btn";
      a.href = Quire.path(href + encodeURIComponent(file.id));
      a.textContent = label;
      row.appendChild(a);
    });
    box.appendChild(row);
    box.hidden = false;
  }

  document.addEventListener("DOMContentLoaded", () => {
    const home = document.getElementById("home-drop");
    if (home) {
      bindDropzone(home, {
        kind: "any",
        accept: ".pdf,.png,.jpg,.jpeg,.webp,.gif,application/pdf,image/*",
        onFile: homeChooser,
      });
    }
    document.querySelectorAll("[data-dropzone]").forEach((root) => {
      const handler = bindDropzone(root, {
        kind: root.dataset.kind || "any",
        accept: root.dataset.accept || "",
        onFile: (meta, raw) => {
          document.dispatchEvent(new CustomEvent("quire:file", { detail: { meta, raw, root } }));
        },
      });
      root._quireDrop = handler;
    });
  });

  window.QuireDrop = { bindDropzone };
})();
