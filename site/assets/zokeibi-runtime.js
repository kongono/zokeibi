/* Zokeibi Vanilla Runtime v1.1.0 — Zero dependencies, Strict-CSP safe, Zero innerHTML XSS sinks */
(() => {
  "use strict";

  if (window.__zokeibiRuntimeInit) return;
  window.__zokeibiRuntimeInit = true;

  const MONTHS = [
    "January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December",
  ];

  const FOCUSABLE_SEL =
    "a[href], button:not(:disabled), input:not([type='hidden']):not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex='-1'])";

  const OVERLAY_SEL = ".z-modal-backdrop, .z-drawer-backdrop, .z-cmdk-backdrop";
  const THEME_STORAGE_KEY = "zokeibi-theme";
  const VALID_THEME_RE = /^[a-z0-9][a-z0-9_-]{0,31}$/i;
  const openerMap = new WeakMap();
  const copyTimers = new WeakMap();
  const sigInitSet = new WeakSet();
  const sigDrawnSet = new WeakSet();
  let pointerDownTarget = null;

  /* ---- OKLCH Theme Switcher & Persistence ---- */
  function syncThemeButtons(activeTheme) {
    document.querySelectorAll("[data-z-set-theme]").forEach((btn) => {
      const slug = (btn.getAttribute("data-z-set-theme") || "").trim().toLowerCase();
      btn.setAttribute("aria-pressed", slug === activeTheme ? "true" : "false");
    });
  }

  function setTheme(rawTheme, persist) {
    const clean = (rawTheme || "").trim().toLowerCase();
    if (!VALID_THEME_RE.test(clean)) return false;
    document.documentElement.setAttribute("data-zokeibi-theme", clean);
    syncThemeButtons(clean);
    if (persist !== false) {
      try {
        window.localStorage.setItem(THEME_STORAGE_KEY, clean);
      } catch (_e) {
        // Ignore private-mode storage errors
      }
    }
    document.dispatchEvent(new CustomEvent("zokeibi:theme-change", { detail: { theme: clean } }));
    return true;
  }

  function restorePersistedTheme() {
    if (!document.documentElement.hasAttribute("data-zokeibi-theme") && !document.documentElement.hasAttribute("data-z-persist-theme")) {
      return;
    }
    let saved = "";
    try {
      saved = (window.localStorage.getItem(THEME_STORAGE_KEY) || "").trim().toLowerCase();
    } catch (_e) {
      saved = "";
    }
    if (saved && VALID_THEME_RE.test(saved)) {
      setTheme(saved, false);
    } else {
      syncThemeButtons((document.documentElement.getAttribute("data-zokeibi-theme") || "").trim().toLowerCase());
    }
  }

  /* ---- ESIGN Signature Pad (Pointer/Touch Canvas + Typed Fallback) ---- */
  function canvasPoint(canvas, event) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = rect.width > 0 ? canvas.width / rect.width : 1;
    const scaleY = rect.height > 0 ? canvas.height / rect.height : 1;
    return {
      x: (event.clientX - rect.left) * scaleX,
      y: (event.clientY - rect.top) * scaleY,
    };
  }

  function initSignaturePads(scope) {
    (scope || document).querySelectorAll("[data-z-signature]").forEach((root) => {
      const canvas = root.querySelector("[data-z-sig-canvas]");
      if (!(canvas instanceof HTMLCanvasElement) || sigInitSet.has(canvas)) return;
      sigInitSet.add(canvas);
      let drawing = false;

      canvas.addEventListener("pointerdown", (event) => {
        if (event.button !== 0 && event.pointerType === "mouse") return;
        const ctx = canvas.getContext("2d");
        if (!ctx) return;
        drawing = true;
        if (typeof canvas.setPointerCapture === "function") {
          try { canvas.setPointerCapture(event.pointerId); } catch (_e) {}
        }
        const pt = canvasPoint(canvas, event);
        ctx.strokeStyle = getComputedStyle(canvas).color || "#111";
        ctx.lineWidth = 2.25;
        ctx.lineCap = "round";
        ctx.lineJoin = "round";
        ctx.beginPath();
        ctx.moveTo(pt.x, pt.y);
        ctx.lineTo(pt.x + 0.1, pt.y + 0.1);
        ctx.stroke();
        sigDrawnSet.add(canvas);
      });

      canvas.addEventListener("pointermove", (event) => {
        if (!drawing) return;
        const ctx = canvas.getContext("2d");
        if (!ctx) return;
        const pt = canvasPoint(canvas, event);
        ctx.lineTo(pt.x, pt.y);
        ctx.stroke();
      });

      const finishStroke = () => {
        if (!drawing) return;
        drawing = false;
        const valueInput = root.querySelector("[data-z-sig-value]");
        const typeInput = root.querySelector("[data-z-sig-type]");
        if (typeInput instanceof HTMLInputElement) typeInput.value = "drawn";
        if (valueInput instanceof HTMLInputElement && sigDrawnSet.has(canvas)) {
          try {
            valueInput.value = canvas.toDataURL("image/png");
            valueInput.dispatchEvent(new Event("change", { bubbles: true }));
          } catch (_e) {}
        }
      };

      canvas.addEventListener("pointerup", finishStroke);
      canvas.addEventListener("pointercancel", finishStroke);
    });
  }

  function findTarget(selector) {
    if (!selector) return null;
    const trimmed = selector.trim();
    if (!trimmed) return null;
    if (trimmed.startsWith("#")) return document.getElementById(trimmed.slice(1));
    const byId = document.getElementById(trimmed);
    if (byId) return byId;
    try {
      const el = document.querySelector(trimmed);
      if (el && el.matches(".z-modal-backdrop, .z-drawer-backdrop, .z-cmdk-backdrop, .z-modal, .z-drawer, .z-toast, .z-banner, [data-z-panel]")) {
        return el;
      }
    } catch (_e) {
      return null;
    }
    return null;
  }

  function resolveOverlay(el) {
    if (!el) return null;
    if (el.matches(OVERLAY_SEL)) return el;
    return el.closest(OVERLAY_SEL) || el;
  }

  function visibleFocusables(container) {
    return Array.from(container.querySelectorAll(FOCUSABLE_SEL)).filter((node) => {
      if (!(node instanceof HTMLElement)) return false;
      if (node.hidden || node.closest("[hidden]")) return false;
      return node.offsetParent !== null || getComputedStyle(node).position === "fixed";
    });
  }

  function showEl(rawEl) {
    const el = resolveOverlay(rawEl);
    if (!el) return;
    if (document.activeElement instanceof HTMLElement && !el.contains(document.activeElement)) {
      openerMap.set(el, document.activeElement);
    }
    el.hidden = false;
    if (el.hasAttribute("data-z-cmdk")) {
      const cmdInput = el.querySelector("[data-z-cmdk-input]");
      if (cmdInput instanceof HTMLInputElement) {
        cmdInput.value = "";
      }
      filterCmdk(el, "");
    }
    const focusables = visibleFocusables(el);
    if (focusables.length > 0) focusables[0].focus();
  }

  function hideEl(rawEl, fromLiveClick) {
    const el = resolveOverlay(rawEl);
    if (!el) return;
    const opener = openerMap.get(el);
    openerMap.delete(el);

    // Keep Kongono Live k-show state synchronized when closing via Esc or backdrop click
    if (!fromLiveClick && el.hasAttribute("k-show")) {
      const liveClose = el.querySelector("[k-on\\:click]");
      if (liveClose instanceof HTMLElement && typeof liveClose.click === "function") {
        liveClose.dataset.zRuntimeClosing = "1";
        liveClose.click();
        delete liveClose.dataset.zRuntimeClosing;
      }
    }

    el.hidden = true;
    if (opener && opener.isConnected && typeof opener.focus === "function") {
      opener.focus();
    }
  }

  function formatIsoDate(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, "0");
    const d = String(date.getDate()).padStart(2, "0");
    return `${y}-${m}-${d}`;
  }

  function isSafeHref(raw) {
    const clean = (raw || "").replace(/[\x00-\x1F\x7F]+/g, "").trim();
    if (!clean) return false;
    const lower = clean.toLowerCase();
    if (lower.startsWith("//") || lower.startsWith("/\\")) return false;
    if (lower.startsWith("/") || lower.startsWith("#")) return true;
    try {
      const parsed = new URL(clean, window.location.origin);
      return parsed.protocol === "https:" || parsed.protocol === "http:" || parsed.protocol === "mailto:";
    } catch (_e) {
      return false;
    }
  }

  function filterCmdk(backdrop, query) {
    const q = (query || "").trim().toLowerCase();
    const items = Array.from(backdrop.querySelectorAll("[data-z-cmdk-item]"));
    let visible = 0;
    items.forEach((li) => {
      const hay = (li.getAttribute("data-z-cmdk-item") || li.textContent || "").toLowerCase();
      const match = q === "" || hay.includes(q);
      li.hidden = !match;
      const link = li.querySelector(".z-cmdk-item");
      if (link) link.classList.remove("is-active");
      li.setAttribute("aria-selected", "false");
      if (match) {
        if (visible === 0) {
          if (link) link.classList.add("is-active");
          li.setAttribute("aria-selected", "true");
        }
        visible++;
      }
    });
    const empty = backdrop.querySelector("[data-z-cmdk-empty]");
    if (empty) empty.hidden = visible > 0;
  }

  function updateDropzoneFiles(root, files) {
    const list = root.querySelector("[data-z-dropzone-files]");
    if (!list) return;
    list.textContent = "";
    Array.from(files || []).forEach((file) => {
      const li = document.createElement("li");
      li.className = "z-dropzone-file";
      const kb = Math.max(1, Math.round(file.size / 1024));
      li.textContent = `${file.name} (${kb} KB)`;
      list.appendChild(li);
    });
  }

  function closeCombobox(root) {
    const list = root.querySelector("[data-z-combobox-list]");
    const input = root.querySelector("[data-z-combobox-input]");
    if (list) list.hidden = true;
    if (input) {
      input.setAttribute("aria-expanded", "false");
      input.removeAttribute("aria-activedescendant");
    }
    root.querySelectorAll("[data-z-combobox-option].is-active").forEach((o) => o.classList.remove("is-active"));
  }

  function closeMultiSelect(root) {
    const list = root.querySelector("[data-z-ms-list]");
    const input = root.querySelector("[data-z-ms-input]");
    if (list) list.hidden = true;
    if (input) {
      input.setAttribute("aria-expanded", "false");
      input.removeAttribute("aria-activedescendant");
    }
    root.querySelectorAll("[data-z-ms-option].is-active").forEach((o) => o.classList.remove("is-active"));
  }

  function selectComboboxOption(root, comboOpt) {
    const val = comboOpt.getAttribute("data-value") || "";
    const lbl = comboOpt.getAttribute("data-label") || (comboOpt.textContent || "").trim();
    const hiddenInput = root.querySelector("[data-z-combobox-value]");
    const textInput = root.querySelector("[data-z-combobox-input]");
    if (hiddenInput) {
      hiddenInput.value = val;
      hiddenInput.dispatchEvent(new Event("change", { bubbles: true }));
    }
    if (textInput) {
      textInput.value = lbl;
      textInput.setCustomValidity("");
    }
    root.querySelectorAll("[data-z-combobox-option]").forEach((opt) => {
      opt.hidden = false;
      opt.setAttribute("aria-selected", opt === comboOpt ? "true" : "false");
    });
    closeCombobox(root);
  }

  function moveListHighlight(input, options, key) {
    const visible = options.filter((opt) => !opt.hidden);
    if (visible.length === 0) return null;
    const curIdx = visible.findIndex((opt) => opt.classList.contains("is-active"));
    let nextIdx = 0;
    if (key === "ArrowDown") nextIdx = curIdx < 0 ? 0 : (curIdx + 1) % visible.length;
    else if (key === "ArrowUp") nextIdx = curIdx < 0 ? visible.length - 1 : (curIdx - 1 + visible.length) % visible.length;
    else if (key === "Home") nextIdx = 0;
    else if (key === "End") nextIdx = visible.length - 1;

    visible.forEach((opt, i) => opt.classList.toggle("is-active", i === nextIdx));
    const active = visible[nextIdx];
    if (active) {
      if (active.id) input.setAttribute("aria-activedescendant", active.id);
      if (typeof active.scrollIntoView === "function") active.scrollIntoView({ block: "nearest" });
    }
    return active;
  }

  /* ---- Date Range Calendar ---- */
  function renderDateRange(root, focusIso) {
    const rawY = parseInt(root.getAttribute("data-dr-year") || "", 10);
    const rawM = parseInt(root.getAttribute("data-dr-month") || "", 10);
    const now = new Date();
    const year = Number.isFinite(rawY) ? rawY : now.getFullYear();
    const month = Number.isFinite(rawM) && rawM >= 1 && rawM <= 12 ? rawM : now.getMonth() + 1;
    const startInput = root.querySelector("[data-z-dr-start]");
    const endInput = root.querySelector("[data-z-dr-end]");
    const summary = root.querySelector("[data-z-dr-summary]");
    const monthEl = root.querySelector("[data-z-dr-month]");
    const grid = root.querySelector("[data-z-dr-grid]");
    if (!startInput || !endInput || !grid) return;

    const start = startInput.value || "";
    const end = endInput.value || "";
    const todayIso = formatIsoDate(new Date());
    if (summary) summary.textContent = end ? `${start} → ${end}` : `${start} → Select end date`;
    if (monthEl) monthEl.textContent = `${MONTHS[(month - 1 + 12) % 12]} ${year}`;

    const firstDow = new Date(year, month - 1, 1).getDay();
    const daysInMonth = new Date(year, month, 0).getDate();
    grid.textContent = "";

    for (let i = 0; i < firstDow; i++) {
      const spacer = document.createElement("span");
      spacer.setAttribute("aria-hidden", "true");
      grid.appendChild(spacer);
    }
    let toFocus = null;
    for (let d = 1; d <= daysInMonth; d++) {
      const iso = `${year}-${String(month).padStart(2, "0")}-${String(d).padStart(2, "0")}`;
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "z-dr-day";
      btn.textContent = String(d);
      btn.setAttribute("data-z-dr-day", iso);
      btn.setAttribute("aria-label", iso);
      let isSelected = false;
      if (iso === todayIso) {
        btn.classList.add("is-today");
        btn.setAttribute("aria-current", "date");
      }
      if (iso === start || (end && iso === end)) {
        btn.classList.add("is-edge");
        isSelected = true;
      } else if (start && end && iso > start && iso < end) {
        btn.classList.add("is-in-range");
        isSelected = true;
      }
      btn.setAttribute("aria-pressed", isSelected ? "true" : "false");
      if (focusIso && iso === focusIso) toFocus = btn;
      grid.appendChild(btn);
    }
    if (toFocus) toFocus.focus();
  }

  /* ---- Multi-Select Tag Combobox ---- */
  function syncMultiSelect(root) {
    const name = root.getAttribute("data-ms-name") || "tags";
    const chipsBox = root.querySelector("[data-z-ms-chips]");
    const hiddenBox = root.querySelector("[data-z-ms-hidden]");
    if (!chipsBox || !hiddenBox) return;
    chipsBox.textContent = "";
    hiddenBox.textContent = "";

    root.querySelectorAll('[data-z-ms-option][aria-selected="true"]').forEach((opt) => {
      const val = opt.getAttribute("data-value") || "";
      const lbl = opt.getAttribute("data-label") || val;
      if (!val) return;

      const inp = document.createElement("input");
      inp.type = "hidden";
      inp.name = `${name}[]`;
      inp.value = val;
      hiddenBox.appendChild(inp);

      const chip = document.createElement("span");
      chip.className = "z-ms-chip";
      chip.setAttribute("data-ms-chip", val);
      const span = document.createElement("span");
      span.textContent = lbl;
      const rm = document.createElement("button");
      rm.type = "button";
      rm.className = "z-ms-chip-rm";
      rm.setAttribute("data-z-ms-remove", val);
      rm.setAttribute("aria-label", `Remove ${lbl}`);
      rm.textContent = "×";
      chip.appendChild(span);
      chip.appendChild(rm);
      chipsBox.appendChild(chip);
    });
  }

  function toggleMultiOption(root, opt) {
    const selected = opt.getAttribute("aria-selected") === "true";
    const next = !selected;
    opt.setAttribute("aria-selected", next ? "true" : "false");
    const chk = opt.querySelector(".z-ms-check");
    if (chk) chk.textContent = next ? "✓" : "";
    syncMultiSelect(root);
  }

  /* ---- Sortable & Filterable Data Tables ---- */
  function directTableRows(table) {
    const tbody = table && table.tBodies && table.tBodies[0];
    return tbody ? Array.from(tbody.rows) : [];
  }

  function updateTableCount(wrap) {
    const countEl = wrap.querySelector("[data-z-table-count]");
    const table = wrap.querySelector("table");
    if (!countEl || !table) return;
    const rows = directTableRows(table);
    const visible = rows.filter((r) => !r.hidden).length;
    countEl.textContent = `Showing ${visible} of ${rows.length} rows`;
  }

  function initSortableTables(scope) {
    (scope || document).querySelectorAll("[data-z-table]").forEach((wrap) => {
      if (wrap.getAttribute("data-z-table-sortable") === "true") {
        const table = wrap.querySelector("table");
        const thead = table && table.tHead;
        if (thead) {
          thead.querySelectorAll("th").forEach((th) => {
            if (!th.hasAttribute("data-z-sort")) th.setAttribute("data-z-sort", "auto");
            if (!th.hasAttribute("tabindex")) th.setAttribute("tabindex", "0");
            if (!th.hasAttribute("aria-sort")) th.setAttribute("aria-sort", "none");
          });
        }
      }
      updateTableCount(wrap);
    });
  }

  function parseNumericCell(raw) {
    const s = (raw || "").trim();
    const m = s.replace(/[$€£¥,\s]/g, "").match(/^(-?\d+(?:\.\d+)?)(%|ms|s|gb|mb|kb|b)?$/i);
    if (!m) return NaN;
    const val = parseFloat(m[1]);
    if (!Number.isFinite(val)) return NaN;
    const unit = (m[2] || "").toLowerCase();
    const mult =
      unit === "ms" ? 0.001 :
      unit === "kb" ? 1024 :
      unit === "mb" ? 1048576 :
      unit === "gb" ? 1073741824 :
      1;
    return val * mult;
  }

  function sortTableByHeader(th) {
    const table = th.closest("table");
    const tbody = table && table.tBodies && table.tBodies[0];
    const row = th.parentElement;
    if (!table || !tbody || !row) return;

    const colIdx = Array.from(row.children).indexOf(th);
    if (colIdx < 0) return;

    const cur = th.getAttribute("aria-sort");
    const dir = cur === "ascending" ? "descending" : "ascending";
    Array.from(row.children).forEach((other) => {
      if (other.matches("th[data-z-sort]")) {
        other.setAttribute("aria-sort", other === th ? dir : "none");
      }
    });

    const mode = th.getAttribute("data-z-sort") || "auto";
    const rows = Array.from(tbody.rows);
    const parseVal = (tr) => {
      const cell = tr.cells[colIdx];
      if (!cell) return "";
      const explicit = cell.getAttribute("data-sort-value");
      return (explicit !== null ? explicit : cell.textContent || "").trim();
    };

    rows.sort((a, b) => {
      const va = parseVal(a);
      const vb = parseVal(b);
      if (mode !== "string") {
        const na = parseNumericCell(va);
        const nb = parseNumericCell(vb);
        const aNum = !Number.isNaN(na);
        const bNum = !Number.isNaN(nb);
        if (aNum && bNum) return dir === "ascending" ? na - nb : nb - na;
        if (aNum !== bNum) return aNum ? -1 : 1;
      }
      const cmp = va.localeCompare(vb, undefined, { numeric: true, sensitivity: "base" });
      return dir === "ascending" ? cmp : -cmp;
    });

    rows.forEach((r) => tbody.appendChild(r));
  }

  /* ---- XSS-Safe DOM Markdown Previewer ---- */
  function appendInlineMarkdown(parent, text) {
    // Non-backtracking character classes prevent ReDoS on repeated '[' or '*'
    const tokenRe = /(\*\*([^*]+)\*\*|\*([^*]+)\*|`([^`]+)`|\[([^\]\[]+)\]\(([^)\s]+)\))/g;
    let last = 0;
    let m;
    while ((m = tokenRe.exec(text)) !== null) {
      if (m.index > last) parent.appendChild(document.createTextNode(text.slice(last, m.index)));
      if (m[2] !== undefined) {
        const strong = document.createElement("strong");
        strong.textContent = m[2];
        parent.appendChild(strong);
      } else if (m[3] !== undefined) {
        const em = document.createElement("em");
        em.textContent = m[3];
        parent.appendChild(em);
      } else if (m[4] !== undefined) {
        const code = document.createElement("code");
        code.textContent = m[4];
        parent.appendChild(code);
      } else if (m[5] !== undefined && m[6] !== undefined) {
        const href = m[6].trim();
        const a = document.createElement("a");
        a.textContent = m[5];
        a.setAttribute("href", isSafeHref(href) ? href : "#");
        a.setAttribute("rel", "noopener noreferrer");
        parent.appendChild(a);
      }
      last = tokenRe.lastIndex;
    }
    if (last < text.length) parent.appendChild(document.createTextNode(text.slice(last)));
  }

  function renderEditorPreview(root) {
    const ta = root.querySelector("[data-z-ed-input]");
    const prev = root.querySelector("[data-z-ed-preview]");
    const countEl = root.querySelector("[data-z-ed-count]");
    if (!ta) return;

    const raw = ta.value || "";
    const words = raw.trim() === "" ? 0 : raw.trim().split(/\s+/).length;
    if (countEl) countEl.textContent = `${words} words · ${raw.length} chars`;
    if (!prev) return;

    prev.textContent = "";
    const lines = raw.split(/\r?\n/);
    let currentUl = null;
    lines.forEach((line) => {
      const trimmed = line.trim();
      if (trimmed === "") {
        currentUl = null;
        return;
      }
      if (/^[-*]\s+/.test(trimmed)) {
        if (!currentUl) {
          currentUl = document.createElement("ul");
          prev.appendChild(currentUl);
        }
        const li = document.createElement("li");
        appendInlineMarkdown(li, trimmed.replace(/^[-*]\s+/, ""));
        currentUl.appendChild(li);
        return;
      }
      currentUl = null;
      const hMatch = trimmed.match(/^(#{1,3})\s+(.+)$/);
      if (hMatch) {
        const level = Math.min(3, Math.max(1, hMatch[1].length));
        const h = document.createElement(`h${level}`);
        appendInlineMarkdown(h, hMatch[2]);
        prev.appendChild(h);
      } else if (trimmed.startsWith("> ")) {
        const bq = document.createElement("blockquote");
        appendInlineMarkdown(bq, trimmed.slice(2));
        prev.appendChild(bq);
      } else {
        const p = document.createElement("p");
        appendInlineMarkdown(p, trimmed);
        prev.appendChild(p);
      }
    });
  }

  function applyEditorCommand(root, cmd) {
    const ta = root.querySelector("[data-z-ed-input]");
    if (!ta) return;
    const s = ta.selectionStart ?? ta.value.length;
    const e = ta.selectionEnd ?? ta.value.length;
    const sel = ta.value.slice(s, e) || "text";
    let replacement = sel;
    if (cmd === "bold") replacement = `**${sel}**`;
    else if (cmd === "italic") replacement = `*${sel}*`;
    else if (cmd === "code") replacement = `\`${sel}\``;
    else if (cmd === "heading") replacement = `\n### ${sel}\n`;
    else if (cmd === "quote") replacement = `\n> ${sel}\n`;
    else if (cmd === "list") replacement = `\n- ${sel}\n`;
    else if (cmd === "link") replacement = `[${sel}](https://example.com)`;

    ta.focus();
    ta.setRangeText(replacement, s, e, "select");
    ta.dispatchEvent(new Event("input", { bubbles: true }));
    renderEditorPreview(root);
  }

  /* ---- OTP Input Sync ---- */
  function syncOtp(root) {
    const hidden = root.querySelector("[data-z-otp-value]");
    const digits = Array.from(root.querySelectorAll("[data-z-otp-digit]"));
    if (hidden) {
      hidden.value = digits.map((d) => d.value || "").join("");
      hidden.dispatchEvent(new Event("change", { bubbles: true }));
    }
  }

  /* ---- Free-Form Tag Input ---- */
  function syncTagInput(root, values) {
    const name = root.getAttribute("data-ti-name") || "tags";
    const chipsBox = root.querySelector("[data-z-ti-chips]");
    const hiddenBox = root.querySelector("[data-z-ti-hidden]");
    if (!chipsBox || !hiddenBox) return;
    chipsBox.textContent = "";
    hiddenBox.textContent = "";
    values.forEach((tag) => {
      const inp = document.createElement("input");
      inp.type = "hidden";
      inp.name = `${name}[]`;
      inp.value = tag;
      hiddenBox.appendChild(inp);

      const chip = document.createElement("span");
      chip.className = "z-taginput-chip";
      chip.setAttribute("data-ti-chip", tag);
      const span = document.createElement("span");
      span.textContent = tag;
      const rm = document.createElement("button");
      rm.type = "button";
      rm.className = "z-taginput-rm";
      rm.setAttribute("data-z-ti-remove", tag);
      rm.setAttribute("aria-label", `Remove ${tag}`);
      rm.textContent = "×";
      chip.appendChild(span);
      chip.appendChild(rm);
      chipsBox.appendChild(chip);
    });
  }

  function getTagValues(root) {
    return Array.from(root.querySelectorAll("[data-z-ti-hidden] input[type='hidden']")).map((i) => i.value);
  }

  function addTagValue(root, raw) {
    const parts = (raw || "").split(",").map((p) => p.trim()).filter(Boolean);
    if (parts.length === 0) return;
    const current = getTagValues(root);
    let changed = false;
    parts.forEach((clean) => {
      if (!current.includes(clean)) {
        current.push(clean);
        changed = true;
      }
    });
    if (changed) syncTagInput(root, current);
  }

  function activateTab(group, tabBtn) {
    const tabId = tabBtn.getAttribute("data-z-tab");
    if (!tabId) return;
    Array.from(group.querySelectorAll("[data-z-tab]"))
      .filter((btn) => btn.closest("[data-z-tabs]") === group)
      .forEach((btn) => {
        const active = btn.getAttribute("data-z-tab") === tabId;
        btn.classList.toggle("is-active", active);
        btn.setAttribute("aria-selected", active ? "true" : "false");
        btn.setAttribute("tabindex", active ? "0" : "-1");
      });
    Array.from(group.querySelectorAll("[data-z-panel]"))
      .filter((panel) => panel.closest("[data-z-tabs]") === group)
      .forEach((panel) => {
        panel.hidden = panel.getAttribute("data-z-panel") !== tabId;
      });
  }

  function announceCopy(btn, statusEl, ok) {
    if (!btn.dataset.origLabel) btn.dataset.origLabel = btn.textContent || "Copy";
    const prevTimer = copyTimers.get(btn);
    if (prevTimer) window.clearTimeout(prevTimer);

    if (ok) {
      if (statusEl) statusEl.textContent = "Copied to clipboard";
      btn.textContent = "Copied!";
    } else {
      if (statusEl) statusEl.textContent = "Unable to copy automatically";
      btn.textContent = "Copy failed";
    }
    const timer = window.setTimeout(() => {
      btn.textContent = btn.dataset.origLabel || "Copy";
      if (statusEl) statusEl.textContent = "";
      copyTimers.delete(btn);
    }, 1600);
    copyTimers.set(btn, timer);
  }

  /* ---- Pointerdown Tracking for Backdrop Drag Safety ---- */
  document.addEventListener("pointerdown", (event) => {
    pointerDownTarget = event.target instanceof Element ? event.target : null;
  });

  /* ---- Global Click Delegation ---- */
  document.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    // Always close outside comboboxes & multi-selects before early-returning
    document.querySelectorAll("[data-z-combobox]").forEach((root) => {
      if (!root.contains(target)) closeCombobox(root);
    });
    document.querySelectorAll("[data-z-multiselect]").forEach((root) => {
      if (!root.contains(target)) closeMultiSelect(root);
    });

    const openBtn = target.closest("[data-z-open]");
    if (openBtn) {
      showEl(findTarget(openBtn.getAttribute("data-z-open")));
      return;
    }

    const closeBtn = target.closest("[data-z-close]");
    if (closeBtn) {
      if (closeBtn instanceof HTMLElement && closeBtn.dataset.zRuntimeClosing === "1") return;
      const sel = closeBtn.getAttribute("data-z-close");
      const fromLive = closeBtn.hasAttribute("k-on:click");
      hideEl(
        sel ? findTarget(sel) : closeBtn.closest(".z-modal-backdrop, .z-drawer-backdrop, .z-cmdk-backdrop, .z-toast, .z-banner"),
        fromLive
      );
      return;
    }

    const toggleBtn = target.closest("[data-z-toggle]");
    if (toggleBtn) {
      const el = resolveOverlay(findTarget(toggleBtn.getAttribute("data-z-toggle")));
      if (el) el.hidden ? showEl(el) : hideEl(el);
      return;
    }

    const setThemeBtn = target.closest("[data-z-set-theme]");
    if (setThemeBtn) {
      setTheme(setThemeBtn.getAttribute("data-z-set-theme") || "", true);
      return;
    }

    const cycleThemeBtn = target.closest("[data-z-cycle-theme]");
    if (cycleThemeBtn) {
      const list = (cycleThemeBtn.getAttribute("data-z-cycle-theme") || "washi,sumi,matcha")
        .split(",")
        .map((s) => s.trim().toLowerCase())
        .filter((s) => VALID_THEME_RE.test(s));
      if (list.length > 0) {
        const cur = (document.documentElement.getAttribute("data-zokeibi-theme") || "").trim().toLowerCase();
        const idx = list.indexOf(cur);
        const next = list[(idx + 1) % list.length];
        setTheme(next, true);
      }
      return;
    }

    const sigModeBtn = target.closest("[data-z-sig-mode]");
    if (sigModeBtn) {
      const root = sigModeBtn.closest("[data-z-signature]");
      const mode = sigModeBtn.getAttribute("data-z-sig-mode");
      if (root && (mode === "drawn" || mode === "typed")) {
        root.setAttribute("data-sig-mode", mode);
        root.querySelectorAll("[data-z-sig-mode]").forEach((btn) => {
          const active = btn.getAttribute("data-z-sig-mode") === mode;
          btn.classList.toggle("is-active", active);
          btn.setAttribute("aria-selected", active ? "true" : "false");
        });
        root.querySelectorAll("[data-z-sig-panel]").forEach((panel) => {
          panel.hidden = panel.getAttribute("data-z-sig-panel") !== mode;
        });
        const typeInput = root.querySelector("[data-z-sig-type]");
        const valueInput = root.querySelector("[data-z-sig-value]");
        if (typeInput instanceof HTMLInputElement) typeInput.value = mode;
        if (valueInput instanceof HTMLInputElement) {
          if (mode === "typed") {
            const textInp = root.querySelector("[data-z-sig-text]");
            valueInput.value = textInp instanceof HTMLInputElement ? textInp.value.trim() : "";
          } else {
            const canvas = root.querySelector("[data-z-sig-canvas]");
            valueInput.value = canvas instanceof HTMLCanvasElement && sigDrawnSet.has(canvas) ? canvas.toDataURL("image/png") : "";
          }
          valueInput.dispatchEvent(new Event("change", { bubbles: true }));
        }
      }
      return;
    }

    const sigClearBtn = target.closest("[data-z-sig-clear]");
    if (sigClearBtn) {
      const root = sigClearBtn.closest("[data-z-signature]");
      if (root) {
        const canvas = root.querySelector("[data-z-sig-canvas]");
        if (canvas instanceof HTMLCanvasElement) {
          const ctx = canvas.getContext("2d");
          if (ctx) ctx.clearRect(0, 0, canvas.width, canvas.height);
          sigDrawnSet.delete(canvas);
        }
        const textInp = root.querySelector("[data-z-sig-text]");
        if (textInp instanceof HTMLInputElement) textInp.value = "";
        const preview = root.querySelector("[data-z-sig-preview]");
        if (preview) preview.textContent = "Your signature preview";
        const valueInput = root.querySelector("[data-z-sig-value]");
        if (valueInput instanceof HTMLInputElement) {
          valueInput.value = "";
          valueInput.dispatchEvent(new Event("change", { bubbles: true }));
        }
      }
      return;
    }

    const tabBtn = target.closest("[data-z-tab]");
    if (tabBtn) {
      const group = tabBtn.closest("[data-z-tabs]");
      if (group) activateTab(group, tabBtn);
      return;
    }

    const comboToggle = target.closest("[data-z-combobox-toggle]");
    if (comboToggle) {
      const root = comboToggle.closest("[data-z-combobox]");
      const list = root && root.querySelector("[data-z-combobox-list]");
      const input = root && root.querySelector("[data-z-combobox-input]");
      if (root && list && input) {
        const opening = list.hidden;
        if (opening) {
          root.querySelectorAll("[data-z-combobox-option]").forEach((opt) => {
            opt.hidden = false;
          });
          list.hidden = false;
          input.setAttribute("aria-expanded", "true");
          input.focus();
        } else {
          closeCombobox(root);
        }
      }
      return;
    }

    const comboOpt = target.closest("[data-z-combobox-option]");
    if (comboOpt) {
      const root = comboOpt.closest("[data-z-combobox]");
      if (root) selectComboboxOption(root, comboOpt);
      return;
    }

    const msOpt = target.closest("[data-z-ms-option]");
    if (msOpt) {
      const root = msOpt.closest("[data-z-multiselect]");
      if (root) toggleMultiOption(root, msOpt);
      return;
    }

    const msRm = target.closest("[data-z-ms-remove]");
    if (msRm) {
      const root = msRm.closest("[data-z-multiselect]");
      const val = msRm.getAttribute("data-z-ms-remove");
      if (root && val) {
        const match = root.querySelector(`[data-z-ms-option][data-value="${CSS.escape(val)}"]`);
        if (match) toggleMultiOption(root, match);
        const inp = root.querySelector("[data-z-ms-input]");
        if (inp instanceof HTMLElement) inp.focus();
      }
      return;
    }

    const tiRm = target.closest("[data-z-ti-remove]");
    if (tiRm) {
      const root = tiRm.closest("[data-z-taginput]");
      const val = tiRm.getAttribute("data-z-ti-remove");
      if (root && val) {
        const nextValues = getTagValues(root).filter((t) => t !== val);
        syncTagInput(root, nextValues);
        const nextRm = root.querySelector("[data-z-ti-remove]");
        const field = root.querySelector("[data-z-ti-field]");
        if (nextRm instanceof HTMLElement) nextRm.focus();
        else if (field instanceof HTMLElement) field.focus();
      }
      return;
    }

    const numBtn = target.closest("[data-z-num-dec], [data-z-num-inc]");
    if (numBtn) {
      const root = numBtn.closest("[data-z-number]");
      const inp = root && root.querySelector("[data-z-num-input]");
      if (inp instanceof HTMLInputElement && !inp.disabled && !inp.readOnly) {
        const rawStep = parseFloat(inp.step || "1");
        const step = Number.isFinite(rawStep) && rawStep > 0 ? rawStep : 1;
        const parsedMin = inp.min !== "" ? parseFloat(inp.min) : -Infinity;
        const parsedMax = inp.max !== "" ? parseFloat(inp.max) : Infinity;
        const min = Number.isFinite(parsedMin) ? parsedMin : -Infinity;
        const max = Number.isFinite(parsedMax) ? parsedMax : Infinity;
        const parsedCur = parseFloat(inp.value || "0");
        const cur = Number.isFinite(parsedCur) ? parsedCur : 0;
        const delta = numBtn.hasAttribute("data-z-num-inc") ? step : -step;
        const stepStr = String(step);
        const decimals = stepStr.includes(".") ? stepStr.split(".")[1].length : 0;
        const factor = Math.pow(10, Math.min(8, decimals));
        const next = Math.max(min, Math.min(max, Math.round((cur + delta) * factor) / factor));
        inp.value = String(next);
        inp.dispatchEvent(new Event("input", { bubbles: true }));
        inp.dispatchEvent(new Event("change", { bubbles: true }));
      }
      return;
    }

    const pwToggle = target.closest("[data-z-pw-toggle]");
    if (pwToggle) {
      const root = pwToggle.closest("[data-z-password]");
      const inp = root && root.querySelector("[data-z-pw-input]");
      if (inp instanceof HTMLInputElement) {
        const showing = inp.type === "text";
        inp.type = showing ? "password" : "text";
        pwToggle.textContent = showing ? "Show" : "Hide";
        pwToggle.setAttribute("aria-pressed", showing ? "false" : "true");
      }
      return;
    }

    const datePreset = target.closest("[data-z-date-offset]");
    if (datePreset) {
      const root = datePreset.closest("[data-z-datepicker]");
      const input = root && root.querySelector("[data-z-date-input]");
      if (input instanceof HTMLInputElement && !input.disabled && !input.readOnly) {
        const days = parseInt(datePreset.getAttribute("data-z-date-offset") || "0", 10);
        const dt = new Date();
        dt.setDate(dt.getDate() + (Number.isNaN(days) ? 0 : days));
        let iso = formatIsoDate(dt);
        if (input.min && iso < input.min) iso = input.min;
        if (input.max && iso > input.max) iso = input.max;
        input.value = iso;
        input.dispatchEvent(new Event("change", { bubbles: true }));
      }
      return;
    }

    const drNav = target.closest("[data-z-dr-nav]");
    if (drNav) {
      const root = drNav.closest("[data-z-daterange]");
      if (root) {
        const now = new Date();
        const rawY = parseInt(root.getAttribute("data-dr-year") || "", 10);
        const rawM = parseInt(root.getAttribute("data-dr-month") || "", 10);
        const y = Number.isFinite(rawY) ? rawY : now.getFullYear();
        const m = Number.isFinite(rawM) ? rawM : now.getMonth() + 1;
        const delta = parseInt(drNav.getAttribute("data-z-dr-nav") || "0", 10) || 0;
        const shifted = new Date(y, m - 1 + delta, 1);
        root.setAttribute("data-dr-year", String(shifted.getFullYear()));
        root.setAttribute("data-dr-month", String(shifted.getMonth() + 1));
        renderDateRange(root);
      }
      return;
    }

    const drDay = target.closest("[data-z-dr-day]");
    if (drDay) {
      const root = drDay.closest("[data-z-daterange]");
      const iso = drDay.getAttribute("data-z-dr-day") || "";
      if (root && iso) {
        const startInput = root.querySelector("[data-z-dr-start]");
        const endInput = root.querySelector("[data-z-dr-end]");
        if (startInput && endInput) {
          if (root.getAttribute("data-dr-picking") !== "end") {
            startInput.value = iso;
            endInput.value = "";
            root.setAttribute("data-dr-picking", "end");
          } else {
            let s = startInput.value || iso;
            let e = iso;
            if (e < s) { const t = s; s = e; e = t; }
            startInput.value = s;
            endInput.value = e;
            root.removeAttribute("data-dr-picking");
          }
          startInput.dispatchEvent(new Event("change", { bubbles: true }));
          endInput.dispatchEvent(new Event("change", { bubbles: true }));
          renderDateRange(root, iso);
        }
      }
      return;
    }

    const drPreset = target.closest("[data-z-dr-preset]");
    if (drPreset) {
      const root = drPreset.closest("[data-z-daterange]");
      const kind = drPreset.getAttribute("data-z-dr-preset") || "7";
      if (root) {
        const startInput = root.querySelector("[data-z-dr-start]");
        const endInput = root.querySelector("[data-z-dr-end]");
        const now = new Date();
        const endDt = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        let startDt = new Date(endDt);
        if (kind === "month") {
          startDt = new Date(endDt.getFullYear(), endDt.getMonth(), 1);
        } else {
          const days = Math.max(1, parseInt(kind, 10) || 7);
          startDt.setDate(startDt.getDate() - (days - 1));
        }
        if (startInput) {
          startInput.value = formatIsoDate(startDt);
          startInput.dispatchEvent(new Event("change", { bubbles: true }));
        }
        if (endInput) {
          endInput.value = formatIsoDate(endDt);
          endInput.dispatchEvent(new Event("change", { bubbles: true }));
        }
        root.removeAttribute("data-dr-picking");
        root.setAttribute("data-dr-year", String(endDt.getFullYear()));
        root.setAttribute("data-dr-month", String(endDt.getMonth() + 1));
        renderDateRange(root);
      }
      return;
    }

    const sortTh = target.closest("th[data-z-sort]");
    if (sortTh) {
      sortTableByHeader(sortTh);
      return;
    }

    const edCmd = target.closest("[data-z-ed-cmd]");
    if (edCmd) {
      const root = edCmd.closest("[data-z-editor]");
      if (root) applyEditorCommand(root, edCmd.getAttribute("data-z-ed-cmd") || "");
      return;
    }

    const edMode = target.closest("[data-z-ed-mode]");
    if (edMode) {
      const root = edMode.closest("[data-z-editor]");
      const mode = edMode.getAttribute("data-z-ed-mode") || "write";
      if (root) {
        const ta = root.querySelector("[data-z-ed-input]");
        const prev = root.querySelector("[data-z-ed-preview]");
        const panes = root.querySelector("[data-z-ed-panes]");
        root.querySelectorAll("[data-z-ed-mode]").forEach((b) => {
          const isAct = b === edMode;
          b.classList.toggle("is-active", isAct);
          b.setAttribute("aria-selected", isAct ? "true" : "false");
        });
        if (panes) panes.classList.toggle("is-split", mode === "split");
        if (ta) ta.hidden = mode === "preview";
        if (prev) prev.hidden = mode === "write";
        renderEditorPreview(root);
      }
      return;
    }

    const copyReveal = target.closest("[data-z-copy-reveal]");
    if (copyReveal) {
      const root = copyReveal.closest("[data-z-copy]");
      const disp = root && root.querySelector("[data-z-copy-display]");
      if (root && disp) {
        const val = root.getAttribute("data-copy-value") || "";
        const isMasked = root.getAttribute("data-copy-masked") === "true";
        root.setAttribute("data-copy-masked", isMasked ? "false" : "true");
        disp.textContent = isMasked ? val : "•".repeat(Math.min(24, Math.max(8, val.length)));
        copyReveal.textContent = isMasked ? "Hide" : "Show";
        copyReveal.setAttribute("aria-pressed", isMasked ? "true" : "false");
      }
      return;
    }

    const copyBtn = target.closest("[data-z-copy-btn]");
    if (copyBtn) {
      const root = copyBtn.closest("[data-z-copy]");
      const status = root && root.querySelector("[data-z-copy-status]");
      const val = (root && root.getAttribute("data-copy-value")) || "";
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard
          .writeText(val)
          .then(() => announceCopy(copyBtn, status, true))
          .catch(() => announceCopy(copyBtn, status, false));
      } else {
        announceCopy(copyBtn, status, false);
      }
      return;
    }

    if (
      target === pointerDownTarget &&
      (target.classList.contains("z-modal-backdrop") ||
        target.classList.contains("z-drawer-backdrop") ||
        target.classList.contains("z-cmdk-backdrop"))
    ) {
      hideEl(target);
    }
  });

  document.addEventListener("focusin", (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (target.hasAttribute("data-z-combobox-input")) {
      const root = target.closest("[data-z-combobox]");
      const list = root && root.querySelector("[data-z-combobox-list]");
      if (list) {
        list.hidden = false;
        target.setAttribute("aria-expanded", "true");
      }
      return;
    }
    if (target.hasAttribute("data-z-ms-input")) {
      const root = target.closest("[data-z-multiselect]");
      const list = root && root.querySelector("[data-z-ms-list]");
      if (list) {
        list.hidden = false;
        target.setAttribute("aria-expanded", "true");
      }
    }
  });

  document.addEventListener("focusout", (event) => {
    const target = event.target;
    const related = event.relatedTarget instanceof Element ? event.relatedTarget : null;
    if (!(target instanceof Element)) return;

    const comboRoot = target.closest("[data-z-combobox]");
    if (comboRoot && (!related || !comboRoot.contains(related))) {
      closeCombobox(comboRoot);
    }
    const msRoot = target.closest("[data-z-multiselect]");
    if (msRoot && (!related || !msRoot.contains(related))) {
      closeMultiSelect(msRoot);
    }
    if (target instanceof HTMLInputElement && target.hasAttribute("data-z-ti-field") && target.value.trim() !== "") {
      const root = target.closest("[data-z-taginput]");
      if (root) {
        addTagValue(root, target.value);
        target.value = "";
      }
    }
  });

  document.addEventListener("input", (event) => {
    const target = event.target;
    if (target instanceof HTMLTextAreaElement && target.hasAttribute("data-z-ed-input")) {
      const root = target.closest("[data-z-editor]");
      if (root) renderEditorPreview(root);
      return;
    }
    if (!(target instanceof HTMLInputElement)) return;

    if (target.hasAttribute("data-z-cmdk-input")) {
      const backdrop = target.closest("[data-z-cmdk]");
      if (backdrop) filterCmdk(backdrop, target.value);
      return;
    }

    if (target.hasAttribute("data-z-combobox-input")) {
      const root = target.closest("[data-z-combobox]");
      const list = root && root.querySelector("[data-z-combobox-list]");
      const hiddenInput = root && root.querySelector("[data-z-combobox-value]");
      if (root && list) {
        const q = target.value.trim().toLowerCase();
        list.hidden = false;
        target.setAttribute("aria-expanded", "true");
        let exactVal = "";
        root.querySelectorAll("[data-z-combobox-option]").forEach((opt) => {
          const lbl = (opt.getAttribute("data-label") || "").toLowerCase();
          const val = (opt.getAttribute("data-value") || "").toLowerCase();
          const hay = lbl + " " + val;
          opt.hidden = q !== "" && !hay.includes(q);
          opt.classList.remove("is-active");
          if (q !== "" && (lbl === q || val === q)) {
            exactVal = opt.getAttribute("data-value") || "";
          }
        });
        if (hiddenInput) hiddenInput.value = exactVal;
      }
      return;
    }

    if (target.hasAttribute("data-z-ms-input")) {
      const root = target.closest("[data-z-multiselect]");
      const list = root && root.querySelector("[data-z-ms-list]");
      if (root && list) {
        const q = target.value.trim().toLowerCase();
        list.hidden = false;
        target.setAttribute("aria-expanded", "true");
        root.querySelectorAll("[data-z-ms-option]").forEach((opt) => {
          const hay = ((opt.getAttribute("data-label") || "") + " " + (opt.getAttribute("data-value") || "")).toLowerCase();
          opt.hidden = q !== "" && !hay.includes(q);
          opt.classList.remove("is-active");
        });
      }
      return;
    }

    if (target.hasAttribute("data-z-ti-field") && target.value.includes(",")) {
      const root = target.closest("[data-z-taginput]");
      if (root) {
        addTagValue(root, target.value);
        target.value = "";
      }
      return;
    }

    if (target.hasAttribute("data-z-table-search")) {
      const wrap = target.closest("[data-z-table]");
      const table = wrap && wrap.querySelector("table");
      if (wrap && table) {
        const q = target.value.trim().toLowerCase();
        directTableRows(table).forEach((tr) => {
          tr.hidden = q !== "" && !(tr.textContent || "").toLowerCase().includes(q);
        });
        updateTableCount(wrap);
      }
      return;
    }

    if (target.hasAttribute("data-z-slider-input")) {
      const root = target.closest("[data-z-slider]");
      const out = root && root.querySelector("[data-z-slider-out]");
      const unit = (root && root.getAttribute("data-slider-unit")) || "";
      if (out) out.textContent = target.value + unit;
      return;
    }

    if (target.hasAttribute("data-z-sig-text")) {
      const root = target.closest("[data-z-signature]");
      if (root) {
        const text = (target.value || "").trim();
        const preview = root.querySelector("[data-z-sig-preview]");
        if (preview) preview.textContent = text !== "" ? text : "Your signature preview";
        const typeInput = root.querySelector("[data-z-sig-type]");
        const valueInput = root.querySelector("[data-z-sig-value]");
        if (typeInput instanceof HTMLInputElement) typeInput.value = "typed";
        if (valueInput instanceof HTMLInputElement) {
          valueInput.value = text;
          valueInput.dispatchEvent(new Event("change", { bubbles: true }));
        }
      }
      return;
    }

    if (target.hasAttribute("data-z-pw-input")) {
      const root = target.closest("[data-z-password]");
      if (root) {
        const v = target.value || "";
        let score = 0;
        if (v.length >= 8) score++;
        if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
        if (/\d/.test(v)) score++;
        if (/[^A-Za-z0-9]/.test(v) || v.length >= 14) score++;
        if (v.length === 0) score = 0;
        root.setAttribute("data-strength", String(score));
        const lbl = root.querySelector("[data-z-pw-label]");
        if (lbl) lbl.textContent = ["Enter password", "Weak", "Fair", "Good", "Strong"][score] || "Strong";
      }
      return;
    }

    if (target.hasAttribute("data-z-otp-digit")) {
      const root = target.closest("[data-z-otp]");
      const clean = target.value.replace(/\D/g, "");
      if (root) {
        const digits = Array.from(root.querySelectorAll("[data-z-otp-digit]"));
        const idx = digits.indexOf(target);
        if (clean.length > 1) {
          const start = clean.length >= digits.length ? 0 : Math.max(0, idx);
          for (let i = 0; i < clean.length && start + i < digits.length; i++) {
            digits[start + i].value = clean[i];
          }
          syncOtp(root);
          const nextFocus = Math.min(start + clean.length, digits.length) - 1;
          if (nextFocus >= 0) digits[nextFocus].focus();
          return;
        }
        target.value = clean.slice(0, 1);
        syncOtp(root);
        if (target.value !== "" && idx >= 0 && idx + 1 < digits.length) {
          digits[idx + 1].focus();
        }
      } else {
        target.value = clean.slice(0, 1);
      }
    }
  });

  document.addEventListener("paste", (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (target.hasAttribute("data-z-otp-digit")) {
      const text = (event.clipboardData && event.clipboardData.getData("text")) || "";
      const nums = text.replace(/\D/g, "");
      if (nums.length > 1) {
        event.preventDefault();
        const root = target.closest("[data-z-otp]");
        if (root) {
          const digits = Array.from(root.querySelectorAll("[data-z-otp-digit]"));
          const idx = digits.indexOf(target);
          const start = nums.length >= digits.length ? 0 : Math.max(0, idx);
          for (let i = 0; i < nums.length && start + i < digits.length; i++) {
            digits[start + i].value = nums[i];
          }
          syncOtp(root);
          const focusIdx = Math.min(start + nums.length, digits.length) - 1;
          if (focusIdx >= 0) digits[focusIdx].focus();
        }
      }
      return;
    }
    if (target.hasAttribute("data-z-ti-field")) {
      const text = (event.clipboardData && event.clipboardData.getData("text")) || "";
      if (text.includes(",")) {
        event.preventDefault();
        const root = target.closest("[data-z-taginput]");
        if (root) addTagValue(root, text);
      }
    }
  });

  document.addEventListener("change", (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement) || !target.hasAttribute("data-z-dropzone-input")) return;
    const root = target.closest("[data-z-dropzone]");
    if (root) updateDropzoneFiles(root, target.files);
  });

  document.addEventListener("dragover", (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const area = target.closest("[data-z-dropzone-area]");
    if (area) {
      event.preventDefault();
      area.classList.add("is-dragover");
    }
  });

  document.addEventListener("dragleave", (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const area = target.closest("[data-z-dropzone-area]");
    if (area && (!event.relatedTarget || !area.contains(event.relatedTarget))) {
      area.classList.remove("is-dragover");
    }
  });

  document.addEventListener("drop", (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const area = target.closest("[data-z-dropzone-area]");
    if (!area) return;
    event.preventDefault();
    area.classList.remove("is-dragover");
    const root = area.closest("[data-z-dropzone]");
    const input = root && root.querySelector("[data-z-dropzone-input]");
    if (root && input instanceof HTMLInputElement && event.dataTransfer && event.dataTransfer.files) {
      let assigned = false;
      try {
        if (typeof DataTransfer === "function") {
          const dt = new DataTransfer();
          const dropped = Array.from(event.dataTransfer.files);
          const subset = input.multiple ? dropped : dropped.slice(0, 1);
          subset.forEach((f) => dt.items.add(f));
          input.files = dt.files;
          assigned = true;
        }
      } catch (_e) {
        assigned = false;
      }
      if (assigned) {
        input.dispatchEvent(new Event("change", { bubbles: true }));
      } else {
        updateDropzoneFiles(root, input.files);
      }
    }
  });

  document.addEventListener("keydown", (event) => {
    const target = event.target;

    // Focus trap inside open aria-modal dialogs
    if (event.key === "Tab") {
      const openOverlays = document.querySelectorAll(".z-modal-backdrop:not([hidden]), .z-drawer-backdrop:not([hidden]), .z-cmdk-backdrop:not([hidden])");
      if (openOverlays.length > 0) {
        const top = openOverlays[openOverlays.length - 1];
        const focusables = visibleFocusables(top);
        if (focusables.length > 0) {
          const first = focusables[0];
          const last = focusables[focusables.length - 1];
          if (event.shiftKey && (document.activeElement === first || !top.contains(document.activeElement))) {
            event.preventDefault();
            last.focus();
            return;
          }
          if (!event.shiftKey && (document.activeElement === last || !top.contains(document.activeElement))) {
            event.preventDefault();
            first.focus();
            return;
          }
        }
      }
    }

    // Keyboard arrow navigation for Tabs (WAI-ARIA Tabs APG)
    if (target instanceof Element && target.matches("[data-z-tab]") && ["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) {
      const group = target.closest("[data-z-tabs]");
      if (group) {
        const tabs = Array.from(group.querySelectorAll("[data-z-tab]")).filter((btn) => btn.closest("[data-z-tabs]") === group);
        const idx = tabs.indexOf(target);
        if (idx >= 0 && tabs.length > 0) {
          event.preventDefault();
          const nextIdx =
            event.key === "ArrowRight" ? (idx + 1) % tabs.length :
            event.key === "ArrowLeft" ? (idx - 1 + tabs.length) % tabs.length :
            event.key === "Home" ? 0 : tabs.length - 1;
          activateTab(group, tabs[nextIdx]);
          tabs[nextIdx].focus();
          return;
        }
      }
    }

    // Keyboard Enter/Space sorting on th[data-z-sort]
    if (target instanceof Element && target.matches("th[data-z-sort]") && (event.key === "Enter" || event.key === " ")) {
      event.preventDefault();
      sortTableByHeader(target);
      return;
    }

    // Combobox keyboard navigation (WAI-ARIA Combobox APG)
    if (target instanceof HTMLInputElement && target.hasAttribute("data-z-combobox-input")) {
      const root = target.closest("[data-z-combobox]");
      const list = root && root.querySelector("[data-z-combobox-list]");
      if (root && list) {
        const options = Array.from(root.querySelectorAll("[data-z-combobox-option]"));
        if (["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) {
          event.preventDefault();
          list.hidden = false;
          target.setAttribute("aria-expanded", "true");
          moveListHighlight(target, options, event.key);
          return;
        }
        if (event.key === "Enter" && !list.hidden) {
          const active = options.find((o) => !o.hidden && o.classList.contains("is-active")) || options.find((o) => !o.hidden);
          if (active) {
            event.preventDefault();
            selectComboboxOption(root, active);
            return;
          }
        }
      }
    }

    // Multi-select keyboard navigation & Backspace removal
    if (target instanceof HTMLInputElement && target.hasAttribute("data-z-ms-input")) {
      const root = target.closest("[data-z-multiselect]");
      const list = root && root.querySelector("[data-z-ms-list]");
      if (root && list) {
        const options = Array.from(root.querySelectorAll("[data-z-ms-option]"));
        if (["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key)) {
          event.preventDefault();
          list.hidden = false;
          target.setAttribute("aria-expanded", "true");
          moveListHighlight(target, options, event.key);
          return;
        }
        if (event.key === "Enter" && !list.hidden) {
          const active = options.find((o) => !o.hidden && o.classList.contains("is-active")) || options.find((o) => !o.hidden);
          if (active) {
            event.preventDefault();
            toggleMultiOption(root, active);
            return;
          }
        }
      }
      if (event.key === "Backspace" && target.value === "") {
        const selected = root ? Array.from(root.querySelectorAll('[data-z-ms-option][aria-selected="true"]')) : [];
        if (root && selected.length > 0) toggleMultiOption(root, selected[selected.length - 1]);
        return;
      }
    }

    // Free-form tag-input Enter / Comma adds tag (IME-safe); Backspace on empty removes last tag
    if (target instanceof HTMLInputElement && target.hasAttribute("data-z-ti-field")) {
      if (event.isComposing) return;
      const root = target.closest("[data-z-taginput]");
      if (root && (event.key === "Enter" || event.key === ",")) {
        event.preventDefault();
        addTagValue(root, target.value);
        target.value = "";
        return;
      }
      if (root && event.key === "Backspace" && target.value === "") {
        const cur = getTagValues(root);
        if (cur.length > 0) {
          cur.pop();
          syncTagInput(root, cur);
        }
        return;
      }
    }

    // OTP Backspace & ArrowLeft/ArrowRight navigation
    if (target instanceof HTMLInputElement && target.hasAttribute("data-z-otp-digit")) {
      const root = target.closest("[data-z-otp]");
      if (root) {
        const digits = Array.from(root.querySelectorAll("[data-z-otp-digit]"));
        const idx = digits.indexOf(target);
        if (event.key === "Backspace" && target.value === "" && idx > 0) {
          digits[idx - 1].value = "";
          digits[idx - 1].focus();
          syncOtp(root);
          return;
        }
        if (event.key === "ArrowLeft" && idx > 0) {
          event.preventDefault();
          digits[idx - 1].focus();
          return;
        }
        if (event.key === "ArrowRight" && idx >= 0 && idx + 1 < digits.length) {
          event.preventDefault();
          digits[idx + 1].focus();
          return;
        }
      }
    }

    // Global Cmd+K / Ctrl+K shortcut toggles the first registered Command Palette
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
      const cmdk = document.querySelector("[data-z-cmdk]");
      if (cmdk) {
        event.preventDefault();
        cmdk.hidden ? showEl(cmdk) : hideEl(cmdk);
      }
      return;
    }

    const openCmdk = document.querySelector("[data-z-cmdk]:not([hidden])");
    if (openCmdk && (event.key === "ArrowDown" || event.key === "ArrowUp" || event.key === "Enter")) {
      const visible = Array.from(openCmdk.querySelectorAll("[data-z-cmdk-item]:not([hidden])"));
      if (visible.length > 0) {
        const curIdx = visible.findIndex((li) => li.getAttribute("aria-selected") === "true");
        if (event.key === "Enter") {
          if (target instanceof HTMLInputElement && target.hasAttribute("data-z-cmdk-input")) {
            const activeLi = visible[curIdx >= 0 ? curIdx : 0];
            const link = activeLi && activeLi.querySelector("a, button");
            if (link && typeof link.click === "function") {
              event.preventDefault();
              link.click();
            }
          }
          return;
        }
        event.preventDefault();
        const delta = event.key === "ArrowDown" ? 1 : -1;
        const nextIdx =
          curIdx < 0
            ? (delta > 0 ? 0 : visible.length - 1)
            : (curIdx + delta + visible.length) % visible.length;
        visible.forEach((li, i) => {
          const isAct = i === nextIdx;
          li.setAttribute("aria-selected", isAct ? "true" : "false");
          const a = li.querySelector(".z-cmdk-item");
          if (a) a.classList.toggle("is-active", isAct);
          if (isAct && typeof li.scrollIntoView === "function") li.scrollIntoView({ block: "nearest" });
        });
      }
      return;
    }

    if (event.key !== "Escape") return;
    let closedDropdown = false;
    document.querySelectorAll("[data-z-combobox]").forEach((root) => {
      const list = root.querySelector("[data-z-combobox-list]");
      if (list && !list.hidden) {
        closeCombobox(root);
        closedDropdown = true;
      }
    });
    document.querySelectorAll("[data-z-multiselect]").forEach((root) => {
      const list = root.querySelector("[data-z-ms-list]");
      if (list && !list.hidden) {
        closeMultiSelect(root);
        closedDropdown = true;
      }
    });
    if (closedDropdown) return;

    const openOverlays = document.querySelectorAll(".z-modal-backdrop:not([hidden]), .z-drawer-backdrop:not([hidden]), .z-cmdk-backdrop:not([hidden])");
    if (openOverlays.length > 0) {
      hideEl(openOverlays[openOverlays.length - 1]);
    }
  });

  function initScope(scope) {
    const root = scope || document;
    initSortableTables(root);
    initSignaturePads(root);
    root.querySelectorAll("[data-z-editor]").forEach(renderEditorPreview);
    syncThemeButtons((document.documentElement.getAttribute("data-zokeibi-theme") || "").trim().toLowerCase());
  }

  window.Zokeibi = { init: initScope, setTheme };

  document.addEventListener("kongono:morphed", (event) => {
    const target = event.target instanceof Element ? event.target : document;
    initScope(target);
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
      restorePersistedTheme();
      initScope(document);
    });
  } else {
    restorePersistedTheme();
    initScope(document);
  }
})();
