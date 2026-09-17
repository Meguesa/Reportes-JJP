(() => {
  "use strict";

  const config = window.REPORTES_JJP_CONFIG;

  function setText(id, value) {
    const element = document.getElementById(id);
    if (element) element.textContent = value;
  }

  function initializeApp() {
    if (!config) {
      console.error("No se encontró REPORTES_JJP_CONFIG.");
      const status = document.getElementById("appStatus");
      if (status) {
        status.innerHTML = '<span class="status-dot"></span><span>Error de configuración</span>';
      }
      return;
    }

    setText("siteName", config.sharePoint.siteName);
    setText("listName", config.sharePoint.listName);
    setText("currentYear", new Date().getFullYear().toString());

    console.info(`${config.appName} inicializado.`);
    console.info("SharePoint configurado:", {
      hostname: config.sharePoint.hostname,
      sitePath: config.sharePoint.sitePath,
      listName: config.sharePoint.listName
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initializeApp);
  } else {
    initializeApp();
  }
})();
