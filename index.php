<?php

declare(strict_types=1);

// Único punto de integración con el Portal: reutilizar su sesión autenticada.
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/reportes-sharepoint.php';
portal_require_authentication();

$user = portal_user();
$name = htmlspecialchars((string) ($user['name'] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$emailRaw = strtolower(trim((string) ($user['email'] ?? '')));
$email = htmlspecialchars($emailRaw, ENT_QUOTES, 'UTF-8');

$reportRoles = [];
$reportError = '';
$reports = [];
$groupDiagnostics = [];

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

function reportes_role_enabled(array $roles, string $role): bool
{
    foreach ($roles as $candidate) {
        if (strcasecmp(trim((string) $candidate), trim($role)) === 0) return true;
    }
    return false;
}

/**
 * Reglas de visibilidad:
 * - Administradores: todos los reportes.
 * - Parque / Capillas: reportes asignados a su AreaAsignada.
 * - Vendedores: únicamente sus propios reportes, identificados por SolicitanteCorreo.
 */
function reportes_row_visible(array $row, array $roles, string $email): bool
{
    if (reportes_role_enabled($roles, 'Administradores')) return true;

    $area = reportes_value($row, ['AreaAsignada', 'Area_x0020_Asignada', 'Area'], '');
    if (reportes_role_enabled($roles, 'Parque') && strcasecmp($area, 'Parque') === 0) return true;
    if (reportes_role_enabled($roles, 'Capillas') && strcasecmp($area, 'Capillas') === 0) return true;

    if (reportes_role_enabled($roles, 'Vendedores')) {
        $solicitanteCorreo = strtolower(reportes_value($row, [
            'SolicitanteCorreo',
            'Solicitante_x0020_Correo',
            'CorreoSolicitante',
            'Correo_x0020_Solicitante',
        ], ''));
        if ($solicitanteCorreo !== '' && $solicitanteCorreo === strtolower(trim($email))) return true;
    }

    return false;
}

try {
    $reportRoles = reportes_user_areas($emailRaw);

    if (count($reportRoles) === 0) {
        $reportError = 'Tu cuenta no pertenece a un grupo con acceso a Reportes.';
        try {
            $groupDiagnostics = reportes_group_diagnostics($emailRaw);
        } catch (Throwable $diagnosticError) {
            $groupDiagnostics = [[
                'area' => 'Diagnóstico',
                'group' => 'Consulta de grupos',
                'status' => 'error',
                'members' => 0,
                'matched' => false,
            ]];
            error_log('Reportes diagnóstico grupos: ' . $diagnosticError->getMessage());
        }
    } else {
        foreach (reportes_list_items(300) as $row) {
            if (!reportes_row_visible($row, $reportRoles, $emailRaw)) continue;

            $area = reportes_value($row, ['AreaAsignada', 'Area_x0020_Asignada', 'Area'], 'Sin área');
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

$visibleRoles = reportes_role_enabled($reportRoles, 'Administradores')
    ? ['Vendedores', 'Parque', 'Capillas', 'Administradores']
    : $reportRoles;
?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#ffffff">
  <title>Reportes | Vista previa</title>
  <link rel="stylesheet" href="/reportes-preview/styles.css?v=20260917-roles-1">
</head>
<body>
  <header class="reportes-header">
    <div class="shell reportes-header-inner">
      <div class="reportes-brand">
        <div class="reportes-logo" aria-hidden="true">
          <svg viewBox="0 0 64 64" role="img">
            <g fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round">
              <path d="M32 6v12M32 46v12M6 32h12M46 32h12M13.6 13.6l8.5 8.5M41.9 41.9l8.5 8.5M50.4 13.6l-8.5 8.5M22.1 41.9l-8.5 8.5"/>
            </g>
            <circle cx="32" cy="32" r="9" fill="currentColor"/>
          </svg>
        </div>
        <div class="reportes-identity">
          <strong>Reportes</strong>
          <span>Vista previa interna</span>
        </div>
      </div>

      <div class="reportes-header-context">Herramienta en desarrollo</div>

      <div class="reportes-header-actions">
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
  </header>

  <main class="shell reportes-main">
    <section class="reportes-hero">
      <span class="reportes-kicker">Vista previa</span>
      <h1>Consulta y seguimiento de reportes</h1>
      <p>Los reportes visibles se obtienen directamente de SharePoint de acuerdo con los grupos asignados a tu cuenta.</p>
      <div class="reportes-meta">
        <div><span>Usuario</span><strong><?= $name ?></strong></div>
        <div><span>Cuenta</span><strong><?= $email ?></strong></div>
        <div><span>Accesos autorizados</span><strong><?= htmlspecialchars(count($visibleRoles) > 0 ? implode(', ', $visibleRoles) : 'Sin acceso', ENT_QUOTES, 'UTF-8') ?></strong></div>
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

      <?php if (count($groupDiagnostics) > 0): ?>
        <section class="diagnostic-card" aria-label="Diagnóstico de grupos de Reportes">
          <div class="diagnostic-heading">
            <div>
              <span class="reportes-kicker">Diagnóstico temporal</span>
              <h2>Lectura de grupos de SharePoint</h2>
              <p>Esta información solo muestra el nombre del grupo, si SharePoint permite consultarlo, el número de miembros devueltos y si encontró tu cuenta.</p>
            </div>
          </div>
          <div class="diagnostic-table-wrap">
            <table class="diagnostic-table">
              <thead>
                <tr>
                  <th>Área</th>
                  <th>Grupo consultado</th>
                  <th>Estado</th>
                  <th>Miembros</th>
                  <th>Tu cuenta</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($groupDiagnostics as $diagnostic): ?>
                  <tr>
                    <td><?= htmlspecialchars((string) ($diagnostic['area'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($diagnostic['group'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string) ($diagnostic['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int) ($diagnostic['members'] ?? 0) ?></td>
                    <td><?= !empty($diagnostic['matched']) ? 'Encontrada' : 'No encontrada' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>
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
            <h2>No hay reportes disponibles para tus accesos</h2>
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
        <p>Fuente: Centro de Control Dirección / BI_Reportes. Los permisos pertenecen a esta herramienta y se resuelven con sus grupos de SharePoint.</p>
      </div>
      <span class="reportes-status reportes-status-ok">Conectado</span>
    </section>
  </main>
</body>
</html>
