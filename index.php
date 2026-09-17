<?php

declare(strict_types=1);

// Único punto de integración con el Portal: reutilizar su sesión autenticada.
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/reportes-sharepoint.php';
require_once __DIR__ . '/includes/reportes-write.php';
portal_require_authentication();

$user = portal_user();
$nameRaw = trim((string) ($user['name'] ?? 'Usuario'));
$emailRaw = strtolower(trim((string) ($user['email'] ?? '')));
$name = htmlspecialchars($nameRaw, ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars($emailRaw, ENT_QUOTES, 'UTF-8');

if (!isset($_SESSION['reportes_csrf']) || !is_string($_SESSION['reportes_csrf'])) {
    $_SESSION['reportes_csrf'] = bin2hex(random_bytes(24));
}
$csrfToken = (string) $_SESSION['reportes_csrf'];

$reportRoles = [];
$reportError = '';
$formError = '';
$formWarnings = [];
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
        $normalized[strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $key))] = $value;
    }
    foreach ($candidateKeys as $key) {
        $needle = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
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
} catch (Throwable $error) {
    error_log('Reportes permisos: ' . $error->getMessage());
    $reportError = 'No fue posible validar tus permisos de Reportes: ' . $error->getMessage();
}

$canCreate = reportes_role_enabled($reportRoles, 'Vendedores') || reportes_role_enabled($reportRoles, 'Administradores');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'crear_reporte') {
    try {
        if (!$canCreate) throw new RuntimeException('Tu cuenta no tiene permiso para crear reportes.');
        if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('La sesión del formulario expiró. Actualiza la página e inténtalo nuevamente.');
        }

        $tipo = trim((string) ($_POST['tipo_reporte'] ?? ''));
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
        $cliente = trim((string) ($_POST['cliente_nombre'] ?? ''));
        $contrato = trim((string) ($_POST['contrato'] ?? ''));
        $ubicacion = trim((string) ($_POST['ubicacion'] ?? ''));
        $prioridad = trim((string) ($_POST['prioridad'] ?? 'Normal'));

        if (!in_array($tipo, ['Parque', 'Capillas'], true)) {
            throw new RuntimeException('Selecciona un tipo de reporte válido.');
        }
        if ($descripcion === '') throw new RuntimeException('Describe el reporte antes de enviarlo.');
        if (mb_strlen($descripcion) > 4000) throw new RuntimeException('La descripción es demasiado larga.');
        if (!in_array($prioridad, ['Baja', 'Normal', 'Alta'], true)) $prioridad = 'Normal';

        if ($tipo === 'Capillas') {
            $cliente = '';
            $contrato = '';
            $ubicacion = '';
        }

        $area = $tipo;
        $title = 'Reporte ' . $tipo . ' - ' . $nameRaw . ' - ' . date('Y-m-d H:i');

        $created = reportes_create_item([
            'Title' => $title,
            'TipoReporte' => $tipo,
            'Origen' => 'Portal',
            'AreaAsignada' => $area,
            'Ubicacion' => $ubicacion,
            'Prioridad' => $prioridad,
            'Estatus' => 'Pendiente',
            'SolicitanteNombre' => $nameRaw,
            'SolicitanteCorreo' => $emailRaw,
            'SolicitanteArea' => 'Ventas',
            'ClienteNombre' => $cliente,
            'Contrato' => $contrato,
            'Descripcion' => $descripcion,
        ]);

        $itemId = (int) ($created['Id'] ?? 0);
        $folio = reportes_generate_folio($itemId);
        reportes_update_item($itemId, ['Folio' => $folio]);

        if (isset($_FILES['adjuntos']) && is_array($_FILES['adjuntos'])) {
            $formWarnings = array_merge($formWarnings, reportes_upload_posted_attachments($itemId, $_FILES['adjuntos']));
        }
        if (isset($_FILES['foto_camara']) && is_array($_FILES['foto_camara'])) {
            $formWarnings = array_merge($formWarnings, reportes_upload_posted_attachments($itemId, $_FILES['foto_camara']));
        }

        $_SESSION['reportes_flash'] = [
            'type' => count($formWarnings) > 0 ? 'warning' : 'success',
            'message' => 'Reporte ' . $folio . ' creado correctamente.',
            'warnings' => $formWarnings,
        ];
        header('Location: /reportes-preview/?creado=' . rawurlencode($folio) . '#reportes');
        exit;
    } catch (Throwable $error) {
        error_log('Reportes crear: ' . $error->getMessage());
        $formError = $error->getMessage();
    }
}

