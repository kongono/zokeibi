/* Zokeibi.com Static Playground Enhancements — Strict CSP, Zero innerHTML, Zero eval */
(() => {
  "use strict";

  const copyTimers = new WeakMap();

  /* ---- Sync Two-Gear Density Pill Highlight ---- */
  function syncDensitySwatches(density) {
    document.querySelectorAll("[data-z-set-density]").forEach((btn) => {
      const active = (btn.getAttribute("data-z-set-density") || "") === density;
      btn.classList.toggle("is-active", active);
      btn.setAttribute("aria-pressed", active ? "true" : "false");
    });
  }

  document.addEventListener("zokeibi:density-change", (event) => {
    if (event instanceof CustomEvent && event.detail && event.detail.density) {
      syncDensitySwatches(event.detail.density);
    }
  });

  /* ---- Instant Search Across All 60 Components ---- */
  function applySearchFilter(rawQuery) {
    const q = (rawQuery || "").trim().toLowerCase();
    const cards = Array.from(document.querySelectorAll("[data-pg-card]"));
    let matched = 0;

    cards.forEach((card) => {
      const hay = card.getAttribute("data-pg-haystack") || "";
      const hit = q === "" || hay.includes(q);
      card.classList.toggle("pg-search-hidden", !hit);
      if (hit) matched++;
    });

    const countEl = document.querySelector("[data-pg-search-count]");
    if (countEl) {
      countEl.textContent =
        q === ""
          ? `${cards.length} of ${cards.length} components`
          : `${matched} matching "${rawQuery.trim()}"`;
    }

    // When searching, switch category filter to All so matches in any category appear
    if (q !== "") {
      const allBtn = document.getElementById("cat-all");
      if (allBtn && allBtn.getAttribute("aria-selected") !== "true") {
        allBtn.click();
      }
    }
  }

  /* ---- Inject 1-Click Copy Button on Markup & CLI Code Blocks ---- */
  function initCodeCopyButtons() {
    document.querySelectorAll("[data-pg-code-wrap]").forEach((wrap) => {
      if (wrap.querySelector("[data-pg-copy-code]")) return;
      const pre = wrap.querySelector("pre.z-code");
      if (!pre) return;

      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "z-btn z-btn-outline z-btn-sm pg-copy-btn";
      btn.setAttribute("data-pg-copy-code", "true");
      btn.setAttribute("aria-label", "Copy snippet to clipboard");
      btn.textContent = "Copy";

      const syncVisibility = () => {
        btn.hidden = pre.hidden;
      };
      syncVisibility();

      const obs = new MutationObserver(syncVisibility);
      obs.observe(pre, { attributes: true, attributeFilter: ["hidden"] });

      btn.addEventListener("click", () => {
        const text = (pre.textContent || "").trim();
        const flash = (label) => {
          const prev = copyTimers.get(btn);
          if (prev) window.clearTimeout(prev);
          btn.textContent = label;
          const t = window.setTimeout(() => {
            btn.textContent = "Copy";
            copyTimers.delete(btn);
          }, 1500);
          copyTimers.set(btn, t);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard
            .writeText(text)
            .then(() => flash("Copied!"))
            .catch(() => flash("Failed"));
        } else {
          flash("Unavailable");
        }
      });

      wrap.appendChild(btn);
    });
  }

  /* ---- Deep-Link Focus (#comp-<id>) ---- */
  function handleDeepLink() {
    const hash = window.location.hash || "";
    if (!hash.startsWith("#comp-")) return;
    const target = document.getElementById(hash.slice(1));
    if (!target) return;

    const allBtn = document.getElementById("cat-all");
    if (allBtn && allBtn.getAttribute("aria-selected") !== "true") {
      allBtn.click();
    }
    target.classList.add("pg-card-focus");
    window.setTimeout(() => {
      target.scrollIntoView({ behavior: "smooth", block: "center" });
    }, 80);
    window.setTimeout(() => target.classList.remove("pg-card-focus"), 2400);
  }

  /* ---- Intercept Static Demo Forms (action="#") to Show Live Feedback Toast ---- */
  document.addEventListener("submit", (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    const rawAction = (form.getAttribute("action") || "").trim();
    if (rawAction !== "" && rawAction !== "#") return;
    event.preventDefault();

    const submitter = event.submitter instanceof HTMLButtonElement ? event.submitter : null;
    const decision = submitter && submitter.value ? submitter.value : "submitted";
    const data = new FormData(form);
    const pairs = [];
    data.forEach((v, k) => {
      if (k !== "_csrf" && typeof v === "string" && v.trim() !== "") {
        pairs.push(`${k}=${v.trim()}`);
      }
    });
    if (window.Zokeibi && typeof window.Zokeibi.toast === "function") {
      window.Zokeibi.toast({
        title: `Demo form ${decision}`,
        description: pairs.length > 0 ? pairs.slice(0, 4).join(" · ") : "Interactive static playground preview.",
        variant: decision === "reject" || decision === "flag" ? "amber" : "emerald",
      });
    }
  });

  document.addEventListener("input", (event) => {
    const target = event.target;
    if (target instanceof HTMLInputElement && target.hasAttribute("data-pg-search-input")) {
      applySearchFilter(target.value);
    }
  });

  function boot() {
    let savedDensity = "default";
    try {
      savedDensity = window.localStorage.getItem("zokeibi-density") || document.documentElement.getAttribute("data-z-density") || "default";
    } catch (_e) {
      savedDensity = document.documentElement.getAttribute("data-z-density") || "default";
    }
    syncDensitySwatches(savedDensity);
    initCodeCopyButtons();
    handleDeepLink();
  }

  window.addEventListener("hashchange", handleDeepLink);
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
