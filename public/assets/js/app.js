(function () {
  const base = document.querySelector('meta[name="app-base"]')?.content || "";
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || "";
  const toast = document.getElementById("toast");
  let toastTimer = 0;

  function path(url) {
    if (/^https?:/i.test(url)) return url;
    return base + (url.charAt(0) === "/" ? url : "/" + url);
  }

  function showToast(message) {
    if (!toast) return;
    toast.textContent = message;
    toast.classList.add("show");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove("show"), 4200);
  }

  async function api(url, options) {
    const opts = options || {};
    const headers = Object.assign({ "X-CSRF-Token": csrf, "X-Requested-With": "fetch" }, opts.headers || {});
    let body = opts.body;
    if (opts.json) {
      headers["Content-Type"] = "application/json";
      body = JSON.stringify(opts.json);
    }
    const response = await fetch(path(url), {
      method: opts.method || (body ? "POST" : "GET"),
      headers,
      body,
      credentials: "same-origin",
    });
    const type = response.headers.get("content-type") || "";
    const data = type.includes("application/json") ? await response.json() : await response.blob();
    if (!response.ok) {
      const message = data && data.error ? data.error : "The request failed.";
      const error = new Error(message);
      error.payload = data;
      error.status = response.status;
      throw error;
    }
    return data;
  }

  function formatBytes(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + " MB";
    return Math.max(1, Math.round(bytes / 1024)) + " KB";
  }

  let capabilitiesPromise = null;
  function capabilities() {
    if (!capabilitiesPromise) capabilitiesPromise = api("/api/capabilities.php");
    return capabilitiesPromise;
  }

  document.querySelector(".nav-toggle")?.addEventListener("click", (event) => {
    const button = event.currentTarget;
    const nav = document.getElementById("site-nav");
    const open = nav.classList.toggle("open");
    button.setAttribute("aria-expanded", open ? "true" : "false");
  });

  const search = document.getElementById("tool-search");
  if (search) {
    const cards = Array.from(document.querySelectorAll("[data-tool]"));
    const empty = document.getElementById("search-empty");
    search.addEventListener("input", () => {
      const query = search.value.trim().toLowerCase();
      let shown = 0;
      cards.forEach((card) => {
        const hay = (card.dataset.tool + " " + card.textContent).toLowerCase();
        const match = query === "" || hay.includes(query);
        card.hidden = !match;
        if (match) shown += 1;
      });
      if (empty) empty.hidden = shown !== 0;
    });
  }

  window.Quire = { base, csrf, path, api, toast: showToast, formatBytes, capabilities };
})();
