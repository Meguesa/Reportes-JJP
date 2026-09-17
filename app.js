import { PublicClientApplication } from "@azure/msal-browser";
import { REPORTES_JJP_CONFIG as config } from "./config.js";

const msalInstance = new PublicClientApplication({
  auth: {
    clientId: config.auth.clientId,
    authority: config.auth.authority,
    redirectUri: config.auth.redirectUri,
    navigateToLoginRequestUrl: false
  },
  cache: {
    cacheLocation: "sessionStorage"
  }
});

function setText(id, value) {
  const element = document.getElementById(id);
  if (element) element.textContent = value;
}

function setSignedInState(account) {
  const loginButton = document.getElementById("loginButton");
  const logoutButton = document.getElementById("logoutButton");
  const status = document.getElementById("appStatus");

  if (account) {
    msalInstance.setActiveAccount(account);
    setText("userName", account.name || account.username || "Usuario autenticado");
    setText("connectionTitle", "Sesión Microsoft activa");
    setText("connectionText", `Usuario autenticado: ${account.username || account.name || "cuenta organizacional"}. El siguiente paso será consultar sus permisos y la lista de SharePoint.`);
    setText("connectionBadge", "Autenticado");
    if (status) status.innerHTML = '<span class="status-dot"></span><span>Sesión activa</span>';
    if (loginButton) loginButton.hidden = true;
    if (logoutButton) logoutButton.hidden = false;
  } else {
    setText("userName", "Sin iniciar sesión");
    setText("connectionTitle", "Autenticación Microsoft preparada");
    setText("connectionText", "Inicia sesión con una cuenta de la organización para validar el acceso.");
    setText("connectionBadge", "Pendiente");
    if (status) status.innerHTML = '<span class="status-dot"></span><span>Sin sesión</span>';
    if (loginButton) loginButton.hidden = false;
    if (logoutButton) logoutButton.hidden = true;
  }
}

async function login() {
  await msalInstance.loginRedirect({
    scopes: config.auth.loginScopes,
    prompt: "select_account"
  });
}

async function logout() {
  const account = msalInstance.getActiveAccount() || msalInstance.getAllAccounts()[0];
  await msalInstance.logoutRedirect({
    account,
    postLogoutRedirectUri: "https://meguesa.github.io/Reportes-JJP/"
  });
}

async function initializeApp() {
  setText("siteName", config.sharePoint.siteName);
  setText("listName", config.sharePoint.listName);
  setText("currentYear", new Date().getFullYear().toString());

  await msalInstance.initialize();

  const accounts = msalInstance.getAllAccounts();
  setSignedInState(accounts[0] || null);

  document.getElementById("loginButton")?.addEventListener("click", login);
  document.getElementById("logoutButton")?.addEventListener("click", logout);
}

initializeApp().catch((error) => {
  console.error("Error al inicializar Reportes JJP:", error);
  setText("connectionTitle", "Error de autenticación");
  setText("connectionText", "No fue posible inicializar Microsoft Entra. Revisa la consola del navegador para ver el detalle.");
  setText("connectionBadge", "Error");
});
