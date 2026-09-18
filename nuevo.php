<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/reportes-common.php';
require_once __DIR__ . '/includes/reportes-write.php';
require_once __DIR__ . '/includes/reportes-notificaciones.php';
require_once __DIR__ . '/includes/reportes-header.php';
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

$error = '';
$roles = [];
try {
    $roles = reportes_user_areas($emailRaw);
    if (!reportes_common_can_create($roles)) throw new RuntimeException('Tu cuenta no tiene permiso para crear reportes.');
} catch (Throwable $ex) {
    $error = $ex->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    try {
        if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('La sesión del formulario expiró.');
        $tipo = trim((string) ($_POST['tipo_reporte'] ?? ''));
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
        $prioridad = trim((string) ($_POST['prioridad'] ?? 'Normal'));
        $cliente = trim((string) ($_POST['cliente_nombre'] ?? ''));
        $contrato = trim((string) ($_POST['contrato'] ?? ''));
        $ubicacion = trim((string) ($_POST['ubicacion'] ?? ''));

        if (!in_array($tipo, ['Parque', 'Capillas'], true)) throw new RuntimeException('Selecciona un tipo de reporte válido.');
        if ($descripcion === '') throw new RuntimeException('Describe el reporte antes de enviarlo.');
        if (!in_array($prioridad, ['Baja', 'Normal', 'Alta'], true)) $prioridad = 'Normal';
        if ($tipo === 'Capillas') { $cliente = ''; $contrato = ''; $ubicacion = ''; }

        $created = reportes_create_item([
            'Title' => 'Reporte ' . $tipo . ' - ' . $nameRaw . ' - ' . date('Y-m-d H:i'),
            'TipoReporte' => $tipo,
            'Origen' => 'Portal',
            'AreaAsignada' => $tipo,
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

        $attachmentWarnings = [];
        if (isset($_FILES['adjuntos']) && is_array($_FILES['adjuntos'])) {
            $attachmentWarnings = array_merge($attachmentWarnings, reportes_upload_posted_attachments($itemId, $_FILES['adjuntos']));
        }
        if (isset($_FILES['foto_camara']) && is_array($_FILES['foto_camara'])) {
            $attachmentWarnings = array_merge($attachmentWarnings, reportes_upload_posted_attachments($itemId, $_FILES['foto_camara']));
        }

        $notificationOk = true;
        $notificationWarning = '';
        try {
            reportes_notification_new_report(
                $itemId,
                $folio,
                $tipo,
                $prioridad,
                $nameRaw,
                $emailRaw,
                $descripcion,
                $cliente,
                $contrato,
                $ubicacion
            );
        } catch (Throwable $notificationError) {
            $notificationOk = false;
            $notificationWarning = $notificationError->getMessage();
            error_log('Reportes notificación ' . $folio . ': ' . $notificationWarning);
        }

        $_SESSION['reportes_flash'] = [
            'folio' => $folio,
            'notification_ok' => $notificationOk,
            'notification_warning' => $notificationWarning,
            'attachment_warnings' => $attachmentWarnings,
        ];

        header('Location: /reportes-preview/resumen.php?creado=' . rawurlencode($folio));
        exit;
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}
?>
<!doctype html>
<html lang="es-MX">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Nuevo reporte</title><link rel="stylesheet" href="/reportes-preview/styles.css?v=20260917-mail-1"><link rel="stylesheet" href="/reportes-preview/styles-sections.css?v=20260917-mail-1"></head>
<body>
<?php reportes_render_header($user); ?>
<main class="shell reportes-main">
  <section class="report-menu"><a class="report-menu-card" href="/reportes-preview/resumen.php"><strong>Resumen</strong><small>Indicadores, filtros y seguimiento.</small></a><a class="report-menu-card is-active" href="/reportes-preview/nuevo.php"><strong>Nuevo reporte</strong><small>Registrar una incidencia.</small></a></section>
  <section class="create-card"><div class="create-heading"><span class="reportes-kicker">Captura</span><h2>Nuevo reporte</h2></div>
  <?php if ($error !== ''): ?><div class="form-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($error === '' || $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
  <form class="report-form" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <label><span>Tipo de reporte *</span><select name="tipo_reporte" id="tipo-reporte" required><option value="">Selecciona...</option><option value="Parque">Parque</option><option value="Capillas">Capillas</option></select></label>
    <label><span>Prioridad</span><select name="prioridad"><option>Normal</option><option>Alta</option><option>Baja</option></select></label>
    <label class="parque-only"><span>Cliente</span><input type="text" name="cliente_nombre"></label><label class="parque-only"><span>Contrato</span><input type="text" name="contrato"></label><label class="form-wide parque-only"><span>Ubicación</span><input type="text" name="ubicacion"></label>
    <label class="form-wide"><span>Descripción del reporte *</span><textarea name="descripcion" rows="6" required></textarea></label>
    <div class="attachment-grid form-wide"><label class="attachment-option"><span>Seleccionar archivos</span><input type="file" name="adjuntos[]" multiple accept=".jpg,.jpeg,.png,.webp,.heic,.pdf,.doc,.docx,.xls,.xlsx"></label><label class="attachment-option"><span>Tomar foto</span><input type="file" name="foto_camara" accept="image/*" capture="environment"></label></div>
    <div class="form-actions form-wide"><button type="submit">Enviar reporte</button></div>
  </form><?php endif; ?></section>
</main>
<script>(function(){const s=document.getElementById('tipo-reporte');if(!s)return;function u(){const h=s.value==='Capillas';document.querySelectorAll('.parque-only').forEach(e=>{e.hidden=h;e.querySelectorAll('input').forEach(i=>{i.disabled=h;if(h)i.value='';});});}s.addEventListener('change',u);u();})();</script>
</body></html>
