import { defineConfig } from "vite";

export default defineConfig({
  base: "/Reportes-JJP/",
  build: {
    rollupOptions: {
      input: {
        main: "index.html",
        auth: "auth-redirect.html"
      }
    }
  }
});
