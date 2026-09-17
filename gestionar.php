<?php

declare(strict_types=1);

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

function gestion_val(array $row, array $keys, string $default = ''): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) continue;
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') return $value;
    }

    $normalized = [];
    foreach ($row as $key => $value) {
        $normalized[strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $key))] = $value;
    }
    foreach ($keys as $key) {
        $needle = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
        if (isset($normalized[$needle])) {
            $value = trim((string) $normalized[$needle]);
            if ($value !== '') return $value;
        }
    }
    return $default;
}

function gestion_has_role(array $roles, string $role): bool
{
    foreach ($roles as $candidate) {
        if (strcasecmp(trim((string) $candidate), $role) === 0) return true;
    }
    return false;
}

function gestion_can_view(array $item, array $roles, string $email): bool
{
    if (gestion_has_role($roles, 'Administradores')) return true;
    $area = gestion_val($item, ['AreaAsignada', 'Area_x0020_Asignada', 'Area']);
    if (gestion_has_role($roles, 'Parque') && strcasecmp($area, 'Parque') === 0) return true;
    if (gestion_has_role($roles, 'Capillas') && strcasecmp($area, 'Capillas') === 0) return true;

    if (gestion_has_role($roles, 'Vendedores')) {
        $requester = strtolower(gestion_val($item, ['SolicitanteCorreo', 'Solicitante_x0020_Correo', 'CorreoSolicitante']));
        return $requester !== '' && $requester === strtolower(trim($email));
    }
    return false;
}

function gestion_can_edit(array $item, array $roles): bool
{
    if (gestion_has_role($roles, 'Administradores')) return true;
    $area = gestion_val($item, ['AreaAsignada', 'Area_x0020_Asignada', 'Area']);
    if (gestion_has_role($roles, 'Parque') && strcasecmp($area, 'Parque') === 0) return true;
    if (gestion_has_role($roles, 'Capillas') && strcasecmp($area, 'Capillas') === 0) return true;
    return false;
}

$itemId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$error = '';
$flash = '';
$item = [];
$roles = [];
$attachments = [];

try {
    if ($itemId <= 0) throw new RuntimeException('No se indicó un reporte válido.');
    $roles = reportes_user_areas($emailRaw);
    $item = reportes_get_item($itemId);
    if (!gestion_can_view($item, $roles, $emailRaw)) {
        throw new RuntimeException('Tu cuenta no tiene acceso a este reporte.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('La sesión del formulario expiró. Actualiza la página e inténtalo nuevamente.');
        }
        if (!gestion_can_edit($item, $roles)) {
            throw new RuntimeException('Tu cuenta puede consultar este reporte, pero no modificarlo.');
        }

        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'tomar_reporte') {
            reportes_update_item($itemId, [
                'ResponsableNombre' => $nameRaw,
                'ResponsableCorreo' => $emailRaw,
                'Estatus' => 'En proceso',
            ]);
            $flash = 'El reporte quedó asignado a tu cuenta y pasó a En proceso.';
        } elseif ($action === 'actualizar_reporte') {
            $status = trim((string) ($_POST['estatus'] ?? 'Pendiente'));
            $seguimiento = trim((string) ($_POST['seguimiento'] ?? ''));
            $solucion = trim((string) ($_POST['solucion'] ?? ''));
            $allowedStatuses = ['Pendiente', 'En proceso', 'Solucionado', 'Cerrado'];
            if (!in_array($status, $allowedStatuses, true)) {
                throw new RuntimeException('Selecciona un estatus válido.');
            }
            if (mb_strlen($seguimiento) > 4000 || mb_strlen($solucion) > 4000) {
                throw new RuntimeException('Seguimiento o solución exceden el máximo permitido.');
            }
            if (in_array($status, ['Solucionado', 'Cerrado'], true) && $solucion === '') {
                throw new RuntimeException('Para cerrar o marcar como solucionado debes registrar la solución.');
            }

            $update = [
                'Estatus' => $status,
                'Seguimiento' => $seguimiento,
                'Solucion' => $solucion,
            ];

            $responsableCorreo = gestion_val($item, ['ResponsableCorreo', 'Responsable Correo']);
            if ($responsableCorreo === '') {
                $update['ResponsableNombre'] = $nameRaw;
                $update['ResponsableCorreo'] = $emailRaw;
            }

            if (in_array($status, ['Solucionado', 'Cerrado'], true)) {
                $update['FechaCierre'] = date(DATE_ATOM);
                $update['CerradoPor'] = $nameRaw . ' <' . $emailRaw . '>';
            } else {
                $update['FechaCierre'] = null;
                $update['CerradoPor'] = '';
            }

            reportes_update_item($itemId, $update);
            $flash = 'Cambios guardados correctamente.';
        }

        $item = reportes_get_item($itemId);
    }

    if (!empty($item['Attachments'])) {
        $attachments = reportes_item_attachments($itemId);
    }
} catch (Throwable $ex) {
    error_log('Reportes gestionar: ' . $ex->getMessage());
    $error = $ex->getMessage();
}

