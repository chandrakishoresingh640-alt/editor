<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/layout.php';

render_header('Lists', 'lists');
tool_intro(
    'On this device',
    'A list for the work in front of you.',
    'Keep several lists, with priorities, due dates, notes, and subtasks. Nothing is uploaded until you choose to download a file.'
);
?>
<section class="section">
  <div class="wrap todo-shell">
    <aside class="card pad todo-side">
      <h2>Lists</h2>
      <p id="hello" class="hint"></p>
      <div id="lists" class="list-nav" role="list"></div>
      <form id="new-list" class="new-list">
        <label class="sr-only" for="list-name">New list name</label>
        <input id="list-name" type="text" maxlength="40" placeholder="New list" autocomplete="off">
        <button class="btn" type="submit">Add</button>
      </form>
    </aside>
    <div class="todo-main">
      <div class="card pad todo-head">
        <div>
          <p class="kicker" id="list-kicker">Today</p>
          <h2 id="list-title">Open tasks</h2>
          <p id="list-meta" class="hint"></p>
        </div>
        <div class="ring-wrap">
          <svg class="ring" viewBox="0 0 36 36" aria-hidden="true">
            <circle class="ring-track" cx="18" cy="18" r="15.5"></circle>
            <circle id="ring" class="ring-value" cx="18" cy="18" r="15.5"></circle>
          </svg>
          <strong id="ring-label">0%</strong>
        </div>
      </div>
      <form id="composer" class="card pad composer">
        <label class="sr-only" for="task-title">Task</label>
        <input id="task-title" type="text" maxlength="180" placeholder="Add a task and press Enter" autocomplete="off" required>
        <div class="composer-row">
          <label>List
            <select id="composer-list"></select>
          </label>
          <label>Priority
            <select id="task-priority">
              <option value="medium" selected>Medium</option>
              <option value="high">High</option>
              <option value="low">Low</option>
            </select>
          </label>
          <label>Due
            <input id="task-due" type="date">
          </label>
          <button id="toggle-note" class="btn ghost" type="button">Add a note</button>
          <button class="btn" type="submit">Add task</button>
        </div>
        <label class="sr-only" for="task-note">Note</label>
        <textarea id="task-note" maxlength="2000" placeholder="A note for later" hidden></textarea>
      </form>
      <div class="toolbar filters" role="toolbar" aria-label="Filter tasks">
        <button class="btn ghost" type="button" data-filter="open" aria-pressed="true">Open</button>
        <button class="btn ghost" type="button" data-filter="today">Today</button>
        <button class="btn ghost" type="button" data-filter="upcoming">Upcoming</button>
        <button class="btn ghost" type="button" data-filter="overdue">Overdue</button>
        <button class="btn ghost" type="button" data-filter="starred">Starred</button>
        <button class="btn ghost" type="button" data-filter="done">Done</button>
        <button class="btn ghost" type="button" data-filter="all">All</button>
        <label class="sr-only" for="task-search">Search tasks</label>
        <input id="task-search" type="search" placeholder="Search tasks" autocomplete="off">
        <label>Sort
          <select id="sort">
            <option value="manual">Manual</option>
            <option value="due">Due date</option>
            <option value="priority">Priority</option>
          </select>
        </label>
      </div>
      <div id="empty" class="note" hidden></div>
      <div id="suggestions" class="btn-row" hidden></div>
      <ul id="tasks" class="task-list" aria-label="Tasks"></ul>
      <div class="btn-row">
        <button id="clear-done" class="btn ghost" type="button">Clear completed</button>
        <button id="export-txt" class="btn ghost" type="button">Download TXT</button>
        <button id="export-pdf" class="btn secondary" type="button">Download PDF</button>
      </div>
      <div id="undo" class="note" hidden>
        <span id="undo-text">Removed.</span>
        <button id="undo-btn" class="btn ghost" type="button">Undo</button>
      </div>
    </div>
  </div>
</section>
<noscript><p class="wrap">The list is saved in this browser and needs JavaScript.</p></noscript>
<?php
render_footer(['/assets/js/todo.js']);
