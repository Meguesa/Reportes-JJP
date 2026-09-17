# Reportes

Herramienta interna de Jardines de Juan Pablo para consulta y seguimiento de reportes por área.

## Responsabilidad del repositorio

Este repositorio contiene toda la implementación propia de Reportes:

- interfaz de la herramienta;
- estilos específicos;
- resolución de permisos por grupos de SharePoint;
- lectura de la lista `Reportes`;
- lectura de archivos adjuntos.

## Integración con el Portal

El Portal Interno únicamente proporciona:

- la sesión Microsoft 365 compartida;
- la tarjeta/enlace hacia la herramienta;
- un workflow puente de publicación que utiliza las credenciales FTPS ya existentes del Portal.

La lógica, interfaz, estilos y conexión SharePoint de Reportes no deben implementarse dentro de `Portal-Interno-JJP`.

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

La publicación se ejecuta desde `Meguesa/Portal-Interno-JJP` mediante el workflow:

`.github/workflows/publicar-reportes.yml`

Ese workflow descarga directamente la rama `main` de `Meguesa/Reportes-JJP` y publica únicamente los archivos de esta herramienta en `/reportes-preview/`.

`Reportes-JJP` no almacena credenciales FTP ni copias del código del Portal.