$canEdit = $item !== [] && gestion_can_edit($item, $roles);
$folio = $item !== [] ? gestion_val($item, ['Folio'], '#' . $itemId) : '#' . $itemId;
$area = $item !== [] ? gestion_val($item, ['AreaAsignada', 'Area_x0020_Asignada', 'Area'], 'Sin área') : '';
$status = $item !== [] ? gestion_val($item, ['Estatus'], 'Pendiente') : '';
$priority = $item !== [] ? gestion_val($item, ['Prioridad'], 'Normal') : '';
$requester = $item !== [] ? gestion_val($item, ['SolicitanteNombre', 'Solicitante Nombre']) : '';
$requesterEmail = $item !== [] ? gestion_val($item, ['SolicitanteCorreo', 'Solicitante Correo']) : '';
$client = $item !== [] ? gestion_val($item, ['ClienteNombre', 'Cliente Nombre']) : '';
$contract = $item !== [] ? gestion_val($item, ['Contrato']) : '';
$location = $item !== [] ? gestion_val($item, ['Ubicacion', 'Ubicación']) : '';
$description = $item !== [] ? gestion_val($item, ['Descripcion', 'Descripción']) : '';
$responsible = $item !== [] ? gestion_val($item, ['ResponsableNombre', 'Responsable Nombre']) : '';
$responsibleEmail = $item !== [] ? gestion_val($item, ['ResponsableCorreo', 'Responsable Correo']) : '';
$tracking = $item !== [] ? gestion_val($item, ['Seguimiento']) : '';
$solution = $item !== [] ? gestion_val($item, ['Solucion', 'Solución']) : '';
$closedAt = $item !== [] ? gestion_val($item, ['FechaCierre', 'Fecha Cierre']) : '';
$closedBy = $item !== [] ? gestion_val($item, ['CerradoPor', 'Cerrado Por']) : '';
?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#ffffff">
  <title><?= htmlspecialchars($folio, ENT_QUOTES, 'UTF-8') ?> | Reportes</title>
  <link rel="stylesheet" href="/reportes-preview/styles.css?v=20260917-manage-1">
