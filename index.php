<?php

declare(strict_types=1);

// La autenticación pertenece al Portal; la herramienta Reportes reutiliza esa sesión.
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/reportes-sharepoint.php';
portal_require_authentication();

$user = portal_user();
$name = htmlspecialchars((string) ($user['name'] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$emailRaw = strtolower(trim((string) ($user['email'] ?? '')));
$email = htmlspecialchars($emailRaw, ENT_QUOTES, 'UTF-8');

$reportAreas = [];
$reportError = '';
$reports = [];

function reportes_value(array $row, array $candidateKeys, string $default = ''): string
{
    foreach ($candidateKeys as $key) {
        if (!array_key_exists($key, $row)) continue;
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') return $value;
    }

    $normalized = [];
    foreach ($row as $key => $value) {
        $normalized[strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key))] = $value;
    }
    foreach ($candidateKeys as $key) {
        $needle = strtolower(preg_replace('/[^a-z0-9]/i', '', $key));
        if (!array_key_exists($needle, $normalized)) continue;
        $value = trim((string) ($normalized[$needle] ?? ''));
        if ($value !== '') return $value;
    }
    return $default;
}

function reportes_area_allowed(string $area, array $allowedAreas, bool $isAdministrator): bool
{
    if ($isAdministrator) return true;
    foreach ($allowedAreas as $allowed) {
        if (strcasecmp(trim($area), trim((string) $allowed)) === 0) return true;
    }
    return false;
}

try {
    $reportAreas = reportes_user_areas($emailRaw);
    $isAdministrator = in_array('Administradores', $reportAreas, true);

    if (count($reportAreas) === 0) {
        $reportError = 'Tu cuenta no pertenece a un grupo con acceso a Reportes.';
    } else {
        foreach (reportes_list_items(300) as $row) {
            $area = reportes_value($row, ['AreaAsignada', 'Area_x0020_Asignada', 'Area'], 'Sin área');
            if (!reportes_area_allowed($area, $reportAreas, $isAdministrator)) continue;

            $itemId = (int) ($row['Id'] ?? $row['ID'] ?? 0);
            $title = reportes_value($row, ['Title', 'Titulo', 'Título', 'Nombre'], 'Reporte sin título');
            $type = reportes_value($row, ['TipoReporte', 'Tipo_x0020_Reporte', 'Tipo'], 'Reporte');
            $description = reportes_value($row, ['Descripcion', 'Descripción', 'Description', 'Comentarios'], '');
            $url = reportes_value($row, ['URL', 'Url', 'Liga', 'Enlace', 'Link'], '');
            $attachments = [];

            if ($itemId > 0 && !empty($row['Attachments'])) {
                try {
                    $attachments = reportes_item_attachments($itemId);
                } catch (Throwable $attachmentError) {
                    error_log('Reportes adjuntos item ' . $itemId . ': ' . $attachmentError->getMessage());
                }
            }

            $reports[] = [
                'id' => $itemId,
                'title' => $title,
                'type' => $type,
                'area' => $area,
                'description' => $description,
                'url' => $url,
                'attachments' => $attachments,
            ];
        }

        usort($reports, static function (array $a, array $b): int {
            $areaCompare = strcasecmp((string) $a['area'], (string) $b['area']);
            return $areaCompare !== 0
                ? $areaCompare
                : strcasecmp((string) $a['title'], (string) $b['title']);
        });
    }
} catch (Throwable $error) {
    error_log('Reportes SharePoint: ' . $error->getMessage());
    $reportError = 'No fue posible consultar Reportes: ' . $error->getMessage();
}

$isAdministrator = in_array('Administradores', $reportAreas, true);
$visibleAreas = $isAdministrator
    ? ['Vendedores', 'Parque', 'Capillas', 'Administradores']
    : $reportAreas;
?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#ffffff">
  <title>Reportes | Vista previa</title>
  <link rel="stylesheet" href="/assets/css/brand.css?v=20260823-map-header-1">
  <link rel="stylesheet" href="/reportes-preview/styles.css?v=20260917-repo-1">
  <link rel="stylesheet" href="/assets/css/account-menu.css">