$flash = $_SESSION['reportes_flash'] ?? null;
unset($_SESSION['reportes_flash']);

if ($reportError === '') {
    if (count($reportRoles) === 0) {
        $reportError = 'Tu cuenta no pertenece a un grupo con acceso a Reportes.';
        try {
            $groupDiagnostics = reportes_group_diagnostics($emailRaw);
        } catch (Throwable $diagnosticError) {
            error_log('Reportes diagnóstico grupos: ' . $diagnosticError->getMessage());
        }
    } else {
        try {
            foreach (reportes_list_items(300) as $row) {
                if (!reportes_row_visible($row, $reportRoles, $emailRaw)) continue;

                $itemId = (int) ($row['Id'] ?? $row['ID'] ?? 0);
                $area = reportes_value($row, ['AreaAsignada', 'Area_x0020_Asignada', 'Area'], 'Sin área');
                $folio = reportes_value($row, ['Folio'], $itemId > 0 ? '#' . $itemId : 'Sin folio');
                $type = reportes_value($row, ['TipoReporte', 'Tipo_x0020_Reporte', 'Tipo'], 'Reporte');
                $status = reportes_value($row, ['Estatus'], 'Pendiente');
                $priority = reportes_value($row, ['Prioridad'], 'Normal');
                $description = reportes_value($row, ['Descripcion', 'Descripción', 'Description', 'Comentarios'], '');
                $requester = reportes_value($row, ['SolicitanteNombre', 'Solicitante Nombre'], '');
                $client = reportes_value($row, ['ClienteNombre', 'Cliente Nombre'], '');
                $contract = reportes_value($row, ['Contrato'], '');
                $location = reportes_value($row, ['Ubicacion', 'Ubicación'], '');
                $createdDate = reportes_value($row, ['Created'], '');
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
                    'folio' => $folio,
                    'type' => $type,
                    'area' => $area,
                    'status' => $status,
                    'priority' => $priority,
                    'description' => $description,
                    'requester' => $requester,
                    'client' => $client,
                    'contract' => $contract,
                    'location' => $location,
                    'created' => $createdDate,
                    'attachments' => $attachments,
                ];
            }

            usort($reports, static function (array $a, array $b): int {
                return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
            });
        } catch (Throwable $error) {
            error_log('Reportes listado: ' . $error->getMessage());
            $reportError = 'No fue posible consultar la lista de Reportes: ' . $error->getMessage();
        }
    }
}

$visibleRoles = reportes_role_enabled($reportRoles, 'Administradores')
    ? ['Vendedores', 'Parque', 'Capillas', 'Administradores']
    : $reportRoles;

$filterSearch = trim((string) ($_GET['q'] ?? ''));
$filterStatus = trim((string) ($_GET['estatus'] ?? ''));
$filterArea = trim((string) ($_GET['area'] ?? ''));
$filterPriority = trim((string) ($_GET['prioridad'] ?? ''));
$allowedStatusFilters = ['', 'Pendiente', 'En proceso', 'Solucionado', 'Cerrado'];
$allowedAreaFilters = ['', 'Parque', 'Capillas'];
$allowedPriorityFilters = ['', 'Baja', 'Normal', 'Alta'];
if (!in_array($filterStatus, $allowedStatusFilters, true)) $filterStatus = '';
if (!in_array($filterArea, $allowedAreaFilters, true)) $filterArea = '';
if (!in_array($filterPriority, $allowedPriorityFilters, true)) $filterPriority = '';

$reportCounts = [
    'Total' => count($reports),
    'Pendiente' => 0,
    'En proceso' => 0,
    'Solucionado' => 0,
    'Cerrado' => 0,
];
foreach ($reports as $report) {
    $statusKey = (string) ($report['status'] ?? '');
    if (array_key_exists($statusKey, $reportCounts)) $reportCounts[$statusKey]++;
}

