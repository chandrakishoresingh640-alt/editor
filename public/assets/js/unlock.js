(function () {
  const $ = (id) => document.getElementById(id);
  let fileId = null;

  function show(info) {
    $("result").hidden = false;
    if (!info.encrypted) {
      $("result-title").textContent = "This PDF is not encrypted.";
      $("result-copy").textContent = "There is no password to remove. You can download the original file.";
      $("password-wrap").hidden = true;
      $("confirm-wrap").hidden = true;
      $("unlock").hidden = true;
      return;
    }
    if (info.unsupported) {
      $("result-title").textContent = "This protection cannot be changed here.";
      $("result-copy").textContent = info.unsupported;
      $("unlock").disabled = true;
      return;
    }
    const perms = info.permissions || {};
    const labels = Object.entries({
      print: "Print",
      copy: "Copy text",
      modify: "Modify",
      annotate: "Annotate",
    }).map(([key, label]) => label + ": " + (perms[key] ? "allowed" : "restricted"));
    if (info.user_password_required) {
      $("result-title").textContent = "A password is required.";
      $("result-copy").textContent = "Standard security, revision " + (info.revision || "unknown") + ". Enter the password you were given. This tool does not try other passwords. " + labels.join(" · ");
      $("password-wrap").hidden = false;
      $("confirm-wrap").hidden = false;
      $("confirm-label").textContent = "I own this PDF or have permission to remove its password.";
    } else {
      $("result-title").textContent = "This PDF opens without a password.";
      $("result-copy").textContent = "It uses permission flags rather than a secret user password. Removing them does not break encryption that was never applied to opening the file. " + labels.join(" · ");
      $("password-wrap").hidden = true;
      $("confirm-wrap").hidden = false;
      $("confirm-label").textContent = "I own this PDF or have permission to remove its restriction flags.";
    }
    $("unlock").hidden = false;
    $("unlock").disabled = false;
  }

  async function inspect(id) {
    fileId = id;
    $("status").textContent = "Checking protection…";
    try {
      const data = await Quire.api("/api/unlock-pdf.php?action=inspect&id=" + encodeURIComponent(id));
      show(data.info);
      $("status").textContent = "";
    } catch (error) {
      $("status").textContent = error.message;
      Quire.toast(error.message);
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    document.addEventListener("quire:file", (event) => inspect(event.detail.meta.id));
    const existing = new URLSearchParams(location.search).get("file");
    if (existing) inspect(existing);
    $("unlock").addEventListener("click", async () => {
      if (!$("confirm").checked) {
        Quire.toast("Confirm that you are allowed to change this PDF.");
        return;
      }
      $("unlock").disabled = true;
      try {
        const data = await Quire.api("/api/unlock-pdf.php", {
          json: { csrf: Quire.csrf, id: fileId, password: $("password").value, authorized: true },
        });
        $("status").textContent = data.note || "The new PDF is ready. The upload was kept.";
        window.location = Quire.path("/api/download.php?id=" + data.file.id);
      } catch (error) {
        $("status").textContent = error.message;
        Quire.toast(error.message);
        $("unlock").disabled = false;
      }
    });
  });
})();
