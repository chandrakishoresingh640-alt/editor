document.getElementById("contact-form")?.addEventListener("submit", async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  const status = document.getElementById("contact-status");
  const body = new FormData(form);
  try {
    const response = await fetch(form.action, {
      method: "POST",
      body,
      headers: { "X-Requested-With": "fetch", "X-CSRF-Token": body.get("csrf") },
    });
    const data = await response.json();
    status.textContent = data.message || data.error || "Sent.";
    if (response.ok) form.reset();
  } catch (error) {
    status.textContent = "The message could not be sent.";
  }
});
