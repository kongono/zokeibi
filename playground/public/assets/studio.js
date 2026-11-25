(() => {
  "use strict";

  const root = document.getElementById("studio-workbench");
  if (!root) return;

  const preview = document.getElementById("studio-preview");
  const hueInput = document.getElementById("st-hue");
  const chromaInput = document.getElementById("st-chroma");
  const lightInput = document.getElementById("st-lightness");
  const radiusInput = document.getElementById("st-radius");
  const modeInput = document.getElementById("st-mode");
  const fontScaleInput = document.getElementById("st-font-scale");
  const fontStackInput = document.getElementById("st-font-stack");

  const hueOut = document.getElementById("st-hue-out");
  const chromaOut = document.getElementById("st-chroma-out");
  const lightOut = document.getElementById("st-lightness-out");
  const radiusOut = document.getElementById("st-radius-out");
  const hexOut = document.getElementById("st-accent-hex");
  const accentChip = document.getElementById("st-accent-chip");
  const cssCodeOut = document.getElementById("studio-css-preview");

  // Mirror hidden fields inside the Save-as-Custom-Theme form
  const themeHueHidden = document.getElementById("st-theme-hue");
  const themeChromaHidden = document.getElementById("st-theme-chroma");
  const themeLightHidden = document.getElementById("st-theme-lightness");
  const themeModeHidden = document.getElementById("st-theme-mode");
  const themeRadiusHidden = document.getElementById("st-theme-radius");

  const FONT_STACKS = {
    system: 'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
    editorial: 'Iowan Old Style, Palatino Linotype, "Book Antiqua", Georgia, ui-serif, serif',
    geometric: '"Segoe UI Variable", "Avenir Next", Avenir, "Helvetica Neue", ui-sans-serif, sans-serif',
    mono: '"JetBrains Mono", "Cascadia Code", "Fira Code", ui-monospace, SFMono-Regular, Menlo, monospace',
  };

  const FONT_SIZES = { compact: "13px", default: "14px", comfortable: "15px" };

  function linToByte(c) {
    const clamped = Math.max(0, Math.min(1, c));
    const gamma = clamped <= 0.0031308 ? 12.92 * clamped : 1.055 * Math.pow(clamped, 1 / 2.4) - 0.055;
    return Math.round(Math.max(0, Math.min(1, gamma)) * 255);
  }

  function byteToLin(b) {
    const c = b / 255;
    return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  }

  function oklchToRgb(L, C, H) {
    const rad = (H * Math.PI) / 180;
    const a = C * Math.cos(rad);
    const b = C * Math.sin(rad);
    const l_ = L + 0.3963377774 * a + 0.2158037573 * b;
    const m_ = L - 0.1055613458 * a - 0.0638541728 * b;
    const s_ = L - 0.0894841775 * a - 1.2914855480 * b;
    const l = l_ * l_ * l_;
    const m = m_ * m_ * m_;
    const s = s_ * s_ * s_;
    const linR = +4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s;
    const linG = -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s;
    const linB = -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s;
    return [linToByte(linR), linToByte(linG), linToByte(linB)];
  }

  function oklchToHex(L, C, H) {
    const [r, g, b] = oklchToRgb(L, C, H);
    return "#" + [r, g, b].map((x) => x.toString(16).padStart(2, "0")).join("");
  }

  function relLum(L, C, H) {
    const [r, g, b] = oklchToRgb(L, C, H);
    return 0.2126 * byteToLin(r) + 0.7152 * byteToLin(g) + 0.0722 * byteToLin(b);
  }

  function contrastRatio(l1, c1, h1, l2, c2, h2) {
    const y1 = relLum(l1, c1, h1);
    const y2 = relLum(l2, c2, h2);
    const hi = Math.max(y1, y2);
    const lo = Math.min(y1, y2);
    return Math.round(((hi + 0.05) / (lo + 0.05)) * 100) / 100;
  }

  function recommendedInkL(L, C, H) {
    const rLight = contrastRatio(0.985, 0.008, H, L, C, H);
    const rDark = contrastRatio(0.145, 0.018, H, L, C, H);
    return rDark > rLight ? 0.145 : 0.985;
  }

  function updateContrastBadge(id, ratio) {
    const el = document.getElementById("wcag-" + id);
    if (!el) return;
    const grade = ratio >= 7.0 ? "AAA" : ratio >= 4.5 ? "AA" : ratio >= 3.0 ? "AA Large" : "FAIL";
    el.textContent = ratio.toFixed(2) + ":1 " + grade;
    el.className = "z-badge " + (ratio >= 4.5 ? "z-badge-emerald" : ratio >= 3.0 ? "z-badge-amber" : "z-badge-crimson");
  }

  function parseNum(raw, fallback) {
    const n = parseFloat(raw);
    return Number.isFinite(n) ? n : fallback;
  }

  function parseIntSafe(raw, fallback) {
    const n = parseInt(raw, 10);
    return Number.isFinite(n) ? n : fallback;
  }

  function syncPreview() {
    if (!preview || !hueInput || !chromaInput || !lightInput || !radiusInput || !modeInput) return;

    const H = parseNum(hueInput.value, 355);
    const C = parseNum(chromaInput.value, 0.145);
    const L = parseNum(lightInput.value, 0.65);
    const R = parseIntSafe(radiusInput.value, 12);
    const Rsm = Math.max(0, Math.round(R * 0.66));
    const mode = modeInput.value === "light" ? "light" : "dark";
    const fontScale = fontScaleInput ? fontScaleInput.value : "default";
    const fontStack = fontStackInput ? fontStackInput.value : "system";

    const inkL = recommendedInkL(L, C, H);
    const inkC = inkL < 0.5 ? 0.018 : 0.008;
    const accentStrong = Math.max(0.28, Math.round((L - 0.11) * 1000) / 1000);
    const hex = oklchToHex(L, C, H);

    if (hueOut) hueOut.textContent = H.toFixed(0) + "°";
    if (chromaOut) chromaOut.textContent = C.toFixed(3);
    if (lightOut) lightOut.textContent = L.toFixed(2);
    if (radiusOut) radiusOut.textContent = R + "px";
    if (hexOut) hexOut.textContent = hex;
    if (accentChip) accentChip.style.setProperty("background-color", hex);

    if (themeHueHidden) themeHueHidden.value = String(H);
    if (themeChromaHidden) themeChromaHidden.value = String(C);
    if (themeLightHidden) themeLightHidden.value = String(L);
    if (themeModeHidden) themeModeHidden.value = mode;
    if (themeRadiusHidden) themeRadiusHidden.value = String(R);

    const accentExpr = "oklch(" + L.toFixed(3) + " " + C.toFixed(3) + " " + H.toFixed(1) + ")";
    preview.style.setProperty("--z-hue", String(H));
    preview.style.setProperty("--z-chroma", C.toFixed(3));
    preview.style.setProperty("--z-radius", R + "px");
    preview.style.setProperty("--z-radius-sm", Rsm + "px");
    preview.style.setProperty("--z-accent", accentExpr);
    preview.style.setProperty("--z-accent-strong", "oklch(" + accentStrong.toFixed(3) + " " + C.toFixed(3) + " " + H.toFixed(1) + ")");
    preview.style.setProperty("--z-accent-ink", "oklch(" + inkL.toFixed(3) + " " + inkC.toFixed(3) + " " + H.toFixed(1) + ")");
    preview.style.setProperty("--z-accent-soft", "color-mix(in oklch, " + accentExpr + " 16%, transparent)");
    preview.style.setProperty("--z-accent-border", "color-mix(in oklch, " + accentExpr + " 42%, transparent)");
    preview.style.setProperty("--z-sans", FONT_STACKS[fontStack] || FONT_STACKS.system);
    preview.style.setProperty("font-size", FONT_SIZES[fontScale] || "14px");

    if (mode === "light") {
      preview.style.setProperty("--z-bg", "oklch(0.972 0.004 95)");
      preview.style.setProperty("--z-surface", "oklch(0.998 0.002 95)");
      preview.style.setProperty("--z-surface-2", "oklch(0.948 0.006 264)");
      preview.style.setProperty("--z-surface-hover", "oklch(0.918 0.008 264)");
      preview.style.setProperty("--z-border", "oklch(0.885 0.01 264)");
      preview.style.setProperty("--z-border-strong", "oklch(0.795 0.014 264)");
      preview.style.setProperty("--z-ink", "oklch(0.18 0.014 264)");
      preview.style.setProperty("--z-ink-muted", "oklch(0.43 0.015 264)");
      preview.style.setProperty("--z-ink-soft", "oklch(0.55 0.012 264)");
      preview.style.setProperty("--z-emerald", "oklch(0.52 0.14 162)");
      preview.style.setProperty("--z-amber", "oklch(0.58 0.145 75)");
      preview.style.setProperty("--z-danger", "oklch(0.54 0.185 20)");
      preview.style.setProperty("--z-blue", "oklch(0.52 0.155 256)");
    } else {
      preview.style.setProperty("--z-bg", "oklch(0.152 0.014 " + H.toFixed(1) + ")");
      preview.style.setProperty("--z-surface", "oklch(0.190 0.016 " + H.toFixed(1) + ")");
      preview.style.setProperty("--z-surface-2", "oklch(0.228 0.018 " + H.toFixed(1) + ")");
      preview.style.setProperty("--z-surface-hover", "oklch(0.262 0.020 " + H.toFixed(1) + ")");
      preview.style.setProperty("--z-border", "oklch(0.296 0.020 " + H.toFixed(1) + ")");
      preview.style.setProperty("--z-border-strong", "oklch(0.372 0.024 " + H.toFixed(1) + ")");
      preview.style.setProperty("--z-ink", "oklch(0.955 0.005 264)");
      preview.style.setProperty("--z-ink-muted", "oklch(0.725 0.012 264)");
      preview.style.setProperty("--z-ink-soft", "oklch(0.565 0.012 264)");
      preview.style.setProperty("--z-emerald", "oklch(0.77 0.15 162)");
      preview.style.setProperty("--z-amber", "oklch(0.82 0.155 82)");
      preview.style.setProperty("--z-danger", "oklch(0.66 0.185 20)");
      preview.style.setProperty("--z-blue", "oklch(0.73 0.145 252)");
    }

    // Live WCAG 2.1 contrast audit (matches OklchContrast::auditConfig exactly)
    const isLight = mode === "light";
    const bgL = isLight ? 0.972 : 0.152;
    const surfL = isLight ? 0.998 : 0.190;
    const bodyInkL = isLight ? 0.18 : 0.955;
    const mutedL = isLight ? 0.43 : 0.725;
    const bgC = isLight ? 0.004 : 0.014;
    const surfC = isLight ? 0.002 : 0.016;
    const surfH = isLight ? 95 : H;

    updateContrastBadge("accent_button", contrastRatio(inkL, inkC, H, L, C, H));
    updateContrastBadge("body_text", contrastRatio(bodyInkL, isLight ? 0.014 : 0.006, 264, bgL, bgC, surfH));
    updateContrastBadge("card_text", contrastRatio(bodyInkL, isLight ? 0.014 : 0.006, 264, surfL, surfC, surfH));
    updateContrastBadge("muted_text", contrastRatio(mutedL, 0.014, 264, surfL, surfC, surfH));

    if (cssCodeOut) {
      cssCodeOut.textContent = [
        "@layer zokeibi.themes {",
        '  [data-zokeibi-theme="studio"] {',
        "    --z-hue: " + H.toFixed(1) + ";",
        "    --z-chroma: " + C.toFixed(3) + ";",
        "    --z-radius: " + R + "px;",
        "    --z-radius-sm: " + Rsm + "px;",
        "    --z-accent: oklch(" + L.toFixed(3) + " var(--z-chroma) var(--z-hue)); /* " + hex + " */",
        "    --z-accent-strong: oklch(" + accentStrong.toFixed(3) + " var(--z-chroma) var(--z-hue));",
        "    --z-accent-ink: oklch(" + inkL.toFixed(3) + " " + inkC.toFixed(3) + " var(--z-hue));",
        "  }",
        "}",
      ].join("\n");
    }
  }

  // Hydrate custom color swatch chips safely via CSSOM (zero inline style="" attributes in HTML)
  document.querySelectorAll("[data-swatch-color]").forEach((el) => {
    const val = el.getAttribute("data-swatch-color");
    if (val) el.style.setProperty("background-color", val);
  });

  // Wire preset loader buttons
  document.querySelectorAll("[data-studio-preset]").forEach((btn) => {
    btn.addEventListener("click", () => {
      if (hueInput && btn.dataset.hue) hueInput.value = btn.dataset.hue;
      if (chromaInput && btn.dataset.chroma) chromaInput.value = btn.dataset.chroma;
      if (lightInput && btn.dataset.lightness) lightInput.value = btn.dataset.lightness;
      if (modeInput && btn.dataset.mode) modeInput.value = btn.dataset.mode;
      if (radiusInput && btn.dataset.radius) radiusInput.value = btn.dataset.radius;
      syncPreview();
      scheduleLiveSync();
    });
  });

  // Sync native color picker helper -> custom color value input
  const colorPicker = document.getElementById("st-color-picker");
  const colorValInput = document.getElementById("st-color-value");
  if (colorPicker && colorValInput) {
    colorPicker.addEventListener("input", () => {
      colorValInput.value = colorPicker.value;
    });
  }

  // Copy CSS button
  const copyBtn = document.getElementById("st-copy-css");
  let copyTimer = null;
  if (copyBtn && cssCodeOut) {
    copyBtn.addEventListener("click", () => {
      if (!copyBtn.dataset.origLabel) copyBtn.dataset.origLabel = copyBtn.textContent || "Copy CSS";
      const finish = (label) => {
        if (copyTimer) window.clearTimeout(copyTimer);
        copyBtn.textContent = label;
        copyTimer = window.setTimeout(() => {
          copyBtn.textContent = copyBtn.dataset.origLabel || "Copy CSS";
        }, 1400);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard
          .writeText(cssCodeOut.textContent || "")
          .then(() => finish("Copied CSS!"))
          .catch(() => finish("Copy failed"));
      } else {
        finish("Copy unavailable");
      }
    });
  }

  // Live Hot-Swap Sync bridge -> POST /studio/live-sync (writes zokeibi.json + rebuilds CSS in-place)
  const liveSyncToggle = document.getElementById("st-live-sync");
  const liveSyncStatus = document.getElementById("st-live-sync-status");
  const proofSummary = document.getElementById("st-proof-summary");
  const saveForm = document.getElementById("studio-save-form");
  let syncTimer = null;
  let syncController = null;

  function scheduleLiveSync() {
    if (!liveSyncToggle || !liveSyncToggle.checked || !saveForm) return;
    if (syncTimer) window.clearTimeout(syncTimer);
    if (liveSyncStatus) liveSyncStatus.textContent = "Syncing CSS...";
    syncTimer = window.setTimeout(() => {
      if (syncController) syncController.abort();
      syncController = typeof AbortController === "function" ? new AbortController() : null;

      const fd = new URLSearchParams();
      const csrfInput = saveForm.querySelector('input[name="_csrf"]');
      if (csrfInput && csrfInput.value) fd.set("_csrf", csrfInput.value);
      if (hueInput) fd.set("hue", hueInput.value);
      if (chromaInput) fd.set("chroma", chromaInput.value);
      if (lightInput) fd.set("accent_lightness", lightInput.value);
      if (radiusInput) fd.set("radius_px", radiusInput.value);
      if (modeInput) fd.set("mode", modeInput.value);
      if (fontScaleInput) fd.set("font_scale", fontScaleInput.value);
      if (fontStackInput) fd.set("font_stack", fontStackInput.value);

      fetch("/studio/live-sync", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
        body: fd.toString(),
        signal: syncController ? syncController.signal : undefined,
      })
        .then((r) => (r.ok ? r.json() : Promise.reject(new Error("Sync failed"))))
        .then((data) => {
          if (liveSyncStatus) {
            liveSyncStatus.textContent = "Hot-swapped (" + data.gzip_kb + " KB · Grade " + data.proof.grade + ")";
          }
          if (proofSummary && data.proof && data.proof.pillars) {
            const p = data.proof.pillars;
            proofSummary.textContent =
              "Themes: " + p.contrast.themes_checked +
              " · Audit: " + p.audit.score + "/100 · Gzip: " + p.budget.gzip_kb.toFixed(2) +
              " / " + p.budget.max_gzip_kb.toFixed(1) + " KB";
          }
          const link = document.querySelector('link[rel="stylesheet"][href*="/assets/zokeibi.min.css"]');
          if (link && data.css_version) {
            link.setAttribute("href", "/assets/zokeibi.min.css?v=" + encodeURIComponent(data.css_version));
          }
        })
        .catch((err) => {
          if (err && err.name === "AbortError") return;
          if (liveSyncStatus) liveSyncStatus.textContent = "Sync error — click Save";
        });
    }, 350);
  }

  if (liveSyncToggle && liveSyncStatus) {
    liveSyncToggle.addEventListener("change", () => {
      liveSyncStatus.textContent = liveSyncToggle.checked ? "Live Hot-Swap armed" : "Manual save";
      if (liveSyncToggle.checked) scheduleLiveSync();
    });
  }

  [hueInput, chromaInput, lightInput, radiusInput].forEach((el) => {
    if (el) {
      el.addEventListener("input", () => {
        syncPreview();
        scheduleLiveSync();
      });
    }
  });

  [modeInput, fontScaleInput, fontStackInput].forEach((el) => {
    if (el) {
      el.addEventListener("change", () => {
        syncPreview();
        scheduleLiveSync();
      });
    }
  });

  syncPreview();
})();
