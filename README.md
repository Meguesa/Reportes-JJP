# Reportes

Herramienta interna de Jardines de Juan Pablo para consulta y seguimiento de reportes por área.

## Responsabilidad del repositorio

Este repositorio contiene toda la implementación propia de Reportes:

- interfaz de la herramienta;
- estilos específicos;
- resolución de permisos por grupos de SharePoint;
- lectura de la lista `Reportes`;
- lectura de archivos adjuntos;
- despliegue de la herramienta a cPanel.

## Integración con el Portal

El Portal Interno únicamente proporciona la sesión Microsoft 365 compartida y la entrada visual hacia la herramienta. La lógica de Reportes no debe implementarse dentro de `Portal-Interno-JJP`.

## Ruta temporal

`https://portal.juanpablo.com.mx/reportes-preview/`

## Grupos de acceso

- `Reportes - Vendedores`
- `Reportes - Parque`
- `Reportes - Capillas`
- `Reportes - Administradores`

## SharePoint

Sitio: `CentroControlDireccion`

Lista: `Reportes`

## Despliegue

Workflow: `.github/workflows/deploy-cpanel.yml`

El repositorio requiere estos secretos de GitHub Actions:

- `FTP_SERVER`
- `FTP_USERNAME`
- `FTP_PASSWORD`
