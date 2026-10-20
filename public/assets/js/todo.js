(function () {
  const KEY = "quire.todos.v1";
  const COLORS = ["terra", "green", "lavender", "ink"];
  const PRIORITY = { high: 0, medium: 1, low: 2 };
  const $ = (id) => document.getElementById(id);

  const ui = { filter: "open", sort: "manual", query: "", undo: null, undoTimer: 0, dragId: null };

  function uid() {
    return crypto.randomUUID();
  }

  function todayISO() {
    const date = new Date();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");
    return date.getFullYear() + "-" + month + "-" + day;
  }

  function shift(iso, days) {
    const [year, month, day] = iso.split("-").map(Number);
    const date = new Date(year, month - 1, day);
    date.setDate(date.getDate() + days);
    const m = String(date.getMonth() + 1).padStart(2, "0");
    const d = String(date.getDate()).padStart(2, "0");
    return date.getFullYear() + "-" + m + "-" + d;
  }

  function fresh() {
    const id = uid();
    return { lists: [{ id, name: "Inbox", color: "terra" }], tasks: [], activeList: id };
  }

  function load() {
    try {
      const parsed = JSON.parse(localStorage.getItem(KEY) || "");
      if (!parsed || !Array.isArray(parsed.lists) || !Array.isArray(parsed.tasks)) return fresh();
      return normalize(parsed);
    } catch (error) {
      return fresh();
    }
  }

  function normalize(data) {
    data.lists = data.lists.filter((list) => list && list.id && list.name).map((list) => ({
      id: String(list.id),
      name: String(list.name).slice(0, 40),
      color: COLORS.includes(list.color) ? list.color : "terra",
    }));
    if (!data.lists.length) return fresh();
    const ids = new Set(data.lists.map((list) => list.id));
    data.tasks = data.tasks.filter((task) => task && ids.has(task.listId)).map((task, index) => ({
      id: String(task.id || uid()),
      listId: String(task.listId),
      title: String(task.title || "Untitled").slice(0, 180),
      notes: String(task.notes || "").slice(0, 2000),
      priority: PRIORITY[task.priority] !== undefined ? task.priority : "medium",
      due: /^\d{4}-\d{2}-\d{2}$/.test(task.due || "") ? task.due : "",
      starred: Boolean(task.starred),
      done: Boolean(task.done),
      doneOn: task.doneOn || "",
      order: Number.isFinite(task.order) ? task.order : index,
      subtasks: Array.isArray(task.subtasks) ? task.subtasks.filter((sub) => sub && sub.title).map((sub) => ({
        id: String(sub.id || uid()),
        title: String(sub.title).slice(0, 140),
        done: Boolean(sub.done),
      })) : [],
    }));
    if (data.activeList !== "all" && !ids.has(data.activeList)) data.activeList = data.lists[0].id;
    return data;
  }

  const state = load();

  function save() {
    try {
      localStorage.setItem(KEY, JSON.stringify(state));
    } catch (error) {
      Quire.toast("This browser could not store the list.");
    }
  }

  function listById(id) {
    return state.lists.find((list) => list.id === id);
  }

  function tasksIn(listId) {
    const pool = listId === "all" ? state.tasks.slice() : state.tasks.filter((task) => task.listId === listId);
    return pool.sort((a, b) => a.order - b.order);
  }

  function dueLabel(iso) {
    if (!iso) return "";
    const today = todayISO();
    if (iso === today) return "Today";
    if (iso === shift(today, 1)) return "Tomorrow";
    if (iso < today) return "Overdue";
    const [year, month, day] = iso.split("-").map(Number);
    return new Date(year, month - 1, day).toLocaleDateString(undefined, { month: "short", day: "numeric" });
  }

  function matches(task) {
    const today = todayISO();
    if (ui.filter === "open" && task.done) return false;
    if (ui.filter === "done" && !task.done) return false;
    if (ui.filter === "today" && (task.done || task.due !== today)) return false;
    if (ui.filter === "upcoming" && (task.done || !task.due || task.due <= today)) return false;
    if (ui.filter === "overdue" && (task.done || !task.due || task.due >= today)) return false;
    if (ui.filter === "starred" && (!task.starred || task.done)) return false;
    if (ui.query) {
      const hay = (task.title + " " + task.notes + " " + task.subtasks.map((sub) => sub.title).join(" ")).toLowerCase();
      if (!hay.includes(ui.query)) return false;
    }
    return true;
  }

  function visible() {
    const items = tasksIn(state.activeList).filter(matches);
    if (ui.sort === "due") {
      items.sort((a, b) => (a.due || "9999").localeCompare(b.due || "9999") || a.order - b.order);
    } else if (ui.sort === "priority") {
      items.sort((a, b) => PRIORITY[a.priority] - PRIORITY[b.priority] || a.order - b.order);
    }
    return items;
  }

  function greeting() {
    const hour = new Date().getHours();
    if (hour < 12) return "Good morning";
    if (hour < 17) return "Good afternoon";
    return "Good evening";
  }

  function renderLists() {
    const host = $("lists");
    host.innerHTML = "";
    const all = document.createElement("div");
    all.className = "list-row" + (state.activeList === "all" ? " active" : "");
    all.setAttribute("role", "listitem");
    const allButton = document.createElement("button");
    allButton.type = "button";
    allButton.className = "list-pick";
    allButton.textContent = "All lists";
    const allCount = document.createElement("span");
    allCount.className = "count";
    allCount.textContent = String(state.tasks.filter((task) => !task.done).length);
    allButton.appendChild(allCount);
    allButton.addEventListener("click", () => { state.activeList = "all"; save(); render(); });
    all.appendChild(allButton);
    host.appendChild(all);

    state.lists.forEach((list) => {
      const row = document.createElement("div");
      row.className = "list-row" + (state.activeList === list.id ? " active" : "");
      row.setAttribute("role", "listitem");
      const pick = document.createElement("button");
      pick.type = "button";
      pick.className = "list-pick";
      const dot = document.createElement("span");
      dot.className = "dot " + list.color;
      const name = document.createElement("span");
      name.textContent = list.name;
      const count = document.createElement("span");
      count.className = "count";
      count.textContent = String(state.tasks.filter((task) => task.listId === list.id && !task.done).length);
      pick.append(dot, name, count);
      pick.addEventListener("click", () => { state.activeList = list.id; save(); render(); });
      pick.addEventListener("dblclick", (event) => {
        event.preventDefault();
        const input = document.createElement("input");
        input.className = "rename-input";
        input.name = "list-name";
        input.setAttribute("aria-label", "List name");
        input.value = list.name;
        input.maxLength = 40;
        row.replaceChildren(input);
        input.focus();
        const commit = () => {
          const next = input.value.trim();
          if (next) list.name = next;
          save();
          render();
        };
        input.addEventListener("blur", commit);
        input.addEventListener("keydown", (key) => {
          if (key.key === "Enter") input.blur();
          if (key.key === "Escape") { input.value = list.name; input.blur(); }
        });
      });
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "btn ghost";
      remove.textContent = "Delete";
      remove.addEventListener("click", () => deleteList(list.id));
      row.append(pick, remove);
      host.appendChild(row);
    });

    const select = $("composer-list");
    const previous = select.value;
    select.innerHTML = "";
    state.lists.forEach((list) => {
      const option = document.createElement("option");
      option.value = list.id;
      option.textContent = list.name;
      select.appendChild(option);
    });
    const preferred = state.activeList !== "all" && listById(state.activeList) ? state.activeList : (previous || state.lists[0].id);
    select.value = listById(preferred) ? preferred : state.lists[0].id;
  }

  function renderHead() {
    const list = state.activeList === "all" ? null : listById(state.activeList);
    $("hello").textContent = greeting() + ". " + new Date().toLocaleDateString(undefined, { weekday: "long", month: "long", day: "numeric" }) + ".";
    $("list-kicker").textContent = list ? list.name : "Every list";
    const labels = { open: "Open tasks", today: "Due today", upcoming: "Coming up", overdue: "Overdue", starred: "Starred", done: "Completed", all: "Everything" };
    $("list-title").textContent = labels[ui.filter] || "Tasks";
    const pool = tasksIn(state.activeList);
    const done = pool.filter((task) => task.done).length;
    const finishedToday = state.tasks.filter((task) => task.done && task.doneOn === todayISO()).length;
    $("list-meta").textContent = done + " of " + pool.length + " finished in this view. " + finishedToday + " finished today.";
    const pct = pool.length ? Math.round((done / pool.length) * 100) : 0;
    const length = 2 * Math.PI * 15.5;
    $("ring").setAttribute("stroke-dasharray", (pct / 100) * length + " " + length);
    $("ring-label").textContent = pct + "%";
  }

  function chip(text, kind) {
    const span = document.createElement("span");
    span.className = "chip" + (kind ? " " + kind : "");
    span.textContent = text;
    return span;
  }

  function renderTasks() {
    const host = $("tasks");
    host.innerHTML = "";
    const items = visible();
    const empty = $("empty");
    const suggestions = $("suggestions");
    suggestions.innerHTML = "";
    if (!items.length) {
      const pool = tasksIn(state.activeList);
      empty.hidden = false;
      empty.textContent = pool.length
        ? "Nothing matches this filter. Try Open or All."
        : "This list is clear. Add a task above, or start from a suggestion.";
      if (!pool.length && !ui.query) {
        suggestions.hidden = false;
        [
          ["Sort the scans that need a cleaner copy", "high"],
          ["Collect passwords I am allowed to use", "medium"],
          ["Paste the lecture transcript", "low"],
        ].forEach(([title, priority]) => {
          const button = document.createElement("button");
          button.type = "button";
          button.className = "btn ghost";
          button.textContent = title;
          button.addEventListener("click", () => addTask(title, priority, "", ""));
          suggestions.appendChild(button);
        });
      } else {
        suggestions.hidden = true;
      }
      return;
    }
    empty.hidden = true;
    suggestions.hidden = true;
    items.forEach((task) => host.appendChild(taskNode(task)));
  }

  function taskNode(task) {
    const item = document.createElement("li");
    item.className = "task " + task.priority + (task.done ? " done" : "");
    item.dataset.id = task.id;
    item.draggable = ui.sort === "manual";

    const top = document.createElement("div");
    top.className = "task-top";
    const handle = document.createElement("button");
    handle.type = "button";
    handle.className = "drag";
    handle.textContent = "⋮⋮";
    handle.title = "Drag to reorder";
    handle.tabIndex = -1;
    const check = document.createElement("input");
    check.type = "checkbox";
    check.className = "check";
    check.checked = task.done;
    check.setAttribute("aria-label", "Mark done: " + task.title);
    check.addEventListener("change", () => {
      task.done = check.checked;
      task.doneOn = task.done ? todayISO() : "";
      save();
      render();
    });
    const title = document.createElement("input");
    title.className = "task-title";
    title.name = "title";
    title.value = task.title;
    title.maxLength = 180;
    title.setAttribute("aria-label", "Task title");
    title.addEventListener("change", () => {
      task.title = title.value.trim() || task.title;
      title.value = task.title;
      save();
    });
    top.append(handle, check, title);

    const meta = document.createElement("div");
    meta.className = "task-meta";
    if (state.activeList === "all") {
      const list = listById(task.listId);
      if (list) meta.appendChild(chip(list.name));
    }
    meta.appendChild(chip(task.priority[0].toUpperCase() + task.priority.slice(1)));
    if (task.due) {
      const kind = task.due < todayISO() ? "overdue" : (task.due === todayISO() ? "today" : "");
      meta.appendChild(chip(dueLabel(task.due), kind));
    }
    if (task.starred) meta.appendChild(chip("Starred", "star"));
    const openSubs = task.subtasks.filter((sub) => !sub.done).length;
    if (task.subtasks.length) meta.appendChild(chip(openSubs + (openSubs === 1 ? " step left" : " steps left")));

    const tools = document.createElement("div");
    tools.className = "task-tools";
    tools.append(
      action("Star", () => { task.starred = !task.starred; save(); render(); }),
      action("Up", () => move(task, -1)),
      action("Down", () => move(task, 1)),
      action("Steps", () => togglePanel(task.id)),
      action("Delete", () => removeTask(task.id))
    );

    const panel = document.createElement("div");
    panel.hidden = ui.openId !== task.id;
    const notes = document.createElement("textarea");
    notes.className = "sub-title";
    notes.name = "notes";
    notes.maxLength = 2000;
    notes.placeholder = "Notes";
    notes.setAttribute("aria-label", "Notes");
    notes.value = task.notes;
    notes.addEventListener("change", () => { task.notes = notes.value; save(); });
    const subs = document.createElement("div");
    subs.className = "subtasks";
    task.subtasks.forEach((sub, index) => {
      const row = document.createElement("div");
      row.className = "sub-row";
      const box = document.createElement("input");
      box.type = "checkbox";
      box.name = "step-done";
      box.checked = sub.done;
      box.setAttribute("aria-label", "Step done");
      box.addEventListener("change", () => { sub.done = box.checked; save(); render(); });
      const field = document.createElement("input");
      field.className = "sub-title";
      field.name = "step";
      field.setAttribute("aria-label", "Step");
      field.value = sub.title;
      field.maxLength = 140;
      field.addEventListener("change", () => { sub.title = field.value.trim() || sub.title; field.value = sub.title; save(); });
      const del = action("Remove", () => { task.subtasks.splice(index, 1); save(); render(); });
      row.append(box, field, del);
      subs.appendChild(row);
    });
    const addSub = document.createElement("form");
    addSub.className = "sub-row";
    const subInput = document.createElement("input");
    subInput.className = "sub-title";
    subInput.name = "new-step";
    subInput.setAttribute("aria-label", "New step");
    subInput.placeholder = "Add a step";
    subInput.maxLength = 140;
    const subButton = document.createElement("button");
    subButton.className = "btn ghost";
    subButton.type = "submit";
    subButton.textContent = "Add step";
    addSub.append(subInput, subButton);
    addSub.addEventListener("submit", (event) => {
      event.preventDefault();
      const text = subInput.value.trim();
      if (!text) return;
      task.subtasks.push({ id: uid(), title: text, done: false });
      save();
      render();
    });
    panel.append(notes, subs, addSub);

    item.append(top, meta, tools, panel);
    item.addEventListener("dragstart", () => { ui.dragId = task.id; item.classList.add("dragging"); });
    item.addEventListener("dragend", () => { ui.dragId = null; item.classList.remove("dragging"); });
    item.addEventListener("dragover", (event) => {
      if (!ui.dragId || ui.sort !== "manual") return;
      event.preventDefault();
    });
    item.addEventListener("drop", (event) => {
      event.preventDefault();
      if (!ui.dragId || ui.dragId === task.id || task.listId !== taskById(ui.dragId)?.listId) return;
      reorder(task.listId, ui.dragId, task.id);
    });
    return item;
  }

  function action(label, handler) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "btn ghost";
    button.textContent = label;
    button.addEventListener("click", handler);
    return button;
  }

  function taskById(id) {
    return state.tasks.find((task) => task.id === id);
  }

  function togglePanel(id) {
    ui.openId = ui.openId === id ? null : id;
    renderTasks();
  }

  function move(task, direction) {
    ui.sort = "manual";
    $("sort").value = "manual";
    const items = tasksIn(task.listId);
    const index = items.findIndex((item) => item.id === task.id);
    const next = index + direction;
    if (next < 0 || next >= items.length) return;
    reorder(task.listId, task.id, items[next].id);
  }

  function reorder(listId, fromId, toId) {
    const items = tasksIn(listId);
    const from = items.findIndex((task) => task.id === fromId);
    const to = items.findIndex((task) => task.id === toId);
    if (from < 0 || to < 0) return;
    const [moved] = items.splice(from, 1);
    items.splice(to, 0, moved);
    items.forEach((task, index) => { task.order = index; });
    save();
    render();
  }

  function addTask(title, priority, due, notes) {
    const listId = $("composer-list").value || state.lists[0].id;
    const order = tasksIn(listId).reduce((max, task) => Math.max(max, task.order), -1) + 1;
    state.tasks.push({
      id: uid(),
      listId,
      title,
      notes: notes || "",
      priority: priority || "medium",
      due: due || "",
      starred: false,
      done: false,
      doneOn: "",
      order,
      subtasks: [],
    });
    if (state.activeList !== "all") state.activeList = listId;
    save();
    render();
  }

  function snapshot() {
    return JSON.stringify({ lists: state.lists, tasks: state.tasks, activeList: state.activeList });
  }

  function removeTask(id) {
    const index = state.tasks.findIndex((task) => task.id === id);
    if (index < 0) return;
    const before = snapshot();
    const removed = state.tasks[index];
    state.tasks.splice(index, 1);
    showUndo("“" + removed.title + "” was removed.", before);
    save();
    render();
  }

  function deleteList(id) {
    if (!confirm("Delete this list and the tasks inside it?")) return;
    const before = snapshot();
    state.lists = state.lists.filter((list) => list.id !== id);
    state.tasks = state.tasks.filter((task) => task.listId !== id);
    if (!state.lists.length) {
      const created = fresh();
      state.lists = created.lists;
      state.activeList = created.activeList;
    } else if (state.activeList === id) {
      state.activeList = state.lists[0].id;
    }
    showUndo("The list was deleted.", before);
    save();
    render();
  }

  function showUndo(text, before) {
    ui.undo = before;
    $("undo-text").textContent = text;
    $("undo").hidden = false;
    clearTimeout(ui.undoTimer);
    ui.undoTimer = setTimeout(() => {
      ui.undo = null;
      $("undo").hidden = true;
    }, 20000);
  }

  function restoreUndo() {
    if (!ui.undo) return;
    const data = JSON.parse(ui.undo);
    state.lists = data.lists;
    state.tasks = data.tasks;
    state.activeList = data.activeList;
    ui.undo = null;
    ui.filter = "all";
    $("undo").hidden = true;
    save();
    render();
    Quire.toast("Restored.");
  }

  function exportText() {
    const lines = [];
    const groups = state.activeList === "all" ? state.lists : [listById(state.activeList)].filter(Boolean);
    groups.forEach((list) => {
      lines.push(list.name);
      tasksIn(list.id).forEach((task) => {
        const mark = task.done ? "x" : " ";
        const extra = [task.priority, task.due ? dueLabel(task.due) : ""].filter(Boolean).join(", ");
        lines.push("- [" + mark + "] " + task.title + (extra ? " (" + extra + ")" : ""));
        if (task.notes) lines.push("  " + task.notes);
        task.subtasks.forEach((sub) => lines.push("  - [" + (sub.done ? "x" : " ") + "] " + sub.title));
      });
      lines.push("");
    });
    return lines.join("\n").trim();
  }

  function render() {
    renderLists();
    renderHead();
    renderTasks();
    document.querySelectorAll("[data-filter]").forEach((button) => {
      button.setAttribute("aria-pressed", button.dataset.filter === ui.filter ? "true" : "false");
    });
  }

  document.addEventListener("DOMContentLoaded", () => {
    render();
    $("composer").addEventListener("submit", (event) => {
      event.preventDefault();
      const title = $("task-title").value.trim();
      if (!title) return;
      addTask(title, $("task-priority").value, $("task-due").value, $("task-note").value.trim());
      $("task-title").value = "";
      $("task-due").value = "";
      $("task-note").value = "";
      $("task-note").hidden = true;
      $("task-title").focus();
    });
    $("toggle-note").addEventListener("click", () => {
      $("task-note").hidden = !$("task-note").hidden;
      if (!$("task-note").hidden) $("task-note").focus();
    });
    $("new-list").addEventListener("submit", (event) => {
      event.preventDefault();
      const name = $("list-name").value.trim();
      if (!name) return;
      const id = uid();
      state.lists.push({ id, name, color: COLORS[state.lists.length % COLORS.length] });
      state.activeList = id;
      $("list-name").value = "";
      save();
      render();
    });
    document.querySelectorAll("[data-filter]").forEach((button) => {
      button.addEventListener("click", () => { ui.filter = button.dataset.filter; render(); });
    });
    $("task-search").addEventListener("input", () => { ui.query = $("task-search").value.trim().toLowerCase(); renderTasks(); });
    $("sort").addEventListener("change", () => { ui.sort = $("sort").value; renderTasks(); });
    $("clear-done").addEventListener("click", () => {
      const gone = state.tasks.filter((task) => task.done && (state.activeList === "all" || task.listId === state.activeList));
      if (!gone.length) { Quire.toast("There are no completed tasks to clear."); return; }
      if (!confirm("Remove " + gone.length + " completed task" + (gone.length === 1 ? "" : "s") + "?")) return;
      const before = snapshot();
      const ids = new Set(gone.map((task) => task.id));
      state.tasks = state.tasks.filter((task) => !ids.has(task.id));
      showUndo("Completed tasks were cleared.", before);
      save();
      render();
    });
    $("export-txt").addEventListener("click", () => {
      const blob = new Blob([exportText()], { type: "text/plain" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.download = "quire-list.txt";
      link.click();
    });
    $("export-pdf").addEventListener("click", async () => {
      const text = exportText();
      if (!text) { Quire.toast("Add a task before exporting."); return; }
      try {
        const data = await Quire.api("/api/export-text-pdf.php", { json: { csrf: Quire.csrf, text, name: "quire-list.pdf" } });
        window.location = Quire.path("/api/download.php?id=" + data.file.id);
      } catch (error) {
        Quire.toast(error.message);
      }
    });
    $("undo-btn").addEventListener("click", restoreUndo);
  });
})();
