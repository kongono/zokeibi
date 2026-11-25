import { defineConfig } from "cf/config";

export default defineConfig({
  worker: {
    name: "zokeibi",
    compatibilityDate: "2026-11-25",
    domains: ["zokeibi.com", "www.zokeibi.com"],
    workersDev: false,
    previewUrls: true,
    assets: {
      htmlHandling: "auto-trailing-slash",
      notFoundHandling: "none",
    },
  },
});