</head>
<body>
  <header class="reportes-header">
    <div class="shell reportes-header-inner">
      <div class="reportes-brand">
        <div class="reportes-logo" aria-hidden="true"><svg viewBox="0 0 64 64"><g fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M32 6v12M32 46v12M6 32h12M46 32h12M13.6 13.6l8.5 8.5M41.9 41.9l8.5 8.5M50.4 13.6l-8.5 8.5M22.1 41.9l-8.5 8.5"/></g><circle cx="32" cy="32" r="9" fill="currentColor"/></svg></div>
        <div class="reportes-identity"><strong>Reportes</strong><span>Seguimiento</span></div>
      </div>
      <div class="reportes-header-context"><?= htmlspecialchars($folio, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="reportes-header-actions"><a class="header-action" href="/reportes-preview/">Volver a Reportes</a></div>
    </div>
  </header>

  <main class="shell reportes-main">
    <?php if ($error !== ''): ?>
      <section class="reportes-note reportes-note-error"><div><span class="reportes-kicker">Error</span><h2>No fue posible abrir el reporte</h2><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p></div></section>
    <?php else: ?>
      <?php if ($flash !== ''): ?><section class="flash-card flash-success"><strong><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></strong></section><?php endif; ?>

      <section class="reportes-hero">
        <span class="reportes-kicker"><?= htmlspecialchars($area, ENT_QUOTES, 'UTF-8') ?></span>
        <h1><?= htmlspecialchars($folio, ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8')) ?></p>
        <div class="reportes-meta">
          <div><span>Estatus</span><strong><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></strong></div>
          <div><span>Prioridad</span><strong><?= htmlspecialchars($priority, ENT_QUOTES, 'UTF-8') ?></strong></div>
          <div><span>Solicitante</span><strong><?= htmlspecialchars($requester !== '' ? $requester : $requesterEmail, ENT_QUOTES, 'UTF-8') ?></strong></div>
        </div>
      </section>

      <section class="create-card">
        <div class="create-heading"><div><span class="reportes-kicker">Datos del reporte</span><h2>Información</h2></div></div>
        <div class="reportes-meta">
          <div><span>Cliente</span><strong><?= htmlspecialchars($client !== '' ? $client : '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
          <div><span>Contrato</span><strong><?= htmlspecialchars($contract !== '' ? $contract : '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
          <div><span>Ubicación</span><strong><?= htmlspecialchars($location !== '' ? $location : '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
        </div>
        <?php if (count($attachments) > 0): ?><div class="report-actions"><?php foreach ($attachments as $attachment): ?><a href="<?= htmlspecialchars((string) $attachment['url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string) $attachment['name'], ENT_QUOTES, 'UTF-8') ?></a><?php endforeach; ?></div><?php endif; ?>
      </section>

      <?php if ($canEdit): ?>
        <section class="create-card">
          <div class="create-heading"><div><span class="reportes-kicker">Atención</span><h2>Gestionar reporte</h2><p>Actualiza el avance hasta resolver y cerrar el reporte.</p></div></div>

          <?php if ($responsibleEmail === ''): ?>
            <form method="post" class="form-actions" style="margin-top:18px">
              <input type="hidden" name="id" value="<?= $itemId ?>">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="action" value="tomar_reporte">
              <button type="submit">Asignarme este reporte</button>
            </form>
          <?php else: ?>
            <div class="report-summary"><span>Responsable: <?= htmlspecialchars($responsible !== '' ? $responsible : $responsibleEmail, ENT_QUOTES, 'UTF-8') ?></span></div>
          <?php endif; ?>

          <form class="report-form" method="post">
            <input type="hidden" name="id" value="<?= $itemId ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="actualizar_reporte">
            <label><span>Estatus</span><select name="estatus"><?php foreach (['Pendiente','En proceso','Solucionado','Cerrado'] as $option): ?><option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?></select></label>
            <label class="form-wide"><span>Seguimiento</span><textarea name="seguimiento" rows="5" maxlength="4000" placeholder="Anota avances, visitas, pendientes o acciones realizadas."><?= htmlspecialchars($tracking, ENT_QUOTES, 'UTF-8') ?></textarea></label>
            <label class="form-wide"><span>Solución</span><textarea name="solucion" rows="5" maxlength="4000" placeholder="Describe la solución aplicada. Es obligatoria para Solucionado o Cerrado."><?= htmlspecialchars($solution, ENT_QUOTES, 'UTF-8') ?></textarea></label>
            <div class="form-actions form-wide"><button type="submit">Guardar cambios</button></div>
          </form>
        </section>
      <?php else: ?>
        <section class="create-card">
          <div class="create-heading"><div><span class="reportes-kicker">Seguimiento</span><h2>Estado del reporte</h2><p>Tu cuenta tiene acceso de consulta. El seguimiento operativo corresponde al área asignada.</p></div></div>
          <div class="reportes-meta">
            <div><span>Responsable</span><strong><?= htmlspecialchars($responsible !== '' ? $responsible : 'Sin asignar', ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div><span>Fecha de cierre</span><strong><?= htmlspecialchars($closedAt !== '' ? $closedAt : '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div><span>Cerrado por</span><strong><?= htmlspecialchars($closedBy !== '' ? $closedBy : '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
          </div>
          <?php if ($tracking !== ''): ?><p class="report-description"><strong>Seguimiento:</strong><br><?= nl2br(htmlspecialchars($tracking, ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
          <?php if ($solution !== ''): ?><p class="report-description"><strong>Solución:</strong><br><?= nl2br(htmlspecialchars($solution, ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
        </section>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</body>
</html>
