/* Zokeibi.com Live Static OKLCH Token Studio — Zero Backend, Strict CSP, Zero innerHTML */
(() => {
  "use strict";

  const root = document.getElementById("studio-workbench");
  if (!root) return;

  const STORAGE_KEY = "zokeibi-web-studio-v1";
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
  const jsonCodeOut = document.getElementById("studio-json-preview");
  const w3cCodeOut = document.getElementById("studio-w3c-preview");
  const twCodeOut = document.getElementById("studio-tailwind-preview");
  const customThemesBox = document.getElementById("st-static-themes");
  const customColorsBox = document.getElementById("st-static-colors");
  const customThemesCount = document.getElementById("st-static-themes-count");
  const customColorsCount = document.getElementById("st-static-colors-count");

  const FONT_STACKS = {
    system: 'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
    editorial: 'Iowan Old Style, Palatino Linotype, "Book Antiqua", Georgia, ui-serif, serif',
    geometric: '"Segoe UI Variable", "Avenir Next", Avenir, "Helvetica Neue", ui-sans-serif, sans-serif',
    mono: '"JetBrains Mono", "Cascadia Code", "Fira Code", ui-monospace, SFMono-Regular, Menlo, monospace',
  };
  const FONT_SIZES = { compact: "13px", default: "14px", comfortable: "15px" };
  const TOKEN_RE = /^[a-z][a-z0-9-]{1,31}$/;
  const COLOR_RE = /^(?:#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})|oklch\(\s*(?:0?\.\d+|\d+(?:\.\d+)?)%?\s+(?:0?\.\d+|\d+(?:\.\d+)?)\s+(?:0?\.\d+|\d+(?:\.\d+)?)(?:\s*\/\s*(?:0?\.\d+|\d+(?:\.\d+)?)%?)?\s*\))$/;

  const state = {
    customThemes: {},
    customColors: {},
  };

  function loadSaved() {
    try {
      const raw = window.localStorage.getItem(STORAGE_KEY);
      if (!raw) return;
      const parsed = JSON.parse(raw);
      if (parsed && typeof parsed === "object") {
        if (parsed.hue != null && hueInput) hueInput.value = String(parsed.hue);
        if (parsed.chroma != null && chromaInput) chromaInput.value = String(parsed.chroma);
        if (parsed.accent_lightness != null && lightInput) lightInput.value = String(parsed.accent_lightness);
        if (parsed.radius_px != null && radiusInput) radiusInput.value = String(parsed.radius_px);
        if (parsed.mode && modeInput) modeInput.value = parsed.mode === "light" ? "light" : "dark";
        if (parsed.font_scale && fontScaleInput && FONT_SIZES[parsed.font_scale]) fontScaleInput.value = parsed.font_scale;
        if (parsed.font_stack && fontStackInput && FONT_STACKS[parsed.font_stack]) fontStackInput.value = parsed.font_stack;
        if (parsed.custom_themes && typeof parsed.custom_themes === "object") state.customThemes = parsed.custom_themes;
        if (parsed.custom_colors && typeof parsed.custom_colors === "object") state.customColors = parsed.custom_colors;
      }
    } catch (_e) {}
  }

  function persistState(cfg) {
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(cfg));
    } catch (_e) {}
  }

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
    return [
      linToByte(+4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s),
      linToByte(-1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s),
      linToByte(-0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s),
    ];
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
    return Math.round(((Math.max(y1, y2) + 0.05) / (Math.min(y1, y2) + 0.05)) * 100) / 100;
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

  function readKnobs() {
    const H = Number.isFinite(parseFloat(hueInput ? hueInput.value : "355")) ? parseFloat(hueInput.value) : 355;
    const C = Number.isFinite(parseFloat(chromaInput ? chromaInput.value : "0.145")) ? parseFloat(chromaInput.value) : 0.145;
    const L = Number.isFinite(parseFloat(lightInput ? lightInput.value : "0.65")) ? parseFloat(lightInput.value) : 0.65;
    const R = Number.isFinite(parseInt(radiusInput ? radiusInput.value : "12", 10)) ? parseInt(radiusInput.value, 10) : 12;
    const mode = modeInput && modeInput.value === "light" ? "light" : "dark";
    const fontScale = fontScaleInput && FONT_SIZES[fontScaleInput.value] ? fontScaleInput.value : "default";
    const fontStack = fontStackInput && FONT_STACKS[fontStackInput.value] ? fontStackInput.value : "system";
    return { H, C, L, R, mode, fontScale, fontStack };
  }

  function buildThemeCss(slug, def) {
    const H = def.hue;
    const C = def.chroma;
    const L = def.accent_lightness;
    const R = def.radius_px;
    const Rsm = Math.max(0, Math.round(R * 0.66));
    const inkL = recommendedInkL(L, C, H);
    const inkC = inkL < 0.5 ? 0.018 : 0.008;
    const accentStrong = Math.max(0.28, Math.round((L - 0.11) * 1000) / 1000);
    const hex = oklchToHex(L, C, H);
    const lines = [
      `  [data-zokeibi-theme="${slug}"] {`,
      `    color-scheme: ${def.mode};`,
      `    --z-hue: ${H.toFixed(1)};`,
      `    --z-chroma: ${C.toFixed(3)};`,
      `    --z-radius: ${R}px;`,
      `    --z-radius-sm: ${Rsm}px;`,
      `    --z-accent: oklch(${L.toFixed(3)} var(--z-chroma) var(--z-hue)); /* ${hex} */`,
      `    --z-accent-strong: oklch(${accentStrong.toFixed(3)} var(--z-chroma) var(--z-hue));`,
      `    --z-accent-ink: oklch(${inkL.toFixed(3)} ${inkC.toFixed(3)} var(--z-hue));`,
    ];
    if (def.mode === "light") {
      lines.push(
        "    --z-bg: oklch(0.972 0.004 95);",
        "    --z-surface: oklch(0.998 0.002 95);",
        "    --z-surface-2: oklch(0.948 0.006 264);",
        "    --z-ink: oklch(0.18 0.014 264);",
        "    --z-ink-muted: oklch(0.43 0.015 264);",
      );
    } else {
      lines.push(
        `    --z-bg: oklch(0.152 0.014 ${H.toFixed(1)});`,
        `    --z-surface: oklch(0.190 0.016 ${H.toFixed(1)});`,
        `    --z-surface-2: oklch(0.228 0.018 ${H.toFixed(1)});`,
        "    --z-ink: oklch(0.955 0.006 264);",
        "    --z-ink-muted: oklch(0.725 0.014 264);",
      );
    }
    lines.push("  }");
    return lines.join("\n");
  }

  function renderCustomLists() {
    if (customThemesCount) {
      customThemesCount.textContent = `${Object.keys(state.customThemes).length} saved`;
    }
    if (customColorsCount) {
      customColorsCount.textContent = `${Object.keys(state.customColors).length} tokens`;
    }
    if (customThemesBox) {
      customThemesBox.textContent = "";
      const names = Object.keys(state.customThemes);
      if (names.length === 0) {
        const empty = document.createElement("div");
        empty.className = "z-soft z-mono";
        empty.textContent = 'No custom themes saved yet. Snapshot your sliders above to emit [data-zokeibi-theme="..."].';
        customThemesBox.appendChild(empty);
      } else {
        names.forEach((name) => {
          const def = state.customThemes[name];
          const row = document.createElement("div");
          row.className = "st-token-row";
          const loadBtn = document.createElement("button");
          loadBtn.type = "button";
          loadBtn.className = "z-btn z-btn-ghost z-btn-sm";
          loadBtn.textContent = `${name} (${Math.round(def.hue)}° · ${def.mode})`;
          loadBtn.addEventListener("click", () => {
            if (hueInput) hueInput.value = String(def.hue);
            if (chromaInput) chromaInput.value = String(def.chroma);
            if (lightInput) lightInput.value = String(def.accent_lightness);
            if (radiusInput) radiusInput.value = String(def.radius_px);
            if (modeInput) modeInput.value = def.mode;
            syncAll();
          });
          const delBtn = document.createElement("button");
          delBtn.type = "button";
          delBtn.className = "z-btn z-btn-danger z-btn-sm";
          delBtn.setAttribute("aria-label", `Delete theme ${name}`);
          delBtn.textContent = "✕";
          delBtn.addEventListener("click", () => {
            delete state.customThemes[name];
            renderCustomLists();
            syncAll();
          });
          row.appendChild(loadBtn);
          row.appendChild(delBtn);
          customThemesBox.appendChild(row);
        });
      }
    }

    if (customColorsBox) {
      customColorsBox.textContent = "";
      const names = Object.keys(state.customColors);
      if (names.length === 0) {
        const empty = document.createElement("div");
        empty.className = "z-soft z-mono";
        empty.textContent = "Add project-specific tokens like --z-color-brand-teal or --z-color-promo-gold.";
        customColorsBox.appendChild(empty);
      } else {
        names.forEach((name) => {
          const val = state.customColors[name];
          const row = document.createElement("div");
          row.className = "st-token-row";
          const left = document.createElement("span");
          left.className = "z-row";
          const chip = document.createElement("span");
          chip.className = "st-chip";
          chip.setAttribute("aria-hidden", "true");
          chip.style.setProperty("background-color", val);
          const lbl = document.createElement("span");
          lbl.textContent = `--z-color-${name}`;
          const vEl = document.createElement("span");
          vEl.className = "z-soft";
          vEl.textContent = val;
          left.appendChild(chip);
          left.appendChild(lbl);
          left.appendChild(vEl);
          const delBtn = document.createElement("button");
          delBtn.type = "button";
          delBtn.className = "z-btn z-btn-danger z-btn-sm";
          delBtn.setAttribute("aria-label", `Delete color ${name}`);
          delBtn.textContent = "✕";
          delBtn.addEventListener("click", () => {
            delete state.customColors[name];
            renderCustomLists();
            syncAll();
          });
          row.appendChild(left);
          row.appendChild(delBtn);
          customColorsBox.appendChild(row);
        });
      }
    }
  }

  function syncAll() {
    if (!preview) return;
    const { H, C, L, R, mode, fontScale, fontStack } = readKnobs();
    const Rsm = Math.max(0, Math.round(R * 0.66));
    const inkL = recommendedInkL(L, C, H);
    const inkC = inkL < 0.5 ? 0.018 : 0.008;
    const accentStrong = Math.max(0.28, Math.round((L - 0.11) * 1000) / 1000);
    const hex = oklchToHex(L, C, H);
    const accentExpr = `oklch(${L.toFixed(3)} ${C.toFixed(3)} ${H.toFixed(1)})`;

    if (hueOut) hueOut.textContent = H.toFixed(0) + "°";
    if (chromaOut) chromaOut.textContent = C.toFixed(3);
    if (lightOut) lightOut.textContent = L.toFixed(2);
    if (radiusOut) radiusOut.textContent = R + "px";
    if (hexOut) hexOut.textContent = hex;
    if (accentChip) accentChip.style.setProperty("background-color", hex);

    preview.style.setProperty("--z-hue", String(H));
    preview.style.setProperty("--z-chroma", C.toFixed(3));
    preview.style.setProperty("--z-radius", R + "px");
    preview.style.setProperty("--z-radius-sm", Rsm + "px");
    preview.style.setProperty("--z-accent", accentExpr);
    preview.style.setProperty("--z-accent-strong", `oklch(${accentStrong.toFixed(3)} ${C.toFixed(3)} ${H.toFixed(1)})`);
    preview.style.setProperty("--z-accent-ink", `oklch(${inkL.toFixed(3)} ${inkC.toFixed(3)} ${H.toFixed(1)})`);
    preview.style.setProperty("--z-accent-soft", `color-mix(in oklch, ${accentExpr} 16%, transparent)`);
    preview.style.setProperty("--z-accent-border", `color-mix(in oklch, ${accentExpr} 42%, transparent)`);
    preview.style.setProperty("--z-sans", FONT_STACKS[fontStack] || FONT_STACKS.system);
    preview.style.setProperty("font-size", FONT_SIZES[fontScale] || "14px");

    const isLight = mode === "light";
    if (isLight) {
      preview.style.setProperty("--z-bg", "oklch(0.972 0.004 95)");
      preview.style.setProperty("--z-surface", "oklch(0.998 0.002 95)");
      preview.style.setProperty("--z-surface-2", "oklch(0.948 0.006 264)");
      preview.style.setProperty("--z-surface-hover", "oklch(0.918 0.008 264)");
      preview.style.setProperty("--z-border", "oklch(0.885 0.01 264)");
      preview.style.setProperty("--z-border-strong", "oklch(0.795 0.014 264)");
      preview.style.setProperty("--z-ink", "oklch(0.18 0.014 264)");
      preview.style.setProperty("--z-ink-muted", "oklch(0.43 0.015 264)");
      preview.style.setProperty("--z-ink-soft", "oklch(0.47 0.014 264)");
      preview.style.setProperty("--z-emerald", "oklch(0.46 0.14 162)");
      preview.style.setProperty("--z-amber", "oklch(0.49 0.145 75)");
      preview.style.setProperty("--z-danger", "oklch(0.50 0.185 20)");
      preview.style.setProperty("--z-blue", "oklch(0.48 0.155 256)");
    } else {
      preview.style.setProperty("--z-bg", `oklch(0.152 0.014 ${H.toFixed(1)})`);
      preview.style.setProperty("--z-surface", `oklch(0.190 0.016 ${H.toFixed(1)})`);
      preview.style.setProperty("--z-surface-2", `oklch(0.228 0.018 ${H.toFixed(1)})`);
      preview.style.setProperty("--z-surface-hover", `oklch(0.262 0.020 ${H.toFixed(1)})`);
      preview.style.setProperty("--z-border", `oklch(0.296 0.020 ${H.toFixed(1)})`);
      preview.style.setProperty("--z-border-strong", `oklch(0.372 0.024 ${H.toFixed(1)})`);
      preview.style.setProperty("--z-ink", "oklch(0.955 0.006 264)");
      preview.style.setProperty("--z-ink-muted", "oklch(0.725 0.014 264)");
      preview.style.setProperty("--z-ink-soft", "oklch(0.645 0.014 264)");
      preview.style.setProperty("--z-emerald", "oklch(0.72 0.155 162)");
      preview.style.setProperty("--z-amber", "oklch(0.80 0.15 78)");
      preview.style.setProperty("--z-danger", "oklch(0.66 0.195 22)");
      preview.style.setProperty("--z-blue", "oklch(0.71 0.15 254)");
    }

    const bgL = isLight ? 0.972 : 0.152;
    const surfL = isLight ? 0.998 : 0.190;
    const bodyInkL = isLight ? 0.18 : 0.955;
    const mutedL = isLight ? 0.43 : 0.725;
    const surfH = isLight ? 95 : H;

    updateContrastBadge("accent_button", contrastRatio(inkL, inkC, H, L, C, H));
    updateContrastBadge("body_text", contrastRatio(bodyInkL, isLight ? 0.014 : 0.006, 264, bgL, isLight ? 0.004 : 0.014, surfH));
    updateContrastBadge("card_text", contrastRatio(bodyInkL, isLight ? 0.014 : 0.006, 264, surfL, isLight ? 0.002 : 0.016, surfH));
    updateContrastBadge("muted_text", contrastRatio(mutedL, 0.014, 264, surfL, isLight ? 0.002 : 0.016, surfH));

    // Update all 4 export outputs live
    const studioCfg = {
      active_theme: "studio",
      radius_preset: "default",
      hue: H,
      chroma: C,
      accent_lightness: L,
      mode,
      radius_px: R,
      font_scale: fontScale,
      font_stack: fontStack,
      custom_colors: state.customColors,
      custom_themes: state.customThemes,
    };
    persistState(studioCfg);

    if (cssCodeOut) {
      const blocks = ["@layer zokeibi.themes {"];
      const rootVars = [];
      if (fontStack !== "system") rootVars.push(`    --z-sans: ${FONT_STACKS[fontStack]};`);
      Object.keys(state.customColors).forEach((k) => rootVars.push(`    --z-color-${k}: ${state.customColors[k]};`));
      if (rootVars.length > 0) blocks.push("  :root {\n" + rootVars.join("\n") + "\n  }");
      blocks.push(buildThemeCss("studio", studioCfg));
      Object.keys(state.customThemes).forEach((tName) => blocks.push(buildThemeCss(tName, state.customThemes[tName])));
      blocks.push("}");
      cssCodeOut.textContent = blocks.join("\n");
    }

    if (jsonCodeOut) {
      jsonCodeOut.textContent = JSON.stringify(
        {
          zokeibi: "1.4.0",
          theme: "studio",
          radius: "default",
          views_dir: "views",
          css_out: "public/assets/zokeibi.min.css",
          studio: studioCfg,
        },
        null,
        2,
      );
    }

    if (w3cCodeOut) {
      const w3cColors = {
        accent: { $type: "color", $value: accentExpr, $description: `Hex approx ${hex}` },
        "accent-ink": { $type: "color", $value: `oklch(${inkL.toFixed(3)} ${inkC.toFixed(3)} ${H.toFixed(1)})` },
        bg: { $type: "color", $value: isLight ? "oklch(0.972 0.004 95)" : `oklch(0.152 0.014 ${H.toFixed(1)})` },
        surface: { $type: "color", $value: isLight ? "oklch(0.998 0.002 95)" : `oklch(0.190 0.016 ${H.toFixed(1)})` },
      };
      Object.keys(state.customColors).forEach((k) => {
        w3cColors[k] = { $type: "color", $value: state.customColors[k] };
      });
      w3cCodeOut.textContent = JSON.stringify(
        {
          $schema: "https://design-tokens.github.io/community-group/format/",
          zokeibi: {
            color: w3cColors,
            radius: {
              default: { $type: "dimension", $value: `${R}px` },
              sm: { $type: "dimension", $value: `${Rsm}px` },
            },
            typography: {
              "font-sans": { $type: "fontFamily", $value: FONT_STACKS[fontStack] },
              "font-size": { $type: "dimension", $value: FONT_SIZES[fontScale] },
            },
          },
        },
        null,
        2,
      );
    }

    if (twCodeOut) {
      const twLines = [
        "@theme {",
        `  --color-accent: ${accentExpr};`,
        `  --color-accent-ink: oklch(${inkL.toFixed(3)} ${inkC.toFixed(3)} ${H.toFixed(1)});`,
        `  --color-bg: ${isLight ? "oklch(0.972 0.004 95)" : `oklch(0.152 0.014 ${H.toFixed(1)})`};`,
        `  --color-surface: ${isLight ? "oklch(0.998 0.002 95)" : `oklch(0.190 0.016 ${H.toFixed(1)})`};`,
        `  --radius-default: ${R}px;`,
        `  --radius-sm: ${Rsm}px;`,
        `  --font-sans: ${FONT_STACKS[fontStack]};`,
      ];
      Object.keys(state.customColors).forEach((k) => {
        twLines.push(`  --color-${k}: ${state.customColors[k]};`);
      });
      twLines.push("}");
      twCodeOut.textContent = twLines.join("\n");
    }
  }

  // Preset buttons
  document.querySelectorAll("[data-studio-preset]").forEach((btn) => {
    btn.addEventListener("click", () => {
      if (hueInput && btn.dataset.hue) hueInput.value = btn.dataset.hue;
      if (chromaInput && btn.dataset.chroma) chromaInput.value = btn.dataset.chroma;
      if (lightInput && btn.dataset.lightness) lightInput.value = btn.dataset.lightness;
      if (modeInput && btn.dataset.mode) modeInput.value = btn.dataset.mode;
      if (radiusInput && btn.dataset.radius) radiusInput.value = btn.dataset.radius;
      syncAll();
    });
  });

  // Intercept static Studio forms (Save, Reset, Add Theme, Add Color) client-side
  const saveForm = document.getElementById("studio-save-form");
  if (saveForm) {
    saveForm.addEventListener("submit", (e) => {
      e.preventDefault();
      syncAll();
      if (window.Zokeibi && window.Zokeibi.toast) {
        window.Zokeibi.toast({ title: "Tokens updated", description: "Saved in browser & ready to copy from the Export tabs below.", variant: "emerald" });
      }
    });
  }

  const resetBtn = document.getElementById("st-reset-btn");
  if (resetBtn && resetBtn.form) {
    resetBtn.form.addEventListener("submit", (e) => {
      e.preventDefault();
      if (hueInput) hueInput.value = "355";
      if (chromaInput) chromaInput.value = "0.145";
      if (lightInput) lightInput.value = "0.65";
      if (radiusInput) radiusInput.value = "12";
      if (modeInput) modeInput.value = "dark";
      if (fontScaleInput) fontScaleInput.value = "default";
      if (fontStackInput) fontStackInput.value = "system";
      state.customThemes = {};
      state.customColors = {};
      renderCustomLists();
      syncAll();
      if (window.Zokeibi && window.Zokeibi.toast) {
        window.Zokeibi.toast({ title: "Reset to Sumi defaults", variant: "accent" });
      }
    });
  }

  const addThemeBtn = document.getElementById("st-add-theme-btn");
  const newThemeInp = document.getElementById("st-new-theme-name");
  if (addThemeBtn && addThemeBtn.form && newThemeInp) {
    addThemeBtn.form.addEventListener("submit", (e) => {
      e.preventDefault();
      const slug = (newThemeInp.value || "").trim().toLowerCase();
      if (!TOKEN_RE.test(slug) || ["sumi", "washi", "indigo", "matcha", "kintsugi", "studio"].includes(slug)) {
        if (window.Zokeibi && window.Zokeibi.toast) {
          window.Zokeibi.toast({ title: "Invalid theme name", description: "Use 2-32 lowercase letters, digits, or hyphens (e.g. acme-ocean).", variant: "amber" });
        }
        return;
      }
      const { H, C, L, R, mode } = readKnobs();
      state.customThemes[slug] = { hue: H, chroma: C, accent_lightness: L, mode, radius_px: R };
      newThemeInp.value = "";
      renderCustomLists();
      syncAll();
      if (window.Zokeibi && window.Zokeibi.toast) {
        window.Zokeibi.toast({ title: `Saved theme "${slug}"`, description: "Emitted in CSS & zokeibi.json exports.", variant: "emerald" });
      }
    });
  }

  const addColorBtn = document.getElementById("st-add-color-btn");
  const colorNameInp = document.getElementById("st-color-name");
  const colorValInp = document.getElementById("st-color-value");
  const colorPicker = document.getElementById("st-color-picker");
  if (colorPicker && colorValInp) {
    colorPicker.addEventListener("input", () => {
      colorValInp.value = colorPicker.value;
    });
  }
  if (addColorBtn && addColorBtn.form && colorNameInp && colorValInp) {
    addColorBtn.form.addEventListener("submit", (e) => {
      e.preventDefault();
      const slug = (colorNameInp.value || "").trim().toLowerCase();
      const val = (colorValInp.value || "").trim();
      if (!TOKEN_RE.test(slug) || !COLOR_RE.test(val)) {
        if (window.Zokeibi && window.Zokeibi.toast) {
          window.Zokeibi.toast({ title: "Invalid token or color", description: "Use a slug (e.g. brand-teal) and #hex or oklch(...).", variant: "amber" });
        }
        return;
      }
      state.customColors[slug] = val;
      colorNameInp.value = "";
      renderCustomLists();
      syncAll();
      if (window.Zokeibi && window.Zokeibi.toast) {
        window.Zokeibi.toast({ title: `Added --z-color-${slug}`, variant: "emerald" });
      }
    });
  }

  // Copy active export tab
  const copyBtn = document.getElementById("st-copy-css");
  if (copyBtn) {
    copyBtn.textContent = "Copy Active Export";
    copyBtn.addEventListener("click", () => {
      const visiblePre = document.querySelector(".z-tabpanel:not([hidden]) pre.z-code") || cssCodeOut;
      const text = visiblePre ? visiblePre.textContent || "" : "";
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
          copyBtn.textContent = "Copied!";
          window.setTimeout(() => { copyBtn.textContent = "Copy Active Export"; }, 1400);
        });
      }
    });
  }

  [hueInput, chromaInput, lightInput, radiusInput].forEach((el) => {
    if (el) el.addEventListener("input", syncAll);
  });
  [modeInput, fontScaleInput, fontStackInput].forEach((el) => {
    if (el) el.addEventListener("change", syncAll);
  });

  loadSaved();
  renderCustomLists();
  syncAll();
})();
