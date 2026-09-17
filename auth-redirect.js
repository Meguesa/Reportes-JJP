import { PublicClientApplication } from "@azure/msal-browser";
import { REPORTES_JJP_CONFIG } from "./config.js";

const msalInstance = new PublicClientApplication({
  auth: {
    clientId: REPORTES_JJP_CONFIG.auth.clientId,
    authority: REPORTES_JJP_CONFIG.auth.authority,
    redirectUri: REPORTES_JJP_CONFIG.auth.redirectUri,
    navigateToLoginRequestUrl: false
  },
  cache: {
    cacheLocation: "sessionStorage"
  }
});

await msalInstance.initialize();

try {
  await msalInstance.handleRedirectPromise();
  window.location.replace("/Reportes-JJP/");
} catch (error) {
  console.error("Error al completar la autenticación:", error);
  document.body.innerHTML = "<p>No fue posible completar el inicio de sesión. Cierra esta pestaña e inténtalo nuevamente.</p>";
}
