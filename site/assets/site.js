/* Zokeibi.com — Strict-CSP Site Controls (Theme/Radius Switcher, Catalog Filter, Copy) */
(() => {
  "use strict";

  const ALLOWED_THEMES = new Set(["sumi", "washi", "indigo", "matcha", "kintsugi"]);
  const ALLOWED_RADII = new Set(["sharp", "default", "rounded"]);
  const root = document.documentElement;
  const copyTimers = new WeakMap();

  const safeGet = (key) => {
    try {
      return window.localStorage.getItem(key);
    } catch (_e) {
      return null;
    }
  };

  const safeSet = (key, val) => {
    try {
      window.localStorage.setItem(key, val);
    } catch (_e) {
      // Storage blocked or quota exceeded
    }
  };

  const savedTheme = safeGet("zokeibi-theme");
  const savedRadius = safeGet("zokeibi-radius");

  const applyTheme = (rawTheme) => {
    const theme = ALLOWED_THEMES.has(rawTheme) ? rawTheme : "sumi";
    root.setAttribute("data-zokeibi-theme", theme);
    safeSet("zokeibi-theme", theme);
    document.querySelectorAll("[data-set-theme]").forEach((btn) => {
      const active = btn.getAttribute("data-set-theme") === theme;
      btn.classList.toggle("is-active", active);
      btn.setAttribute("aria-pressed", active ? "true" : "false");
    });
  };

  const applyRadius = (rawRadius) => {
    const radius = ALLOWED_RADII.has(rawRadius) ? rawRadius : "default";
    root.setAttribute("data-zokeibi-radius", radius);
    safeSet("zokeibi-radius", radius);
    document.querySelectorAll("[data-set-radius]").forEach((btn) => {
      const active = btn.getAttribute("data-set-radius") === radius;
      btn.classList.toggle("is-active", active);
      btn.setAttribute("aria-pressed", active ? "true" : "false");
    });
  };

  applyTheme(savedTheme || "sumi");
  applyRadius(savedRadius || "default");

  document.addEventListener("click", (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    const themeBtn = target.closest("[data-set-theme]");
    if (themeBtn) {
      applyTheme(themeBtn.getAttribute("data-set-theme") || "sumi");
      return;
    }

    const radiusBtn = target.closest("[data-set-radius]");
    if (radiusBtn) {
      applyRadius(radiusBtn.getAttribute("data-set-radius") || "default");
      return;
    }

    const filterBtn = target.closest("[data-catalog-filter]");
    if (filterBtn) {
      const cat = filterBtn.getAttribute("data-catalog-filter") || "all";
      document.querySelectorAll("[data-catalog-filter]").forEach((b) => {
        const active = b === filterBtn;
        b.classList.toggle("is-active", active);
        b.setAttribute("aria-pressed", active ? "true" : "false");
      });
      document.querySelectorAll("[data-catalog-item]").forEach((card) => {
        const itemCat = card.getAttribute("data-catalog-item") || "";
        card.hidden = cat !== "all" && itemCat !== cat;
      });
      return;
    }

    const copyBtn = target.closest("[data-copy-cmd]");
    if (copyBtn) {
      const cmd = copyBtn.getAttribute("data-copy-cmd") || "";
      if (!copyBtn.dataset.origLabel) copyBtn.dataset.origLabel = copyBtn.textContent || "Copy";
      const finish = (label) => {
        const prev = copyTimers.get(copyBtn);
        if (prev) window.clearTimeout(prev);
        copyBtn.textContent = label;
        const timer = window.setTimeout(() => {
          copyBtn.textContent = copyBtn.dataset.origLabel || "Copy";
          copyTimers.delete(copyBtn);
        }, 1600);
        copyTimers.set(copyBtn, timer);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard
          .writeText(cmd)
          .then(() => finish("Copied!"))
          .catch(() => finish("Copy failed"));
      } else {
        finish("Copy unavailable");
      }
    }
  });
})();