$filteredReports = array_values(array_filter($reports, static function (array $report) use ($filterSearch, $filterStatus, $filterArea, $filterPriority): bool {
    if ($filterStatus !== '' && strcasecmp((string) ($report['status'] ?? ''), $filterStatus) !== 0) return false;
    if ($filterArea !== '' && strcasecmp((string) ($report['area'] ?? ''), $filterArea) !== 0) return false;
    if ($filterPriority !== '' && strcasecmp((string) ($report['priority'] ?? ''), $filterPriority) !== 0) return false;

    if ($filterSearch !== '') {
        $haystack = implode(' ', [
            (string) ($report['folio'] ?? ''),
            (string) ($report['type'] ?? ''),
            (string) ($report['area'] ?? ''),
            (string) ($report['status'] ?? ''),
            (string) ($report['priority'] ?? ''),
            (string) ($report['description'] ?? ''),
            (string) ($report['requester'] ?? ''),
            (string) ($report['client'] ?? ''),
            (string) ($report['contract'] ?? ''),
            (string) ($report['location'] ?? ''),
        ]);
        if (stripos($haystack, $filterSearch) === false) return false;
    }

    return true;
}));

$hasFilters = $filterSearch !== '' || $filterStatus !== '' || $filterArea !== '' || $filterPriority !== '';
?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#ffffff">
  <title>Reportes | Vista previa</title>
  <link rel="stylesheet" href="/reportes-preview/styles.css?v=20260917-nav-2">
