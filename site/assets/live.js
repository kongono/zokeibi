(() => {
  "use strict";
  if (window.KongonoLive) return;

  const METHODS = ["get", "post", "put", "patch", "delete"];
  const BLOCKED_KEYS = new Set(["__proto__", "prototype", "constructor", "window", "document", "globalThis", "Function"]);
  const URL_ATTRS = new Set(["href", "src", "action", "formaction", "poster", "data", "cite", "ping"]);
  const BLOCKED_ATTRS = new Set(["srcdoc", "style", "srcset", "is", "xmlns", "codebase", "archive", "classid", "xlink:href"]);
  const SENSITIVE_QUEUE_RE = /password|passwd|passphrase|secret|token|_csrf|csrf|cvv|cvc|ssn|otp|totp|card/i;
  const MAX_AST_CACHE = 512;
  const MAX_TOKENS = 128;
  const MAX_DEPTH = 24;
  const MAX_PREFETCH = 40;
  const PREFETCH_TTL_MS = 15000;
  const OFFLINE_QUEUE_KEY = "kongono:offline-queue";

  const streams = new WeakMap();
  const islands = new WeakMap();
  const timers = new WeakMap();
  const sortables = new WeakMap();
  const prefetchedVisible = new WeakSet();
  const astCache = new Map();
  const prefetchCache = new Map();
  const inflightPrefetch = new Map();
  const customActions = Object.create(null);
  let islandSeq = 0;
  let flushingQueue = false;

  const PIPES = Object.create(null);
  PIPES.currency = (val, code = "USD", locale = "en-US") => {
    const n = Number(val);
    if (!Number.isFinite(n)) return "";
    const cur = String(code || "USD").toUpperCase();
    const loc = String(locale || "en-US");
    const isWhole = Math.abs(n - Math.round(n)) < 1e-9;
    try {
      return new Intl.NumberFormat(loc, {
        style: "currency",
        currency: cur,
        minimumFractionDigits: isWhole ? 0 : 2,
        maximumFractionDigits: 2
      }).format(n);
    } catch (_e) {
      return cur + " " + (isWhole ? String(Math.round(n)) : n.toFixed(2));
    }
  };
  PIPES.number = (val, locale = "en-US") => {
    const n = Number(val);
    if (!Number.isFinite(n)) return "0";
    try { return new Intl.NumberFormat(String(locale || "en-US")).format(n); } catch (_e) { return String(n); }
  };
  PIPES.fixed = (val, digits = 2) => {
    const n = Number(val);
    if (!Number.isFinite(n)) return "0";
    const d = Math.max(0, Math.min(6, Math.trunc(Number(digits) || 0)));
    return n.toFixed(d);
  };
  PIPES.round = (val) => Math.round(Number(val) || 0);
  PIPES.floor = (val) => Math.floor(Number(val) || 0);
  PIPES.ceil = (val) => Math.ceil(Number(val) || 0);
  PIPES.abs = (val) => Math.abs(Number(val) || 0);
  PIPES.percent = (val, digits = 0) => {
    const n = Number(val);
    if (!Number.isFinite(n)) return "0%";
    const d = Math.max(0, Math.min(4, Math.trunc(Number(digits) || 0)));
    return (n * 100).toFixed(d) + "%";
  };
  PIPES.upper = (val) => String(val == null ? "" : val).toUpperCase();
  PIPES.lower = (val) => String(val == null ? "" : val).toLowerCase();
  PIPES.trim = (val) => String(val == null ? "" : val).trim();
  PIPES.plural = (val, singular = "", pluralForm = "") => {
    const n = Number(val);
    const num = Number.isFinite(n) ? n : 0;
    const s = String(singular || "");
    const p = String(pluralForm || (s ? s + "s" : ""));
    return String(num) + " " + (num === 1 ? s : p);
  };
  PIPES.default = (val, fallback = "") => (val === null || val === undefined || val === "" ? fallback : val);

  function cloneSafe(val) {
    if (Array.isArray(val)) return val.map(cloneSafe);
    if (val && typeof val === "object") {
      const out = Object.create(null);
      for (const k of Object.keys(val)) {
        if (!BLOCKED_KEYS.has(k)) out[k] = cloneSafe(val[k]);
      }
      return out;
    }
    return val;
  }

  function isSafeAttrUrl(raw, attrName) {
    const rawStr = String(raw == null ? "" : raw);
    if (/[\u0000-\u001F\u007F\\]/.test(rawStr)) return false;
    const v = rawStr.trim();
    if (!v) return false;
    if (v.startsWith("//")) return false;
    if (v.startsWith("/") || v.startsWith("#")) return true;
    const m = /^([a-zA-Z][a-zA-Z0-9+.-]*):/.exec(v);
    if (!m) return !v.includes(":");
    const scheme = m[1].toLowerCase();
    if (scheme === "https" || scheme === "http") {
      return v.toLowerCase().startsWith(scheme + "://");
    }
    if (scheme === "mailto") {
      return !attrName || String(attrName).toLowerCase() === "href";
    }
    return false;
  }

  function isSameOriginUrl(rawUrl) {
    if (!rawUrl || /[\u0000-\u001F\u007F]/.test(rawUrl)) return null;
    try {
      const parsed = new URL(rawUrl, window.location.origin);
      if (parsed.origin !== window.location.origin) return null;
      return parsed;
    } catch (_e) {
      return null;
    }
  }

  function getPath(state, path) {
    const parts = String(path || "").trim().split(".");
    let cur = state;
    for (const p of parts) {
      if (!p || BLOCKED_KEYS.has(p) || cur == null) return undefined;
      if (p === "length" && (Array.isArray(cur) || typeof cur === "string")) return cur.length;
      if (typeof cur !== "object") return undefined;
      if (!Object.prototype.hasOwnProperty.call(cur, p)) return undefined;
      cur = cur[p];
    }
    return cur;
  }

  function setPath(state, path, value) {
    const parts = String(path || "").trim().split(".");
    if (parts.length === 0) return;
    let cur = state;
    for (let i = 0; i < parts.length - 1; i++) {
      const p = parts[i];
      if (!p || BLOCKED_KEYS.has(p)) return;
      if (!cur[p] || typeof cur[p] !== "object") cur[p] = Object.create(null);
      cur = cur[p];
    }
    const leaf = parts[parts.length - 1];
    if (!leaf || BLOCKED_KEYS.has(leaf)) return;
    cur[leaf] = value;
  }

  // Zero-eval recursive-descent AST tokenizer & parser (arithmetic, parens, ternary, comparisons, pipes)
  function tokenize(src) {
    const tokens = [];
    let i = 0;
    const len = src.length;
    while (i < len && tokens.length < MAX_TOKENS) {
      const ch = src[i];
      if (ch === " " || ch === "\t" || ch === "\n" || ch === "\r") { i++; continue; }
      const three = src.slice(i, i + 3);
      if (three === "===" || three === "!==") { tokens.push({ t: "op", v: three }); i += 3; continue; }
      const two = src.slice(i, i + 2);
      if (two === "==" || two === "!=" || two === ">=" || two === "<=" || two === "&&" || two === "||" || two === "??") {
        tokens.push({ t: "op", v: two }); i += 2; continue;
      }
      if ("+-*/%!<>?:(),|".includes(ch)) {
        tokens.push({ t: ch === "|" ? "pipe" : "punc", v: ch }); i++; continue;
      }
      if (ch === "'" || ch === '"') {
        const q = ch;
        let s = "";
        i++;
        while (i < len && src[i] !== q) {
          if (src[i] === "\\" && i + 1 < len) { s += src[i + 1]; i += 2; }
          else { s += src[i]; i++; }
        }
        if (i < len && src[i] === q) i++;
        tokens.push({ t: "str", v: s });
        continue;
      }
      if ((ch >= "0" && ch <= "9") || (ch === "." && i + 1 < len && src[i + 1] >= "0" && src[i + 1] <= "9")) {
        let num = "";
        let dot = false;
        while (i < len && ((src[i] >= "0" && src[i] <= "9") || (!dot && src[i] === "."))) {
          if (src[i] === ".") dot = true;
          num += src[i++];
        }
        tokens.push({ t: "num", v: Number(num) });
        continue;
      }
      if (/[a-zA-Z_$]/.test(ch)) {
        let id = "";
        while (i < len && /[a-zA-Z0-9_$.]/.test(src[i])) id += src[i++];
        if (id === "true") tokens.push({ t: "lit", v: true });
        else if (id === "false") tokens.push({ t: "lit", v: false });
        else if (id === "null") tokens.push({ t: "lit", v: null });
        else tokens.push({ t: "id", v: id });
        continue;
      }
      i++;
    }
    return tokens;
  }

  function compileExpr(raw) {
    const key = String(raw || "").trim();
    if (!key) return null;
    if (astCache.has(key)) return astCache.get(key);
    const tokens = tokenize(key);
    let pos = 0;
    let depth = 0;
    const peek = () => tokens[pos] || null;
    const next = () => tokens[pos++] || null;
    const match = (t, v) => {
      const tk = peek();
      if (tk && tk.t === t && (v === undefined || tk.v === v)) { pos++; return tk; }
      return null;
    };

    function parsePipes() {
      let node = parseTernary();
      while (match("pipe")) {
        const nameTk = match("id");
        if (!nameTk) break;
        const args = [];
        if (match("punc", "(")) {
          if (!match("punc", ")")) {
            do {
              args.push(parseTernary());
            } while (match("punc", ","));
            match("punc", ")");
          }
        }
        node = { k: "pipe", name: nameTk.v, expr: node, args };
      }
      return node;
    }

    function parseTernary() {
      const cond = parseNullish();
      if (match("punc", "?")) {
        const yes = parseTernary();
        match("punc", ":");
        const no = parseTernary();
        return { k: "tern", c: cond, y: yes, n: no };
      }
      return cond;
    }

    function parseNullish() {
      let left = parseOr();
      while (match("op", "??")) left = { k: "bin", op: "??", l: left, r: parseOr() };
      return left;
    }

    function parseOr() {
      let left = parseAnd();
      while (match("op", "||")) left = { k: "bin", op: "||", l: left, r: parseAnd() };
      return left;
    }

    function parseAnd() {
      let left = parseCmp();
      while (match("op", "&&")) left = { k: "bin", op: "&&", l: left, r: parseCmp() };
      return left;
    }

    function parseCmp() {
      let left = parseAdd();
      const tk = peek();
      if (tk && ((tk.t === "op" && (tk.v === "===" || tk.v === "!==" || tk.v === "==" || tk.v === "!=" || tk.v === ">=" || tk.v === "<=")) || (tk.t === "punc" && (tk.v === ">" || tk.v === "<")))) {
        next();
        left = { k: "bin", op: tk.v, l: left, r: parseAdd() };
      }
      return left;
    }

    function parseAdd() {
      let left = parseMul();
      while (peek() && peek().t === "punc" && (peek().v === "+" || peek().v === "-")) {
        const op = next().v;
        left = { k: "bin", op, l: left, r: parseMul() };
      }
      return left;
    }

    function parseMul() {
      let left = parseUnary();
      while (peek() && peek().t === "punc" && (peek().v === "*" || peek().v === "/" || peek().v === "%")) {
        const op = next().v;
        left = { k: "bin", op, l: left, r: parseUnary() };
      }
      return left;
    }

    function parseUnary() {
      if (depth >= MAX_DEPTH) return { k: "lit", v: undefined };
      const tk = peek();
      if (tk && tk.t === "punc" && (tk.v === "!" || tk.v === "-")) {
        next();
        depth++;
        const arg = parseUnary();
        depth--;
        return { k: "un", op: tk.v, a: arg };
      }
      return parsePrimary();
    }

    function parsePrimary() {
      if (depth >= MAX_DEPTH) return { k: "lit", v: undefined };
      const tk = peek();
      if (!tk) return { k: "lit", v: undefined };
      if (tk.t === "num" || tk.t === "str" || tk.t === "lit") { next(); return { k: "lit", v: tk.v }; }
      if (tk.t === "id") { next(); return { k: "path", p: tk.v }; }
      if (tk.t === "punc" && tk.v === "(") {
        next();
        depth++;
        const inner = parsePipes();
        depth--;
        match("punc", ")");
        return inner;
      }
      next();
      return { k: "lit", v: undefined };
    }

    const ast = parsePipes();
    if (astCache.size >= MAX_AST_CACHE) {
      const oldest = astCache.keys().next().value;
      if (oldest !== undefined) astCache.delete(oldest);
    }
    astCache.set(key, ast);
    return ast;
  }

  function evalAst(node, state) {
    if (!node) return undefined;
    switch (node.k) {
      case "lit": return node.v;
      case "path": return getPath(state, node.p);
      case "un": {
        const v = evalAst(node.a, state);
        return node.op === "!" ? !v : -(Number(v) || 0);
      }
      case "tern":
        return evalAst(node.c, state) ? evalAst(node.y, state) : evalAst(node.n, state);
      case "pipe": {
        const base = evalAst(node.expr, state);
        const fn = PIPES[node.name];
        if (typeof fn !== "function") return base;
        const args = node.args.map((a) => evalAst(a, state));
        return fn(base, ...args);
      }
      case "bin": {
        if (node.op === "&&") { const l = evalAst(node.l, state); return l ? evalAst(node.r, state) : l; }
        if (node.op === "||") { const l = evalAst(node.l, state); return l ? l : evalAst(node.r, state); }
        if (node.op === "??") {
          const l = evalAst(node.l, state);
          return (l !== null && l !== undefined) ? l : evalAst(node.r, state);
        }
        const l = evalAst(node.l, state);
        const r = evalAst(node.r, state);
        switch (node.op) {
          case "===": return l === r;
          case "!==": return l !== r;
          case "==": return l === r || String(l) === String(r);
          case "!=": return !(l === r || String(l) === String(r));
          case ">=": return Number(l) >= Number(r);
          case "<=": return Number(l) <= Number(r);
          case ">": return Number(l) > Number(r);
          case "<": return Number(l) < Number(r);
          case "+": {
            if (typeof l === "string" || typeof r === "string") return String(l == null ? "" : l) + String(r == null ? "" : r);
            const s = (Number(l) || 0) + (Number(r) || 0);
            return Number.isFinite(s) ? s : 0;
          }
          case "-": { const s = (Number(l) || 0) - (Number(r) || 0); return Number.isFinite(s) ? s : 0; }
          case "*": { const s = (Number(l) || 0) * (Number(r) || 0); return Number.isFinite(s) ? s : 0; }
          case "/": { const d = Number(r) || 0; return d === 0 ? 0 : ((Number(l) || 0) / d); }
          case "%": { const d = Number(r) || 0; return d === 0 ? 0 : ((Number(l) || 0) % d); }
        }
      }
    }
    return undefined;
  }

  function evalExpr(rawExpr, state) {
    const ast = compileExpr(rawExpr);
    return ast ? evalAst(ast, state) : undefined;
  }

  function storageFor(mode) {
    try {
      if (mode === "local") return window.localStorage;
      if (mode === "session") return window.sessionStorage;
    } catch (_e) {}
    return null;
  }

  function persistKeyFor(root) {
    const explicit = root.getAttribute("k-persist-key");
    if (explicit) return "kongono:state:" + explicit;
    const id = root.id || ("island-" + (++islandSeq));
    return "kongono:state:" + window.location.pathname + ":" + id;
  }

  function saveIsland(island) {
    if (!island || !island.persistStore || !island.persistKey) return;
    try {
      island.persistStore.setItem(island.persistKey, JSON.stringify(island.state));
    } catch (_e) {}
  }

  function findIsland(el) {
    const host = el && el.closest ? el.closest("[k-state]") : null;
    return host ? islands.get(host) || initIsland(host) : null;
  }

  function renderIsland(island) {
    const { root, state } = island;
    const walk = (node) => {
      if (!node || node.nodeType !== 1) return;
      if (node !== root && node.hasAttribute("k-state")) return;

      if (node.hasAttribute("k-text")) {
        const val = evalExpr(node.getAttribute("k-text"), state);
        const text = val == null ? "" : String(val);
        if (node.textContent !== text) node.textContent = text;
      }
      if (node.hasAttribute("k-show")) {
        const show = Boolean(evalExpr(node.getAttribute("k-show"), state));
        node.hidden = !show;
        node.style.display = show ? "" : "none";
      }
      if (node.hasAttribute("k-hide")) {
        const hide = Boolean(evalExpr(node.getAttribute("k-hide"), state));
        node.hidden = hide;
        node.style.display = hide ? "none" : "";
      }
      if (node.hasAttribute("k-model")) {
        const path = node.getAttribute("k-model") || "";
        const val = getPath(state, path);
        if (node.type === "checkbox") {
          node.checked = Boolean(val);
        } else if (node.type === "radio") {
          node.checked = String(val) === node.value;
        } else if (document.activeElement !== node) {
          const str = val == null ? "" : String(val);
          if (node.value !== str) node.value = str;
        }
      }
      if (node.hasAttribute("k-filter")) {
        const q = String(evalExpr(node.getAttribute("k-filter"), state) || "").trim().toLowerCase();
        const hay = String(node.getAttribute("data-filter-text") || node.textContent || "").toLowerCase();
        const match = !q || hay.includes(q);
        node.hidden = !match;
        node.style.display = match ? "" : "none";
      }
      for (const attr of Array.from(node.attributes)) {
        if (attr.name.startsWith("k-class:")) {
          const cls = attr.name.slice(8);
          if (cls) node.classList.toggle(cls, Boolean(evalExpr(attr.value, state)));
        } else if (attr.name.startsWith("k-attr:")) {
          const targetAttr = attr.name.slice(7).toLowerCase();
          if (
            !targetAttr
            || targetAttr.includes(":")
            || targetAttr.startsWith("on")
            || targetAttr.startsWith("k-")
            || targetAttr.startsWith("kgn-")
            || BLOCKED_ATTRS.has(targetAttr)
          ) continue;
          const v = evalExpr(attr.value, state);
          if (v === false || v === null || v === undefined) {
            node.removeAttribute(targetAttr);
          } else {
            const strVal = v === true ? "true" : String(v);
            if (URL_ATTRS.has(targetAttr)) {
              if (!isSafeAttrUrl(strVal, targetAttr)) continue;
              node.setAttribute(targetAttr, strVal.trim());
              continue;
            }
            node.setAttribute(targetAttr, strVal);
          }
        }
      }
      for (const child of node.children) walk(child);
    };
    walk(root);
  }

  function initIsland(root) {
    if (!root) return null;
    if (islands.has(root)) {
      const existing = islands.get(root);
      renderIsland(existing);
      return existing;
    }
    let initial = Object.create(null);
    try {
      const parsed = JSON.parse(root.getAttribute("k-state") || "{}");
      if (parsed && typeof parsed === "object" && !Array.isArray(parsed)) {
        initial = cloneSafe(parsed);
      }
    } catch (_e) {}

    const persistMode = (root.getAttribute("k-persist") || "").trim().toLowerCase();
    const persistStore = storageFor(persistMode);
    const persistKey = persistStore ? persistKeyFor(root) : null;
    const state = cloneSafe(initial);
    if (persistStore && persistKey) {
      try {
        const savedRaw = persistStore.getItem(persistKey);
        if (savedRaw) {
          const saved = JSON.parse(savedRaw);
          if (saved && typeof saved === "object" && !Array.isArray(saved)) {
            const safeSaved = cloneSafe(saved);
            for (const k of Object.keys(initial)) {
              if (k in safeSaved && !BLOCKED_KEYS.has(k)) state[k] = safeSaved[k];
            }
          }
        }
      } catch (_e) {}
    }

    const island = { root, state, initial: cloneSafe(initial), persistStore, persistKey };
    islands.set(root, island);
    renderIsland(island);
    return island;
  }

  function splitDelimited(raw, delimiter) {
    const out = [];
    let cur = "";
    let depth = 0;
    let quote = null;
    for (let i = 0; i < raw.length; i++) {
      const ch = raw[i];
      if (quote) {
        cur += ch;
        if (ch === "\\" && i + 1 < raw.length) { cur += raw[++i]; continue; }
        if (ch === quote) quote = null;
        continue;
      }
      if (ch === "'" || ch === '"') { quote = ch; cur += ch; continue; }
      if (ch === "(") { depth++; cur += ch; continue; }
      if (ch === ")") { depth = Math.max(0, depth - 1); cur += ch; continue; }
      if (ch === delimiter && depth === 0) {
        if (cur.trim() !== "") out.push(cur.trim());
        cur = "";
        continue;
      }
      cur += ch;
    }
    if (cur.trim() !== "") out.push(cur.trim());
    return out;
  }

  function splitTopLevelArgs(raw) {
    return splitDelimited(raw, ",");
  }

  function runActionList(rawActions, island, el, ev) {
    const statements = splitDelimited(String(rawActions || ""), ";");
    let dirty = false;
    for (const stmt of statements) {
      const m = /^([a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*)\)$/.exec(stmt);
      if (!m) continue;
      const fn = m[1];
      const args = m[2].trim() ? splitTopLevelArgs(m[2]) : [];
      if (fn === "toggle" && args[0]) {
        setPath(island.state, args[0], !Boolean(getPath(island.state, args[0])));
        dirty = true;
      } else if (fn === "set" && args[0] && args.length >= 2) {
        setPath(island.state, args[0], evalExpr(args.slice(1).join(","), island.state));
        dirty = true;
      } else if (fn === "inc" && args[0]) {
        const step = args[1] ? Number(evalExpr(args[1], island.state)) : 1;
        setPath(island.state, args[0], Number(getPath(island.state, args[0]) || 0) + (Number.isFinite(step) ? step : 1));
        dirty = true;
      } else if (fn === "dec" && args[0]) {
        const step = args[1] ? Number(evalExpr(args[1], island.state)) : 1;
        setPath(island.state, args[0], Number(getPath(island.state, args[0]) || 0) - (Number.isFinite(step) ? step : 1));
        dirty = true;
      } else if (fn === "reset") {
        if (args[0]) {
          setPath(island.state, args[0], cloneSafe(getPath(island.initial, args[0])));
        } else {
          island.state = cloneSafe(island.initial);
        }
        dirty = true;
      } else if (fn === "dispatch" && args[0]) {
        const name = String(evalExpr(args[0], island.state) || args[0]);
        el.dispatchEvent(new CustomEvent(name, { bubbles: true, detail: { state: cloneSafe(island.state) } }));
      } else if (fn === "call" && args[0]) {
        const name = String(evalExpr(args[0], island.state) || args[0]);
        if (typeof customActions[name] === "function") {
          customActions[name](island.state, { el, ev, root: island.root });
          dirty = true;
        }
      }
    }
    if (dirty) {
      saveIsland(island);
      renderIsland(island);
    }
  }

  function handleSignalEvent(eventType, ev) {
    let cur = ev.target && ev.target.nodeType === 1 ? ev.target : null;
    while (cur) {
      for (const attr of Array.from(cur.attributes || [])) {
        if (!attr.name.startsWith("k-on:")) continue;
        const parts = attr.name.slice(5).split(".");
        if (parts[0] !== eventType) continue;
        const mods = new Set(parts.slice(1));
        if (mods.has("self") && ev.target !== cur) continue;
        if (mods.has("enter") && ev.key !== "Enter") continue;
        if (mods.has("escape") && ev.key !== "Escape") continue;
        if (mods.has("prevent")) ev.preventDefault();
        if (mods.has("stop")) ev.stopPropagation();
        const island = findIsland(cur);
        if (island) runActionList(attr.value, island, cur, ev);
      }
      cur = cur.parentElement;
    }
  }

  // Keyed DOM Morphing preserving active input focus, dirty values, k-ignore subtrees, and Hybrid Bridge Island props
  function nodeKey(node) {
    if (!node || node.nodeType !== 1) return null;
    return node.getAttribute("k-key") || node.id || null;
  }

  function syncAttributes(fromEl, toEl) {
    const isFormField = fromEl.tagName === "INPUT" || fromEl.tagName === "TEXTAREA" || fromEl.tagName === "SELECT";
    const preserveInput = isFormField && !toEl.hasAttribute("k-sync") && (
      document.activeElement === fromEl ||
      ((fromEl.type === "checkbox" || fromEl.type === "radio")
        ? fromEl.checked !== fromEl.defaultChecked
        : fromEl.value !== fromEl.defaultValue)
    );

    for (const attr of Array.from(fromEl.attributes)) {
      if (preserveInput && (attr.name === "value" || attr.name === "checked")) continue;
      if (!toEl.hasAttribute(attr.name)) fromEl.removeAttribute(attr.name);
    }
    for (const attr of Array.from(toEl.attributes)) {
      if (preserveInput && (attr.name === "value" || attr.name === "checked")) continue;
      if (fromEl.getAttribute(attr.name) !== attr.value) {
        fromEl.setAttribute(attr.name, attr.value);
      }
    }
    if (isFormField && !preserveInput) {
      if (fromEl.type === "checkbox" || fromEl.type === "radio") {
        fromEl.checked = toEl.hasAttribute("checked");
      } else if ("value" in toEl && fromEl.value !== toEl.value) {
        fromEl.value = toEl.value;
      }
    }
  }

  function morphNode(fromNode, toNode) {
    if (
      fromNode.nodeType === 1
      && fromNode.hasAttribute("data-k-island")
      && toNode.nodeType === 1
      && toNode.hasAttribute("data-k-island")
      && fromNode.getAttribute("data-k-island") === toNode.getAttribute("data-k-island")
    ) {
      const nextPropsRaw = toNode.getAttribute("data-k-props") || "{}";
      if (fromNode.getAttribute("data-k-props") !== nextPropsRaw) {
        fromNode.setAttribute("data-k-props", nextPropsRaw);
        let parsed = {};
        try { parsed = JSON.parse(nextPropsRaw); } catch (_e) {}
        fromNode.dispatchEvent(new CustomEvent("kongono:island-props", {
          bubbles: true,
          detail: { component: fromNode.getAttribute("data-k-island") || "", props: parsed }
        }));
      }
      return;
    }
    if (fromNode.nodeType === 1 && fromNode.hasAttribute("k-ignore")) return;
    if (fromNode.nodeType !== toNode.nodeType || fromNode.nodeName !== toNode.nodeName) {
      fromNode.replaceWith(toNode.cloneNode(true));
      return;
    }
    if (fromNode.nodeType === 3 || fromNode.nodeType === 8) {
      if (fromNode.nodeValue !== toNode.nodeValue) fromNode.nodeValue = toNode.nodeValue;
      return;
    }
    if (fromNode.nodeType === 1) {
      syncAttributes(fromNode, toNode);
      morphChildren(fromNode, toNode);
    }
  }

  function morphChildren(fromParent, toParent) {
    const keyedOld = new Map();
    for (const child of Array.from(fromParent.childNodes)) {
      const k = nodeKey(child);
      if (k) keyedOld.set(k, child);
    }

    const toNodes = Array.from(toParent.childNodes);
    let cursor = fromParent.firstChild;

    for (const toChild of toNodes) {
      while (cursor && cursor.nodeType === 3 && toChild.nodeType === 1 && cursor.nodeValue.trim() === "") {
        const next = cursor.nextSibling;
        fromParent.removeChild(cursor);
        cursor = next;
      }

      const k = nodeKey(toChild);
      let match = null;
      if (k && keyedOld.has(k)) {
        match = keyedOld.get(k);
        keyedOld.delete(k);
        if (match !== cursor) fromParent.insertBefore(match, cursor);
      } else if (cursor && !nodeKey(cursor) && cursor.nodeType === toChild.nodeType && cursor.nodeName === toChild.nodeName) {
        match = cursor;
      }

      if (match) {
        const next = match.nextSibling;
        morphNode(match, toChild);
        cursor = next;
      } else {
        const clone = toChild.cloneNode(true);
        fromParent.insertBefore(clone, cursor);
      }
    }

    while (cursor) {
      const next = cursor.nextSibling;
      fromParent.removeChild(cursor);
      cursor = next;
    }
  }

  function getCsrf(el) {
    const form = el && el.closest ? el.closest("form") : null;
    const input = form ? form.querySelector('input[name="_csrf"]') : document.querySelector('input[name="_csrf"]');
    if (input && input.value) return input.value;
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? (meta.getAttribute("content") || "") : "";
  }

  function syncOnlineIndicators(root) {
    const online = typeof navigator === "undefined" ? true : navigator.onLine !== false;
    const scope = root || document;
    scope.querySelectorAll("[k-online]").forEach((el) => {
      el.hidden = !online;
      el.style.display = online ? "" : "none";
    });
    scope.querySelectorAll("[k-offline]").forEach((el) => {
      el.hidden = online;
      el.style.display = online ? "none" : "";
    });
  }

  function hydrate(root) {
    const scope = root || document;
    if (scope.nodeType === 1) {
      const ancestor = scope.closest("[k-state]");
      if (ancestor) initIsland(ancestor);
    }
    scope.querySelectorAll("[k-state]").forEach(initIsland);
    syncOnlineIndicators(scope);
    connectStreams(scope);
    connectPrefetch(scope);
    connectSortables(scope);
  }

  function swapSingle(target, tplContent, mode) {
    if (!target || mode === "none") return;
    if (mode === "delete") { target.remove(); return; }
    if (mode === "morph" || mode === "morphInner") {
      if (mode === "morph" && tplContent.childElementCount === 1 && target.parentNode) {
        morphNode(target, tplContent.firstElementChild);
      } else {
        morphChildren(target, tplContent);
      }
      hydrate(target.parentNode || document);
      return;
    }
    // Insert the already-parsed DocumentFragment directly instead of re-serializing Text nodes
    // via .textContent (which would double-decode HTML entities into executable DOM XSS).
    if (mode === "outerHTML") {
      const parent = target.parentNode;
      target.replaceWith(tplContent);
      hydrate(parent || document);
    } else if (mode === "beforeend") {
      target.append(tplContent);
      hydrate(target);
    } else if (mode === "afterbegin") {
      target.prepend(tplContent);
      hydrate(target);
    } else {
      target.replaceChildren(tplContent);
      hydrate(target);
    }
  }

  function performSwap(target, html, mode) {
    const tpl = document.createElement("template");
    tpl.innerHTML = html;

    // Extract & apply Out-of-Band multi-region morphs ([kgn-oob][id]) before swapping primary target
    const oobNodes = Array.from(tpl.content.children).filter((n) => n.nodeType === 1 && n.hasAttribute("kgn-oob") && n.id);
    for (const oobEl of oobNodes) {
      oobEl.remove();
      const oobTarget = document.getElementById(oobEl.id);
      if (!oobTarget) continue;
      const rawOob = (oobEl.getAttribute("kgn-oob") || "").trim();
      const oobMode = (!rawOob || rawOob === "true") ? "morph" : rawOob;
      oobEl.removeAttribute("kgn-oob");
      const wrap = document.createElement("template");
      if (oobMode === "morphInner" || oobMode === "innerHTML") {
        while (oobEl.firstChild) wrap.content.appendChild(oobEl.firstChild);
      } else {
        wrap.content.appendChild(oobEl);
      }
      swapSingle(oobTarget, wrap.content, oobMode);
    }

    const hasRemaining = tpl.content.childElementCount > 0 || (tpl.content.textContent || "").trim() !== "";
    if (target && mode !== "none" && (hasRemaining || oobNodes.length === 0 || mode === "delete")) {
      swapSingle(target, tpl.content, mode);
    }
  }

  function applySwap(target, html, mode, useTransition) {
    if (useTransition && typeof document.startViewTransition === "function") {
      document.startViewTransition(() => performSwap(target, html, mode));
    } else {
      performSwap(target, html, mode);
    }
  }

  function findLiveAttr(el) {
    for (const m of METHODS) {
      const attr = "kgn-" + m;
      if (el.hasAttribute(attr)) {
        return { method: m.toUpperCase(), url: el.getAttribute(attr) || "" };
      }
    }
    return null;
  }

  function invalidatePrefetch() {
    prefetchCache.clear();
  }

  function getCachedPrefetch(url) {
    const entry = prefetchCache.get(url);
    if (!entry) return null;
    if (Date.now() - entry.at > PREFETCH_TTL_MS) {
      prefetchCache.delete(url);
      return null;
    }
    return entry;
  }

  function prefetchUrl(rawUrl) {
    const parsed = isSameOriginUrl(rawUrl);
    if (!parsed) return;
    const href = parsed.href;
    if (getCachedPrefetch(href) || inflightPrefetch.has(href)) return;
    const p = fetch(href, {
      method: "GET",
      headers: { "X-Kongono-Live": "true", "X-Kongono-Prefetch": "true", "Accept": "text/html" },
      credentials: "same-origin"
    }).then(async (res) => {
      if (!res.ok) return;
      if (!isSameOriginUrl(res.url || href)) return;
      const ct = (res.headers.get("Content-Type") || "").toLowerCase();
      if (!ct.startsWith("text/html")) return;
      const text = await res.text();
      if (prefetchCache.size >= MAX_PREFETCH) {
        const oldest = prefetchCache.keys().next().value;
        if (oldest !== undefined) prefetchCache.delete(oldest);
      }
      prefetchCache.set(href, {
        at: Date.now(),
        text,
        swap: res.headers.get("X-Kongono-Swap") || "",
        pushUrl: res.headers.get("X-Kongono-Push-Url") || "",
        status: res.status
      });
    }).catch(() => {}).finally(() => inflightPrefetch.delete(href));
    inflightPrefetch.set(href, p);
  }

  function connectPrefetch(root) {
    if (typeof IntersectionObserver === "undefined") return;
    const nodes = (root || document).querySelectorAll('[kgn-prefetch="visible"][kgn-get]');
    if (nodes.length === 0) return;
    const io = new IntersectionObserver((entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        io.unobserve(entry.target);
        const url = entry.target.getAttribute("kgn-get");
        if (url) prefetchUrl(url);
      }
    }, { rootMargin: "120px" });
    nodes.forEach((n) => {
      if (prefetchedVisible.has(n)) return;
      prefetchedVisible.add(n);
      io.observe(n);
    });
  }

  // Offline-first resilient mutation queue with Idempotency-Key replay
  function readOfflineQueue() {
    try {
      const raw = window.sessionStorage.getItem(OFFLINE_QUEUE_KEY);
      const parsed = raw ? JSON.parse(raw) : [];
      return Array.isArray(parsed) ? parsed : [];
    } catch (_e) {
      return [];
    }
  }

  function writeOfflineQueue(items) {
    try {
      window.sessionStorage.setItem(OFFLINE_QUEUE_KEY, JSON.stringify(items.slice(0, 50)));
    } catch (_e) {}
  }

  function enqueueOffline(item) {
    const queue = readOfflineQueue();
    queue.push(item);
    writeOfflineQueue(queue);
    window.dispatchEvent(new CustomEvent("kongono:offline-queued", { detail: { pending: queue.length, item } }));
  }

  function newIdempotencyKey() {
    if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
      return "kgn-" + crypto.randomUUID();
    }
    return "kgn-" + Date.now() + "-" + Math.random().toString(36).slice(2, 10);
  }

  async function flushOfflineQueue() {
    if (flushingQueue || (typeof navigator !== "undefined" && navigator.onLine === false)) return 0;
    const queue = readOfflineQueue();
    if (queue.length === 0) return 0;
    flushingQueue = true;
    let replayed = 0;
    const remaining = [];
    try {
      for (let i = 0; i < queue.length; i++) {
        const job = queue[i];
        const parsed = isSameOriginUrl(job.url || "");
        if (!parsed) continue;
        const method = String(job.method || "POST").toUpperCase();
        const headers = {
          "X-Kongono-Live": "true",
          "Accept": "text/html",
          "Idempotency-Key": job.idempotencyKey || newIdempotencyKey()
        };
        const csrf = getCsrf(null);
        if (csrf && method !== "GET" && method !== "HEAD") headers["X-CSRF-Token"] = csrf;
        const init = { method, headers, credentials: "same-origin" };
        if (Array.isArray(job.bodyEntries) && job.bodyEntries.length > 0 && method !== "GET" && method !== "HEAD") {
          const fd = new FormData();
          for (const [k, v] of job.bodyEntries) fd.append(k, v);
          init.body = fd;
        }
        try {
          const res = await fetch(parsed.href, init);
          if (res.status >= 500) {
            remaining.push(...queue.slice(i));
            break;
          }
          const finalUrl = isSameOriginUrl(res.url || parsed.href);
          if (!finalUrl) continue;
          const ct = (res.headers.get("Content-Type") || "").toLowerCase();
          const text = await res.text();
          if (ct.startsWith("text/html") && (res.ok || text.length > 0)) {
            let target = null;
            if (job.targetSel) {
              try { target = document.querySelector(job.targetSel); } catch (_selErr) { target = null; }
            }
            applySwap(target, text, res.headers.get("X-Kongono-Swap") || job.swap || "morph", Boolean(job.useTransition));
          }
          replayed++;
        } catch (_netErr) {
          remaining.push(...queue.slice(i));
          break;
        }
      }
      writeOfflineQueue(remaining);
      if (replayed > 0) {
        invalidatePrefetch();
        document.querySelectorAll("[data-kongono-queued]").forEach((el) => el.removeAttribute("data-kongono-queued"));
        window.dispatchEvent(new CustomEvent("kongono:offline-flushed", { detail: { replayed, remaining: remaining.length } }));
      }
    } finally {
      flushingQueue = false;
    }
    return replayed;
  }

  async function dispatchLive(el, form) {
    const live = findLiveAttr(el);
    if (!live || !live.url) return;

    const targetUrl = isSameOriginUrl(live.url);
    if (!targetUrl) return;

    const confirmMsg = el.getAttribute("kgn-confirm");
    if (confirmMsg && !window.confirm(confirmMsg)) return;

    const targetSel = el.getAttribute("kgn-target");
    let target = el;
    if (targetSel) {
      try { target = document.querySelector(targetSel); } catch (_selErr) { target = null; }
    }
    const defaultSwap = el.getAttribute("kgn-swap") || "innerHTML";
    const useTransition = el.getAttribute("kgn-transition") === "true";

    let fetchUrl = targetUrl.href;
    if (live.method === "GET" && (el.tagName === "INPUT" || el.tagName === "SELECT" || el.tagName === "TEXTAREA") && el.name) {
      const u = new URL(targetUrl.href);
      u.searchParams.set(el.name, el.value || "");
      fetchUrl = u.href;
    }

    // Serve instant 0ms hit from speculative prefetch cache on GET
    if (live.method === "GET") {
      const cached = getCachedPrefetch(fetchUrl);
      if (cached) {
        if (cached.pushUrl && cached.pushUrl.startsWith("/") && !cached.pushUrl.startsWith("//") && !cached.pushUrl.startsWith("/\\") && window.history && window.history.pushState) {
          try { window.history.pushState({}, "", cached.pushUrl); } catch (_e) {}
        }
        applySwap(target, cached.text, cached.swap || defaultSwap, useTransition);
        el.dispatchEvent(new CustomEvent("kongono:swapped", { bubbles: true, detail: { status: cached.status, target, prefetched: true } }));
        return;
      }
    } else {
      invalidatePrefetch();
    }

    const headers = { "X-Kongono-Live": "true", "Accept": "text/html" };
    const csrf = getCsrf(form || el);
    if (csrf && live.method !== "GET" && live.method !== "HEAD") headers["X-CSRF-Token"] = csrf;

    const init = { method: live.method, headers, credentials: "same-origin" };
    let serializedEntries = [];
    if (form && live.method !== "GET" && live.method !== "HEAD") {
      const fd = new FormData(form);
      init.body = fd;
      fd.forEach((v, k) => {
        if (typeof v === "string" && !SENSITIVE_QUEUE_RE.test(k)) {
          serializedEntries.push([k, v]);
        }
      });
    }

    // Optimistic UI with automatic rollback on failure (preserved when queued offline)
    const optText = el.getAttribute("kgn-optimistic-text");
    const optClass = el.getAttribute("kgn-optimistic-class");
    const prevText = optText !== null ? el.textContent : null;
    const hadOptClass = optClass ? el.classList.contains(optClass) : false;
    if (optText !== null) el.textContent = optText;
    if (optClass) el.classList.toggle(optClass, true);
    const shouldDisable = el.hasAttribute("kgn-disable") && "disabled" in el;
    if (shouldDisable) el.disabled = true;

    const allowOfflineQueue = el.getAttribute("kgn-offline") === "queue" && live.method !== "GET";
    if (allowOfflineQueue && typeof navigator !== "undefined" && navigator.onLine === false) {
      el.setAttribute("data-kongono-queued", "true");
      if (shouldDisable) el.disabled = false;
      enqueueOffline({
        url: fetchUrl,
        method: live.method,
        bodyEntries: serializedEntries,
        targetSel: targetSel || "",
        swap: defaultSwap,
        useTransition,
        idempotencyKey: newIdempotencyKey()
      });
      return;
    }

    el.setAttribute("aria-busy", "true");
    let succeeded = false;
    let queuedOffline = false;
    try {
      const res = await fetch(fetchUrl, init);
      const finalUrl = isSameOriginUrl(res.url || fetchUrl);
      if (!finalUrl) return;
      succeeded = res.ok;

      const redirect = res.headers.get("X-Kongono-Redirect");
      if (redirect) {
        const safeRedirect = isSameOriginUrl(redirect);
        if (safeRedirect) {
          window.location.assign(safeRedirect.href);
          return;
        }
      }
      const pushUrl = res.headers.get("X-Kongono-Push-Url");
      if (pushUrl && pushUrl.startsWith("/") && !pushUrl.startsWith("//") && !pushUrl.startsWith("/\\") && window.history && window.history.pushState) {
        try { window.history.pushState({}, "", pushUrl); } catch (_e) {}
      }
      const swap = res.headers.get("X-Kongono-Swap") || defaultSwap;
      const text = await res.text();
      const ct = (res.headers.get("Content-Type") || "").toLowerCase();
      if (res.ok || text.length > 0) {
        if (ct.startsWith("text/html")) {
          applySwap(target, text, swap, useTransition);
        } else if (target && swap !== "none" && swap !== "delete") {
          target.textContent = text;
        }
      }
      el.dispatchEvent(new CustomEvent("kongono:swapped", { bubbles: true, detail: { status: res.status, target, prefetched: false } }));
    } catch (_netErr) {
      if (allowOfflineQueue) {
        queuedOffline = true;
        el.setAttribute("data-kongono-queued", "true");
        enqueueOffline({
          url: fetchUrl,
          method: live.method,
          bodyEntries: serializedEntries,
          targetSel: targetSel || "",
          swap: defaultSwap,
          useTransition,
          idempotencyKey: newIdempotencyKey()
        });
      }
    } finally {
      el.removeAttribute("aria-busy");
      if (shouldDisable) el.disabled = false;
      if (!succeeded && !queuedOffline) {
        if (prevText !== null) el.textContent = prevText;
        if (optClass) el.classList.toggle(optClass, hadOptClass);
      }
    }
  }

  function scheduleTriggered(el, triggerType) {
    const spec = (el.getAttribute("kgn-trigger") || "").trim();
    if (!spec) return;
    const parts = spec.split(/\s+/);
    if (parts[0] !== triggerType) return;
    let delay = 0;
    for (const p of parts.slice(1)) {
      const m = /^delay:(\d+)ms$/.exec(p);
      if (m) delay = Math.min(5000, Number(m[1]));
    }
    if (timers.has(el)) clearTimeout(timers.get(el));
    if (delay > 0) {
      timers.set(el, setTimeout(() => dispatchLive(el, el.closest("form")), delay));
    } else {
      dispatchLive(el, el.closest("form"));
    }
  }

  // Built-in zero-eval drag-and-drop & keyboard (Alt+Arrow) reordering (kgn-sortable)
  async function syncSortableOrder(container, snapshot) {
    const rawUrl = container.getAttribute("kgn-sortable") || "";
    const targetUrl = isSameOriginUrl(rawUrl);
    if (!targetUrl) return;
    invalidatePrefetch();

    const order = Array.from(container.children).map((c) => nodeKey(c)).filter(Boolean);
    const fd = new FormData();
    for (const key of order) fd.append("order[]", key);
    const headers = { "X-Kongono-Live": "true", "Accept": "text/html" };
    const csrf = getCsrf(container);
    if (csrf) headers["X-CSRF-Token"] = csrf;

    try {
      const res = await fetch(targetUrl.href, { method: "POST", headers, body: fd, credentials: "same-origin" });
      if (!res.ok || !isSameOriginUrl(res.url || targetUrl.href)) throw new Error("reorder_failed");
      const text = await res.text();
      const ct = (res.headers.get("Content-Type") || "").toLowerCase();
      if (ct.startsWith("text/html") && text.trim() !== "") {
        const targetSel = container.getAttribute("kgn-target");
        let target = container;
        if (targetSel) {
          try { target = document.querySelector(targetSel) || container; } catch (_selErr) { target = container; }
        }
        applySwap(target, text, res.headers.get("X-Kongono-Swap") || container.getAttribute("kgn-swap") || "morph", container.getAttribute("kgn-transition") === "true");
      }
      container.dispatchEvent(new CustomEvent("kongono:reordered", { bubbles: true, detail: { order } }));
    } catch (_e) {
      for (const node of snapshot) container.appendChild(node);
    }
  }

  function connectSortables(root) {
    const containers = (root || document).querySelectorAll("[kgn-sortable]");
    containers.forEach((container) => {
      Array.from(container.children).forEach((child) => {
        if (nodeKey(child)) {
          child.setAttribute("draggable", "true");
          child.setAttribute("data-sortable-item", "true");
        }
      });
      if (sortables.has(container)) return;
      let dragged = null;
      let snapshot = [];
      let changed = false;

      container.addEventListener("dragstart", (ev) => {
        const item = ev.target && ev.target.closest ? ev.target.closest("[data-sortable-item]") : null;
        if (!item || item.parentElement !== container) return;
        dragged = item;
        snapshot = Array.from(container.children);
        changed = false;
        item.classList.add("is-dragging");
        if (ev.dataTransfer) {
          ev.dataTransfer.effectAllowed = "move";
          try { ev.dataTransfer.setData("text/plain", nodeKey(item) || ""); } catch (_e) {}
        }
      });

      container.addEventListener("dragover", (ev) => {
        if (!dragged) return;
        ev.preventDefault();
        const over = ev.target && ev.target.closest ? ev.target.closest("[data-sortable-item]") : null;
        if (!over || over === dragged || over.parentElement !== container) return;
        const rect = over.getBoundingClientRect();
        const after = (ev.clientY - rect.top) > (rect.height / 2);
        container.insertBefore(dragged, after ? over.nextSibling : over);
        changed = true;
      });

      container.addEventListener("dragend", () => {
        if (!dragged) return;
        dragged.classList.remove("is-dragging");
        dragged = null;
        if (changed) syncSortableOrder(container, snapshot);
      });

      container.addEventListener("keydown", (ev) => {
        if (!ev.altKey || (ev.key !== "ArrowUp" && ev.key !== "ArrowDown")) return;
        const item = ev.target && ev.target.closest ? ev.target.closest("[data-sortable-item]") : null;
        if (!item || item.parentElement !== container) return;
        const snap = Array.from(container.children);
        if (ev.key === "ArrowUp" && item.previousElementSibling) {
          ev.preventDefault();
          container.insertBefore(item, item.previousElementSibling);
          item.focus();
          syncSortableOrder(container, snap);
        } else if (ev.key === "ArrowDown" && item.nextElementSibling) {
          ev.preventDefault();
          container.insertBefore(item.nextElementSibling, item);
          item.focus();
          syncSortableOrder(container, snap);
        }
      });

      sortables.set(container, true);
    });
  }

  function connectStreams(root) {
    const nodes = (root || document).querySelectorAll("[kgn-stream]");
    nodes.forEach((node) => {
      if (streams.has(node)) return;
      const url = node.getAttribute("kgn-stream");
      const streamUrl = isSameOriginUrl(url || "");
      if (!streamUrl || typeof EventSource === "undefined") return;
      const eventName = node.getAttribute("kgn-event") || "message";
      const swap = node.getAttribute("kgn-swap") || "morphInner";
      const es = new EventSource(streamUrl.href);
      streams.set(node, es);
      const handler = (ev) => {
        if (!document.contains(node)) {
          es.close();
          streams.delete(node);
          return;
        }
        applySwap(node, ev.data || "", swap, false);
      };
      if (eventName === "message") {
        es.onmessage = handler;
      } else {
        es.addEventListener(eventName, handler);
      }
    });
  }

  document.addEventListener("click", (ev) => {
    handleSignalEvent("click", ev);
    const el = ev.target && ev.target.closest ? ev.target.closest("[kgn-get],[kgn-post],[kgn-put],[kgn-patch],[kgn-delete]") : null;
    if (!el || el.tagName === "FORM" || el.hasAttribute("kgn-trigger")) return;
    ev.preventDefault();
    dispatchLive(el, null);
  });

  document.addEventListener("pointerover", (ev) => {
    const el = ev.target && ev.target.closest ? ev.target.closest('[kgn-prefetch="hover"][kgn-get]') : null;
    if (el) prefetchUrl(el.getAttribute("kgn-get"));
  });

  document.addEventListener("focusin", (ev) => {
    const el = ev.target && ev.target.closest ? ev.target.closest('[kgn-prefetch="hover"][kgn-get]') : null;
    if (el) prefetchUrl(el.getAttribute("kgn-get"));
  });

  document.addEventListener("pointerdown", (ev) => {
    const el = ev.target && ev.target.closest ? ev.target.closest('[kgn-prefetch="mousedown"][kgn-get]') : null;
    if (el) prefetchUrl(el.getAttribute("kgn-get"));
  });

  document.addEventListener("submit", (ev) => {
    handleSignalEvent("submit", ev);
    const form = ev.target;
    if (!form || form.tagName !== "FORM" || !findLiveAttr(form)) return;
    ev.preventDefault();
    dispatchLive(form, form);
  });

  document.addEventListener("input", (ev) => {
    const el = ev.target;
    if (el && el.nodeType === 1) {
      if (el.hasAttribute("k-model")) {
        const island = findIsland(el);
        if (island) {
          const val = el.type === "checkbox" ? el.checked : (el.type === "number" || el.type === "range" ? Number(el.value) : el.value);
          setPath(island.state, el.getAttribute("k-model"), val);
          saveIsland(island);
          renderIsland(island);
        }
      }
      if (el.hasAttribute("kgn-trigger")) scheduleTriggered(el, "input");
    }
    handleSignalEvent("input", ev);
  });

  document.addEventListener("change", (ev) => {
    const el = ev.target;
    if (el && el.nodeType === 1) {
      if (el.hasAttribute("k-model")) {
        const island = findIsland(el);
        if (island) {
          const val = el.type === "checkbox" ? el.checked : (el.type === "number" || el.type === "range" ? Number(el.value) : el.value);
          setPath(island.state, el.getAttribute("k-model"), val);
          saveIsland(island);
          renderIsland(island);
        }
      }
      if (el.hasAttribute("kgn-trigger")) scheduleTriggered(el, "change");
    }
    handleSignalEvent("change", ev);
  });

  document.addEventListener("keydown", (ev) => handleSignalEvent("keydown", ev));
  document.addEventListener("keyup", (ev) => {
    const el = ev.target;
    if (el && el.nodeType === 1 && el.hasAttribute("kgn-trigger")) scheduleTriggered(el, "keyup");
    handleSignalEvent("keyup", ev);
  });

  window.addEventListener("online", () => {
    syncOnlineIndicators(document);
    window.dispatchEvent(new CustomEvent("kongono:online"));
    flushOfflineQueue();
  });
  window.addEventListener("offline", () => {
    syncOnlineIndicators(document);
    window.dispatchEvent(new CustomEvent("kongono:offline"));
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => { hydrate(document); flushOfflineQueue(); });
  } else {
    hydrate(document);
    flushOfflineQueue();
  }

  window.KongonoLive = {
    version: "2.5.0",
    connectStreams,
    hydrate,
    prefetch: prefetchUrl,
    invalidatePrefetch,
    flushQueue: flushOfflineQueue,
    pendingQueueCount: () => readOfflineQueue().length,
    isOnline: () => (typeof navigator === "undefined" ? true : navigator.onLine !== false),
    evaluate: (expr, state) => evalExpr(expr, cloneSafe(state || {})),
    morph: (target, html, outer = false) => performSwap(target, html, outer ? "morph" : "morphInner"),
    action: (name, fn) => { if (name && !BLOCKED_KEYS.has(name) && typeof fn === "function") customActions[name] = fn; },
    pipe: (name, fn) => { if (name && !BLOCKED_KEYS.has(name) && typeof fn === "function") PIPES[name] = fn; },
    getState: (el) => { const i = findIsland(el); return i ? cloneSafe(i.state) : null; },
    setState: (el, key, value) => { const i = findIsland(el); if (i) { setPath(i.state, key, value); saveIsland(i); renderIsland(i); } }
  };
})();