</head>
<body>
  <header class="brand-header">
    <div class="brand-main">
      <div class="shell brand-main-inner">
        <div class="portal-header-left">
          <img class="portal-header-logo" src="/mapa/assets/logo.jpg" alt="Jardines de Juan Pablo">
          <div class="portal-identity">
            <strong>Reportes</strong>
            <span>Vista previa interna</span>
          </div>
        </div>
        <div class="portal-header-context">Herramienta en desarrollo</div>
        <div class="portal-header-actions">
          <a class="header-action" href="/">Volver al Portal</a>
          <details class="account-menu">
            <summary class="account-trigger" aria-label="Abrir menú de usuario" title="<?= $name ?>">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="8" r="4" fill="currentColor" />
                <path d="M4 20c0-4.1 3.6-6 8-6s8 1.9 8 6v1H4z" fill="currentColor" />
              </svg>
            </summary>
            <div class="account-menu-panel">
              <div class="account-menu-info">
                <strong><?= $name ?></strong>
                <span><?= $email ?></span>
              </div>
              <a class="account-menu-logout" href="/logout.php">Cerrar sesión</a>
            </div>
          </details>
        </div>
      </div>
    </div>
  </header>

  <main class="shell reportes-main">
    <section class="reportes-hero">
      <span class="reportes-kicker">Vista previa</span>
      <h1>Consulta y seguimiento de reportes</h1>
      <p>Los reportes visibles se obtienen directamente de SharePoint de acuerdo con los grupos asignados a tu cuenta.</p>
      <div class="reportes-meta">
        <div><span>Usuario</span><strong><?= $name ?></strong></div>
        <div><span>Cuenta</span><strong><?= $email ?></strong></div>
        <div><span>Áreas autorizadas</span><strong><?= htmlspecialchars(count($visibleAreas) > 0 ? implode(', ', $visibleAreas) : 'Sin acceso', ENT_QUOTES, 'UTF-8') ?></strong></div>
      </div>
    </section>

    <?php if ($reportError !== ''): ?>
      <section class="reportes-note reportes-note-error" role="alert">
        <div>
          <span class="reportes-kicker">Estado</span>
          <h2>Acceso a Reportes no disponible</h2>
          <p><?= htmlspecialchars($reportError, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <span class="reportes-status">Revisar</span>
      </section>
    <?php else: ?>
      <section class="reportes-heading">
        <div>
          <span class="reportes-kicker">SharePoint</span>
          <h2>Reportes disponibles</h2>
        </div>
        <span class="reportes-status reportes-status-ok"><?= count($reports) ?> encontrados</span>
      </section>

      <?php if (count($reports) === 0): ?>
        <section class="reportes-note">
          <div>
            <span class="reportes-kicker">Sin registros</span>
            <h2>No hay reportes disponibles para tus áreas</h2>
            <p>La conexión con SharePoint funciona, pero no se encontraron registros visibles con tu configuración actual.</p>
          </div>
          <span class="reportes-status">0 reportes</span>
        </section>
      <?php else: ?>
        <section class="report-list" aria-label="Reportes disponibles">
          <?php foreach ($reports as $report): ?>
            <article class="report-item">
              <div class="report-item-top">
                <div>
                  <span class="report-area"><?= htmlspecialchars((string) $report['area'], ENT_QUOTES, 'UTF-8') ?></span>
                  <h3><?= htmlspecialchars((string) $report['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                </div>
                <span class="report-type"><?= htmlspecialchars((string) $report['type'], ENT_QUOTES, 'UTF-8') ?></span>
              </div>

              <?php if ((string) $report['description'] !== ''): ?>
                <p class="report-description"><?= htmlspecialchars((string) $report['description'], ENT_QUOTES, 'UTF-8') ?></p>
              <?php endif; ?>

              <?php if ((string) $report['url'] !== '' || count($report['attachments']) > 0): ?>
                <div class="report-actions">
                  <?php if ((string) $report['url'] !== ''): ?>
                    <a href="<?= htmlspecialchars((string) $report['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Abrir reporte</a>
                  <?php endif; ?>
                  <?php foreach ($report['attachments'] as $attachment): ?>
                    <a href="<?= htmlspecialchars((string) $attachment['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string) $attachment['name'], ENT_QUOTES, 'UTF-8') ?></a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
    <?php endif; ?>

    <section class="reportes-note">
      <div>
        <span class="reportes-kicker">Integración</span>
        <h2>Lista SharePoint conectada</h2>
        <p>Fuente: Centro de Control Dirección / Reportes. Los permisos pertenecen a esta herramienta y se resuelven con sus grupos de SharePoint.</p>
      </div>
      <span class="reportes-status reportes-status-ok">Conectado</span>
    </section>
  </main>
</body>
</html>
