export const REPORTES_JJP_CONFIG = Object.freeze({
  appName: "Reportes JJP",
  auth: {
    clientId: "78813e75-d4b0-4dd4-868b-8b7e9d6d94a7",
    tenantId: "888d54c0-f785-49d1-b967-54da8b0aed94",
    authority: "https://login.microsoftonline.com/888d54c0-f785-49d1-b967-54da8b0aed94",
    redirectUri: "https://meguesa.github.io/Reportes-JJP/auth-redirect.html",
    loginScopes: ["openid", "profile", "email"]
  },
  sharePoint: {
    hostname: "meguesajdjp.sharepoint.com",
    sitePath: "/sites/CentroControlDireccion",
    siteName: "Centro de Control Dirección",
    listName: "Reportes",
    listUrl: "https://meguesajdjp.sharepoint.com/sites/CentroControlDireccion/Lists/Reportes/AllItems.aspx"
  },
  areas: ["Vendedores", "Parque", "Capillas", "Administradores"]
});
