/* Zokeibi Vanilla Runtime v1.0.0 — Zero dependencies, Strict-CSP safe, Zero innerHTML XSS sinks */
(() => {
  "use strict";

  const MONTHS = [
    "January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December",
  ];

  function findTarget(selector) {
    if (!selector) return null;
    if (selector.startsWith("#")) return document.getElementById(selector.slice(1));
    return document.getElementById(selector) || document.querySelector(selector);
  }

  function showEl(el) {
    if (!el) return;
    el.hidden = false;
    const focusable = el.querySelector("input:not([type='hidden']), select, textarea, button, [tabindex]:not([tabindex='-1'])");
    if (focusable && typeof focusable.focus === "function") focusable.focus();
  }

  function hideEl(el) {
    if (!el) return;
    el.hidden = true;
  }

  function formatIsoDate(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, "0");
    const d = String(date.getDate()).padStart(2, "0");
    return `${y}-${m}-${d}`;
  }

  function filterCmdk(backdrop, query) {
    const q = (query || "").trim().toLowerCase();
    const items = Array.from(backdrop.querySelectorAll("[data-z-cmdk-item]"));
    let visible = 0;
    items.forEach((li) => {
      const hay = li.getAttribute("data-z-cmdk-item") || li.textContent.toLowerCase();
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

  /* ---- Date Range Calendar ---- */
  function renderDateRange(root) {
    const year = parseInt(root.getAttribute("data-dr-year") || String(new Date().getFullYear()), 10);
    const month = parseInt(root.getAttribute("data-dr-month") || String(new Date().getMonth() + 1), 10);
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
    for (let d = 1; d <= daysInMonth; d++) {
      const iso = `${year}-${String(month).padStart(2, "0")}-${String(d).padStart(2, "0")}`;
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "z-dr-day";
      btn.textContent = String(d);
      btn.setAttribute("data-z-dr-day", iso);
      if (iso === todayIso) btn.classList.add("is-today");
      if (iso === start || (end && iso === end)) {
        btn.classList.add("is-edge");
      } else if (start && end && iso > start && iso < end) {
        btn.classList.add("is-in-range");
      }
      grid.appendChild(btn);
    }
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
  function updateTableCount(wrap) {
    const countEl = wrap.querySelector("[data-z-table-count]");
    const tbody = wrap.querySelector("tbody");
    if (!countEl || !tbody) return;
    const rows = Array.from(tbody.querySelectorAll("tr"));
    const visible = rows.filter((r) => !r.hidden).length;
    countEl.textContent = `Showing ${visible} of ${rows.length} rows`;
  }

  function initSortableTables() {
    document.querySelectorAll("[data-z-table]").forEach((wrap) => {
      if (wrap.getAttribute("data-z-table-sortable") === "true") {
        wrap.querySelectorAll("thead th").forEach((th) => {
          if (!th.hasAttribute("data-z-sort")) th.setAttribute("data-z-sort", "auto");
          if (!th.hasAttribute("tabindex")) th.setAttribute("tabindex", "0");
          if (!th.hasAttribute("aria-sort")) th.setAttribute("aria-sort", "none");
        });
      }
      updateTableCount(wrap);
    });
  }

  function sortTableByHeader(th) {
    const table = th.closest("table");
    const tbody = table && table.querySelector("tbody");
    const row = th.parentElement;
    if (!table || !tbody || !row) return;

    const colIdx = Array.from(row.children).indexOf(th);
    if (colIdx < 0) return;

    const cur = th.getAttribute("aria-sort");
    const dir = cur === "ascending" ? "descending" : "ascending";
    row.querySelectorAll("th[data-z-sort]").forEach((other) => {
      other.setAttribute("aria-sort", other === th ? dir : "none");
    });

    const mode = th.getAttribute("data-z-sort") || "auto";
    const rows = Array.from(tbody.querySelectorAll("tr"));
    const parseVal = (tr) => {
      const cell = tr.children[colIdx];
      return cell ? (cell.textContent || "").trim() : "";
    };
    const toNum = (s) => {
      const cleaned = s.replace(/[$€£¥,%\s]/g, "").replace(/ms$|kb$|mb$|s$/i, "");
      if (cleaned === "" || !/^-?\d+(\.\d+)?$/.test(cleaned)) return NaN;
      return parseFloat(cleaned);
    };

    rows.sort((a, b) => {
      const va = parseVal(a);
      const vb = parseVal(b);
      if (mode !== "string") {
        const na = toNum(va);
        const nb = toNum(vb);
        if (!Number.isNaN(na) && !Number.isNaN(nb)) {
          return dir === "ascending" ? na - nb : nb - na;
        }
      }
      const cmp = va.localeCompare(vb, undefined, { numeric: true, sensitivity: "base" });
      return dir === "ascending" ? cmp : -cmp;
    });

    rows.forEach((r) => tbody.appendChild(r));
  }

  /* ---- XSS-Safe DOM Markdown Previewer ---- */
  function appendInlineMarkdown(parent, text) {
    const tokenRe = /(\*\*([^*]+)\*\*|\*([^*]+)\*|`([^`]+)`|\[([^\]]+)\]\(([^)]+)\))/g;
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
        const safe = href.startsWith("https://") || href.startsWith("http://") || href.startsWith("/") || href.startsWith("#");
        const a = document.createElement("a");
        a.textContent = m[5];
        a.setAttribute("href", safe ? href : "#");
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
      if (/^#{1,3}\s+/.test(trimmed)) {
        const h = document.createElement("h3");
        appendInlineMarkdown(h, trimmed.replace(/^#{1,3}\s+/, ""));
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
    else if (cmd === "link") replacement = `[${sel}](https://kongono.com)`;

    ta.value = ta.value.slice(0, s) + replacement + ta.value.slice(e);
    ta.focus();
    ta.setSelectionRange(s, s + replacement.length);
    renderEditorPreview(root);
  }

  /* ---- OTP Input Sync ---- */
  function syncOtp(root) {
    const hidden = root.querySelector("[data-z-otp-value]");
    const digits = Array.from(root.querySelectorAll("[data-z-otp-digit]"));
    if (hidden) hidden.value = digits.map((d) => d.value || "").join("");
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
    const clean = (raw || "").trim().replace(/,+$/, "").trim();
    if (!clean) return;
    const current = getTagValues(root);
    if (!current.includes(clean)) {
      current.push(clean);
      syncTagInput(root, current);
    }
  }

  /* ---- Global Click Delegation ---- */
  document.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    const openBtn = target.closest("[data-z-open]");
    if (openBtn) {
      showEl(findTarget(openBtn.getAttribute("data-z-open")));
      return;
    }

    const closeBtn = target.closest("[data-z-close]");
    if (closeBtn) {
      const sel = closeBtn.getAttribute("data-z-close");
      hideEl(sel ? findTarget(sel) : closeBtn.closest(".z-modal-backdrop, .z-drawer-backdrop, .z-cmdk-backdrop, .z-toast, .z-banner"));
      return;
    }

    const toggleBtn = target.closest("[data-z-toggle]");
    if (toggleBtn) {
      const el = findTarget(toggleBtn.getAttribute("data-z-toggle"));
      if (el) el.hidden ? showEl(el) : hideEl(el);
      return;
    }

    const tabBtn = target.closest("[data-z-tab]");
    if (tabBtn) {
      const group = tabBtn.closest("[data-z-tabs]");
      const tabId = tabBtn.getAttribute("data-z-tab");
      if (group && tabId) {
        group.querySelectorAll("[data-z-tab]").forEach((btn) => {
          const active = btn.getAttribute("data-z-tab") === tabId;
          btn.classList.toggle("is-active", active);
          btn.setAttribute("aria-selected", active ? "true" : "false");
        });
        group.querySelectorAll("[data-z-panel]").forEach((panel) => {
          panel.hidden = panel.getAttribute("data-z-panel") !== tabId;
        });
      }
      return;
    }

    const comboToggle = target.closest("[data-z-combobox-toggle]");
    if (comboToggle) {
      const root = comboToggle.closest("[data-z-combobox]");
      const list = root && root.querySelector("[data-z-combobox-list]");
      const input = root && root.querySelector("[data-z-combobox-input]");
      if (list && input) {
        list.hidden = !list.hidden;
        input.setAttribute("aria-expanded", list.hidden ? "false" : "true");
        if (!list.hidden) input.focus();
      }
      return;
    }

    const comboOpt = target.closest("[data-z-combobox-option]");
    if (comboOpt) {
      const root = comboOpt.closest("[data-z-combobox]");
      if (root) {
        const val = comboOpt.getAttribute("data-value") || "";
        const lbl = comboOpt.getAttribute("data-label") || comboOpt.textContent.trim();
        const hiddenInput = root.querySelector("[data-z-combobox-value]");
        const textInput = root.querySelector("[data-z-combobox-input]");
        const list = root.querySelector("[data-z-combobox-list]");
        if (hiddenInput) hiddenInput.value = val;
        if (textInput) {
          textInput.value = lbl;
          textInput.setAttribute("aria-expanded", "false");
        }
        root.querySelectorAll("[data-z-combobox-option]").forEach((opt) => {
          opt.setAttribute("aria-selected", opt === comboOpt ? "true" : "false");
        });
        if (list) list.hidden = true;
      }
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
      }
      return;
    }

    const tiRm = target.closest("[data-z-ti-remove]");
    if (tiRm) {
      const root = tiRm.closest("[data-z-taginput]");
      const val = tiRm.getAttribute("data-z-ti-remove");
      if (root && val) {
        syncTagInput(root, getTagValues(root).filter((t) => t !== val));
      }
      return;
    }

    const numBtn = target.closest("[data-z-num-dec], [data-z-num-inc]");
    if (numBtn) {
      const root = numBtn.closest("[data-z-number]");
      const inp = root && root.querySelector("[data-z-num-input]");
      if (inp) {
        const step = parseFloat(inp.step || "1") || 1;
        const min = inp.min !== "" ? parseFloat(inp.min) : -Infinity;
        const max = inp.max !== "" ? parseFloat(inp.max) : Infinity;
        const cur = parseFloat(inp.value || "0") || 0;
        const delta = numBtn.hasAttribute("data-z-num-inc") ? step : -step;
        const next = Math.max(min, Math.min(max, Math.round((cur + delta) * 1000) / 1000));
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
      if (inp) {
        const showing = inp.type === "text";
        inp.type = showing ? "password" : "text";
        pwToggle.textContent = showing ? "Show" : "Hide";
      }
      return;
    }

    const datePreset = target.closest("[data-z-date-offset]");
    if (datePreset) {
      const root = datePreset.closest("[data-z-datepicker]");
      const input = root && root.querySelector("[data-z-date-input]");
      if (input) {
        const days = parseInt(datePreset.getAttribute("data-z-date-offset") || "0", 10);
        const dt = new Date();
        dt.setDate(dt.getDate() + (Number.isNaN(days) ? 0 : days));
        input.value = formatIsoDate(dt);
        input.dispatchEvent(new Event("change", { bubbles: true }));
      }
      return;
    }

    const drNav = target.closest("[data-z-dr-nav]");
    if (drNav) {
      const root = drNav.closest("[data-z-daterange]");
      if (root) {
        let y = parseInt(root.getAttribute("data-dr-year") || String(new Date().getFullYear()), 10);
        let m = parseInt(root.getAttribute("data-dr-month") || String(new Date().getMonth() + 1), 10);
        const delta = parseInt(drNav.getAttribute("data-z-dr-nav") || "0", 10);
        m += delta;
        if (m < 1) { m = 12; y--; }
        if (m > 12) { m = 1; y++; }
        root.setAttribute("data-dr-year", String(y));
        root.setAttribute("data-dr-month", String(m));
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
          renderDateRange(root);
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
        if (startInput) startInput.value = formatIsoDate(startDt);
        if (endInput) endInput.value = formatIsoDate(endDt);
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
          b.classList.toggle("is-active", b === edMode);
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
      }
      return;
    }

    const copyBtn = target.closest("[data-z-copy-btn]");
    if (copyBtn) {
      const root = copyBtn.closest("[data-z-copy]");
      const status = root && root.querySelector("[data-z-copy-status]");
      const val = (root && root.getAttribute("data-copy-value")) || "";
      const announce = () => {
        if (status) status.textContent = "Copied to clipboard";
        const orig = copyBtn.textContent;
        copyBtn.textContent = "Copied!";
        window.setTimeout(() => {
          copyBtn.textContent = orig;
          if (status) status.textContent = "";
        }, 1600);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(val).then(announce).catch(announce);
      } else {
        announce();
      }
      return;
    }

    // Close open comboboxes & multi-selects on outside click
    document.querySelectorAll("[data-z-combobox]").forEach((root) => {
      if (!root.contains(target)) {
        const list = root.querySelector("[data-z-combobox-list]");
        const input = root.querySelector("[data-z-combobox-input]");
        if (list) list.hidden = true;
        if (input) input.setAttribute("aria-expanded", "false");
      }
    });
    document.querySelectorAll("[data-z-multiselect]").forEach((root) => {
      if (!root.contains(target)) {
        const list = root.querySelector("[data-z-ms-list]");
        const input = root.querySelector("[data-z-ms-input]");
        if (list) list.hidden = true;
        if (input) input.setAttribute("aria-expanded", "false");
      }
    });

    if (
      target.classList.contains("z-modal-backdrop") ||
      target.classList.contains("z-drawer-backdrop") ||
      target.classList.contains("z-cmdk-backdrop")
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
      if (root && list) {
        const q = target.value.trim().toLowerCase();
        list.hidden = false;
        target.setAttribute("aria-expanded", "true");
        root.querySelectorAll("[data-z-combobox-option]").forEach((opt) => {
          const hay = ((opt.getAttribute("data-label") || "") + " " + (opt.getAttribute("data-value") || "")).toLowerCase();
          opt.hidden = q !== "" && !hay.includes(q);
        });
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
        });
      }
      return;
    }

    if (target.hasAttribute("data-z-table-search")) {
      const wrap = target.closest("[data-z-table]");
      const tbody = wrap && wrap.querySelector("tbody");
      if (wrap && tbody) {
        const q = target.value.trim().toLowerCase();
        tbody.querySelectorAll("tr").forEach((tr) => {
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
      target.value = clean.slice(0, 1);
      if (root) {
        syncOtp(root);
        if (target.value !== "") {
          const digits = Array.from(root.querySelectorAll("[data-z-otp-digit]"));
          const idx = digits.indexOf(target);
          if (idx >= 0 && idx + 1 < digits.length) digits[idx + 1].focus();
        }
      }
    }
  });

  document.addEventListener("paste", (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement) || !target.hasAttribute("data-z-otp-digit")) return;
    const text = (event.clipboardData && event.clipboardData.getData("text")) || "";
    const nums = text.replace(/\D/g, "");
    if (nums.length > 1) {
      event.preventDefault();
      const root = target.closest("[data-z-otp]");
      if (root) {
        const digits = Array.from(root.querySelectorAll("[data-z-otp-digit]"));
        digits.forEach((d, i) => {
          d.value = nums[i] || "";
        });
        syncOtp(root);
        const focusIdx = Math.min(nums.length, digits.length) - 1;
        if (focusIdx >= 0) digits[focusIdx].focus();
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
    if (area) area.classList.remove("is-dragover");
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
    if (root && input && event.dataTransfer && event.dataTransfer.files) {
      try {
        input.files = event.dataTransfer.files;
      } catch (_e) {
        // Fallback: still display selected file chips when DataTransfer assignment is restricted
      }
      updateDropzoneFiles(root, event.dataTransfer.files);
    }
  });

  document.addEventListener("keydown", (event) => {
    const target = event.target;

    // Keyboard Enter/Space sorting on th[data-z-sort]
    if (target instanceof Element && target.matches("th[data-z-sort]") && (event.key === "Enter" || event.key === " ")) {
      event.preventDefault();
      sortTableByHeader(target);
      return;
    }

    // Multi-select Backspace removes last selected chip when search input is empty
    if (target instanceof HTMLInputElement && target.hasAttribute("data-z-ms-input") && event.key === "Backspace" && target.value === "") {
      const root = target.closest("[data-z-multiselect]");
      const selected = root ? Array.from(root.querySelectorAll('[data-z-ms-option][aria-selected="true"]')) : [];
      if (root && selected.length > 0) toggleMultiOption(root, selected[selected.length - 1]);
      return;
    }

    // Free-form tag-input Enter / Comma adds tag; Backspace on empty removes last tag
    if (target instanceof HTMLInputElement && target.hasAttribute("data-z-ti-field")) {
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

    // OTP Backspace jumps to previous box when empty
    if (target instanceof HTMLInputElement && target.hasAttribute("data-z-otp-digit") && event.key === "Backspace" && target.value === "") {
      const root = target.closest("[data-z-otp]");
      if (root) {
        const digits = Array.from(root.querySelectorAll("[data-z-otp-digit]"));
        const idx = digits.indexOf(target);
        if (idx > 0) {
          digits[idx - 1].value = "";
          digits[idx - 1].focus();
          syncOtp(root);
        }
      }
      return;
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
          const activeLi = visible[curIdx >= 0 ? curIdx : 0];
          const link = activeLi && activeLi.querySelector("a, button");
          if (link && typeof link.click === "function") {
            event.preventDefault();
            link.click();
          }
          return;
        }
        event.preventDefault();
        const delta = event.key === "ArrowDown" ? 1 : -1;
        const nextIdx = (curIdx + delta + visible.length) % visible.length;
        visible.forEach((li, i) => {
          const isAct = i === nextIdx;
          li.setAttribute("aria-selected", isAct ? "true" : "false");
          const a = li.querySelector(".z-cmdk-item");
          if (a) a.classList.toggle("is-active", isAct);
        });
      }
      return;
    }

    if (event.key !== "Escape") return;
    document.querySelectorAll("[data-z-combobox]").forEach((root) => {
      const list = root.querySelector("[data-z-combobox-list]");
      const input = root.querySelector("[data-z-combobox-input]");
      if (list && !list.hidden) {
        list.hidden = true;
        if (input) input.setAttribute("aria-expanded", "false");
      }
    });
    document.querySelectorAll("[data-z-multiselect]").forEach((root) => {
      const list = root.querySelector("[data-z-ms-list]");
      const input = root.querySelector("[data-z-ms-input]");
      if (list && !list.hidden) {
        list.hidden = true;
        if (input) input.setAttribute("aria-expanded", "false");
      }
    });
    const openOverlays = document.querySelectorAll(".z-modal-backdrop:not([hidden]), .z-drawer-backdrop:not([hidden]), .z-cmdk-backdrop:not([hidden])");
    if (openOverlays.length > 0) {
      hideEl(openOverlays[openOverlays.length - 1]);
    }
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
      initSortableTables();
      document.querySelectorAll("[data-z-editor]").forEach(renderEditorPreview);
    });
  } else {
    initSortableTables();
    document.querySelectorAll("[data-z-editor]").forEach(renderEditorPreview);
  }
})();