</head>
<body>
  <header class="reportes-header">
    <div class="shell reportes-header-inner">
      <div class="reportes-brand">
        <div class="reportes-logo" aria-hidden="true">
          <svg viewBox="0 0 64 64" role="img"><g fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M32 6v12M32 46v12M6 32h12M46 32h12M13.6 13.6l8.5 8.5M41.9 41.9l8.5 8.5M50.4 13.6l-8.5 8.5M22.1 41.9l-8.5 8.5"/></g><circle cx="32" cy="32" r="9" fill="currentColor"/></svg>
        </div>
        <div class="reportes-identity"><strong>Reportes</strong><span>Vista previa interna</span></div>
      </div>
      <div class="reportes-header-context">Herramienta en desarrollo</div>
      <div class="reportes-header-actions">
        <a class="header-action" href="/">Volver al Portal</a>
        <details class="account-menu">
          <summary class="account-trigger" aria-label="Abrir menú de usuario" title="<?= $name ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4" fill="currentColor"/><path d="M4 20c0-4.1 3.6-6 8-6s8 1.9 8 6v1H4z" fill="currentColor"/></svg></summary>
          <div class="account-menu-panel"><div class="account-menu-info"><strong><?= $name ?></strong><span><?= $email ?></span></div><a class="account-menu-logout" href="/logout.php">Cerrar sesión</a></div>
        </details>
      </div>
    </div>
  </header>

  <main class="shell reportes-main">
    <nav class="section-nav" aria-label="Secciones de Reportes">
      <span class="section-nav-label">Secciones</span>
      <?php if ($canCreate): ?><a href="#nuevo-reporte">Nuevo reporte</a><?php endif; ?>
      <?php if ($reportError === ''): ?>
        <a href="#resumen">Resumen</a>
        <a href="#reportes">Reportes</a>
      <?php endif; ?>
    </nav>

    <section class="reportes-hero">
      <span class="reportes-kicker">Vista previa</span><h1>Consulta y seguimiento de reportes</h1>
      <p>Los reportes se registran y consultan directamente en SharePoint de acuerdo con los permisos de cada usuario.</p>
      <div class="reportes-meta"><div><span>Usuario</span><strong><?= $name ?></strong></div><div><span>Cuenta</span><strong><?= $email ?></strong></div><div><span>Accesos autorizados</span><strong><?= htmlspecialchars(count($visibleRoles) > 0 ? implode(', ', $visibleRoles) : 'Sin acceso', ENT_QUOTES, 'UTF-8') ?></strong></div></div>
    </section>

    <?php if (is_array($flash)): ?>
      <section class="flash-card <?= (($flash['type'] ?? '') === 'warning') ? 'flash-warning' : 'flash-success' ?>"><strong><?= htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong><?php foreach (($flash['warnings'] ?? []) as $warning): ?><span><?= htmlspecialchars((string) $warning, ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?></section>
    <?php endif; ?>

    <?php if ($canCreate): ?>
      <section class="create-card" id="nuevo-reporte">
        <div class="create-heading"><div><span class="reportes-kicker">Captura</span><h2>Nuevo reporte</h2><p>Registra una incidencia para Parque o Capillas. Tu nombre y correo se tomarán automáticamente de la sesión.</p></div></div>
        <?php if ($formError !== ''): ?><div class="form-error"><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <form class="report-form" method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="crear_reporte"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <label><span>Tipo de reporte *</span><select name="tipo_reporte" id="tipo-reporte" required><option value="">Selecciona...</option><option value="Parque" <?= (($_POST['tipo_reporte'] ?? '') === 'Parque') ? 'selected' : '' ?>>Parque</option><option value="Capillas" <?= (($_POST['tipo_reporte'] ?? '') === 'Capillas') ? 'selected' : '' ?>>Capillas</option></select></label>
          <label><span>Prioridad</span><select name="prioridad"><option value="Normal">Normal</option><option value="Alta">Alta</option><option value="Baja">Baja</option></select></label>
          <label class="parque-only"><span>Cliente</span><input type="text" name="cliente_nombre" maxlength="180" value="<?= htmlspecialchars((string) ($_POST['cliente_nombre'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Nombre del cliente"></label>
          <label class="parque-only"><span>Contrato</span><input type="text" name="contrato" maxlength="80" value="<?= htmlspecialchars((string) ($_POST['contrato'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Número de contrato"></label>
          <label class="form-wide parque-only"><span>Ubicación</span><input type="text" name="ubicacion" maxlength="180" value="<?= htmlspecialchars((string) ($_POST['ubicacion'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Sección, lote, sala o referencia"></label>
          <div class="report-type-note form-wide" id="capillas-note" hidden>Para reportes de Capillas solo se solicita la descripción del reporte y, si aplica, evidencia fotográfica o archivos adjuntos.</div>
          <label class="form-wide"><span>Descripción del reporte *</span><textarea name="descripcion" rows="5" maxlength="4000" required placeholder="Describe claramente qué sucedió y qué necesitas que se revise."><?= htmlspecialchars((string) ($_POST['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></label>
          <div class="attachment-grid form-wide">
            <label class="attachment-option"><span>Seleccionar archivos</span><input type="file" name="adjuntos[]" multiple accept=".jpg,.jpeg,.png,.webp,.heic,.pdf,.doc,.docx,.xls,.xlsx"><small>Fotos o documentos existentes. Hasta 15 MB por archivo.</small></label>
            <label class="attachment-option"><span>Tomar foto</span><input type="file" name="foto_camara" accept="image/*" capture="environment"><small>En celular abre la cámara trasera y adjunta la foto al reporte.</small></label>
          </div>
          <div class="form-actions form-wide"><button type="submit">Enviar reporte</button></div>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($reportError !== ''): ?>
      <section class="reportes-note reportes-note-error" role="alert"><div><span class="reportes-kicker">Estado</span><h2>Acceso a Reportes no disponible</h2><p><?= htmlspecialchars($reportError, ENT_QUOTES, 'UTF-8') ?></p></div><span class="reportes-status">Revisar</span></section>
    <?php else: ?>
      <section class="report-toolbar" id="resumen">
        <div class="report-kpis" aria-label="Resumen por estatus">
          <?php foreach ($reportCounts as $label => $count): ?>
            <div class="report-kpi"><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><strong><?= (int) $count ?></strong></div>
          <?php endforeach; ?>
        </div>

        <form class="report-filters" method="get" action="/reportes-preview/#reportes">
          <label class="filter-search"><span>Buscar</span><input type="search" name="q" value="<?= htmlspecialchars($filterSearch, ENT_QUOTES, 'UTF-8') ?>" placeholder="Folio, cliente, contrato, ubicación o descripción"></label>
          <label><span>Estatus</span><select name="estatus"><option value="">Todos</option><?php foreach (['Pendiente','En proceso','Solucionado','Cerrado'] as $option): ?><option value="<?= $option ?>" <?= $filterStatus === $option ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?></select></label>
          <label><span>Área</span><select name="area"><option value="">Todas</option><option value="Parque" <?= $filterArea === 'Parque' ? 'selected' : '' ?>>Parque</option><option value="Capillas" <?= $filterArea === 'Capillas' ? 'selected' : '' ?>>Capillas</option></select></label>
          <label><span>Prioridad</span><select name="prioridad"><option value="">Todas</option><?php foreach (['Alta','Normal','Baja'] as $option): ?><option value="<?= $option ?>" <?= $filterPriority === $option ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?></select></label>
          <div class="filter-actions"><button type="submit">Filtrar</button><?php if ($hasFilters): ?><a href="/reportes-preview/#reportes">Limpiar</a><?php endif; ?></div>
        </form>
      </section>

      <section class="reportes-heading" id="reportes"><div><span class="reportes-kicker">SharePoint</span><h2>Reportes disponibles</h2></div><span class="reportes-status reportes-status-ok"><?= count($filteredReports) ?> de <?= count($reports) ?></span></section>
      <?php if (count($reports) === 0): ?>
        <section class="reportes-note"><div><span class="reportes-kicker">Sin registros</span><h2>No hay reportes disponibles para tus accesos</h2><p>La conexión con SharePoint funciona. Cuando se registre el primer reporte aparecerá aquí.</p></div><span class="reportes-status">0 reportes</span></section>
      <?php elseif (count($filteredReports) === 0): ?>
        <section class="reportes-note"><div><span class="reportes-kicker">Sin coincidencias</span><h2>No encontramos reportes con esos filtros</h2><p>Modifica la búsqueda o limpia los filtros para volver a ver la bandeja completa.</p></div><span class="reportes-status">0 resultados</span></section>
      <?php else: ?>
        <section class="report-list" aria-label="Reportes disponibles">
          <?php foreach ($filteredReports as $report): ?>
            <article class="report-item">
              <div class="report-item-top"><div><span class="report-area"><?= htmlspecialchars((string) $report['area'], ENT_QUOTES, 'UTF-8') ?></span><h3><?= htmlspecialchars((string) $report['folio'], ENT_QUOTES, 'UTF-8') ?></h3></div><span class="report-type"><?= htmlspecialchars((string) $report['status'], ENT_QUOTES, 'UTF-8') ?></span></div>
              <div class="report-summary"><span><?= htmlspecialchars((string) $report['type'], ENT_QUOTES, 'UTF-8') ?></span><span>Prioridad: <?= htmlspecialchars((string) $report['priority'], ENT_QUOTES, 'UTF-8') ?></span><?php if ((string) $report['requester'] !== ''): ?><span>Solicitante: <?= htmlspecialchars((string) $report['requester'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?><?php if ((string) $report['client'] !== ''): ?><span>Cliente: <?= htmlspecialchars((string) $report['client'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?><?php if ((string) $report['contract'] !== ''): ?><span>Contrato: <?= htmlspecialchars((string) $report['contract'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></div>
              <?php if ((string) $report['description'] !== ''): ?><p class="report-description"><?= nl2br(htmlspecialchars((string) $report['description'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
              <div class="report-actions">
                <a href="/reportes-preview/gestionar.php?id=<?= (int) $report['id'] ?>">Ver / gestionar</a>
                <?php foreach ($report['attachments'] as $attachment): ?><a href="<?= htmlspecialchars((string) $attachment['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string) $attachment['name'], ENT_QUOTES, 'UTF-8') ?></a><?php endforeach; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
    <?php endif; ?>

    <section class="reportes-note"><div><span class="reportes-kicker">Integración</span><h2>Lista SharePoint conectada</h2><p>Fuente: Centro de Control Dirección / BI_Reportes. Reportes mantiene su propia lógica de permisos y datos.</p></div><span class="reportes-status reportes-status-ok">Conectado</span></section>
  </main>

  <script>
    (function () {
      const typeSelect = document.getElementById('tipo-reporte');
      if (!typeSelect) return;
      const parqueFields = Array.from(document.querySelectorAll('.parque-only'));
      const capillasNote = document.getElementById('capillas-note');

      function updateReportTypeFields() {
        const isCapillas = typeSelect.value === 'Capillas';
        parqueFields.forEach(function (element) {
          element.hidden = isCapillas;
          element.querySelectorAll('input, select, textarea').forEach(function (control) {
            control.disabled = isCapillas;
            if (isCapillas && control instanceof HTMLInputElement) control.value = '';
          });
        });
        if (capillasNote) capillasNote.hidden = !isCapillas;
      }

      typeSelect.addEventListener('change', updateReportTypeFields);
      updateReportTypeFields();
    })();
  </script>
</body>
</html>
