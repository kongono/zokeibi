/* Zokeibi.com — Strict-CSP Site Controls (Theme/Radius Switcher, Catalog Filter, Copy) */
(() => {
  "use strict";

  const root = document.documentElement;
  const savedTheme = localStorage.getItem("zokeibi-theme");
  const savedRadius = localStorage.getItem("zokeibi-radius");

  const applyTheme = (theme) => {
    root.setAttribute("data-zokeibi-theme", theme);
    localStorage.setItem("zokeibi-theme", theme);
    document.querySelectorAll("[data-set-theme]").forEach((btn) => {
      const active = btn.getAttribute("data-set-theme") === theme;
      btn.classList.toggle("is-active", active);
      btn.setAttribute("aria-pressed", active ? "true" : "false");
    });
  };

  const applyRadius = (radius) => {
    root.setAttribute("data-zokeibi-radius", radius);
    localStorage.setItem("zokeibi-radius", radius);
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
      const prev = copyBtn.textContent;
      const done = () => {
        copyBtn.textContent = "Copied!";
        window.setTimeout(() => { copyBtn.textContent = prev; }, 1600);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(cmd).then(done).catch(done);
      } else {
        done();
      }
    }
  });
})();
