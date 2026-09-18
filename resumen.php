<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/reportes-common.php';
require_once __DIR__ . '/includes/reportes-header.php';
portal_require_authentication();

$user = portal_user();
$emailRaw = strtolower(trim((string) ($user['email'] ?? '')));
$error = '';
$roles = [];
$reports = [];
$todo = [];

try {
    $roles = reportes_user_areas($emailRaw);
    if (count($roles) === 0) throw new RuntimeException('Tu cuenta no pertenece a un grupo con acceso a Reportes.');
    $reports = reportes_common_load_reports($roles, $emailRaw);
    $todo = reportes_common_todo($reports, $roles);
} catch (Throwable $ex) {
    $error = $ex->getMessage();
}

$canCreate = reportes_common_can_create($roles);
$counts = reportes_common_counts($reports);
$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['estatus'] ?? ''));
$area = trim((string) ($_GET['area'] ?? ''));
$priority = trim((string) ($_GET['prioridad'] ?? ''));
$createdFolio = trim((string) ($_GET['creado'] ?? ''));

$flash = $_SESSION['reportes_flash'] ?? null;
if (is_array($flash)) {
    unset($_SESSION['reportes_flash']);
}

$filtered = array_values(array_filter($reports, static function(array $r) use ($q, $status, $area, $priority): bool {
    if ($status !== '' && strcasecmp((string) $r['status'], $status) !== 0) return false;
    if ($area !== '' && strcasecmp((string) $r['area'], $area) !== 0) return false;
    if ($priority !== '' && strcasecmp((string) $r['priority'], $priority) !== 0) return false;
    if ($q !== '') {
        $haystack = implode(' ', [
            (string) $r['folio'],
            (string) $r['description'],
            (string) $r['requester'],
            (string) $r['client'],
            (string) $r['contract'],
            (string) $r['location'],
        ]);
        if (stripos($haystack, $q) === false) return false;
    }
    return true;
}));

function resumen_render_rows(array $items, string $emptyMessage): void
{
    if (count($items) === 0) {
        echo '<div class="report-list-empty">' . htmlspecialchars($emptyMessage, ENT_QUOTES, 'UTF-8') . '</div>';
        return;
    }

    echo '<div class="report-table" role="table">';
    echo '<div class="report-table-head" role="row">';
    echo '<span>Reporte</span><span>Área</span><span>Prioridad</span><span>Solicitante</span><span>Estatus</span><span></span>';
    echo '</div>';

    foreach ($items as $r) {
        $folio = htmlspecialchars((string) $r['folio'], ENT_QUOTES, 'UTF-8');
        $area = htmlspecialchars((string) $r['area'], ENT_QUOTES, 'UTF-8');
        $priority = htmlspecialchars((string) $r['priority'], ENT_QUOTES, 'UTF-8');
        $requester = htmlspecialchars((string) ($r['requester'] !== '' ? $r['requester'] : '—'), ENT_QUOTES, 'UTF-8');
        $statusRaw = trim((string) $r['status']);
        $status = htmlspecialchars($statusRaw, ENT_QUOTES, 'UTF-8');
        $statusClass = match (mb_strtolower($statusRaw, 'UTF-8')) {
            'pendiente' => 'status-pendiente',
            'en proceso' => 'status-proceso',
            'solucionado' => 'status-solucionado',
            'cerrado' => 'status-cerrado',
            default => 'status-cerrado',
        };
        $description = trim((string) $r['description']);
        $descriptionShort = mb_strlen($description) > 115 ? mb_substr($description, 0, 112) . '…' : $description;
        $descriptionHtml = htmlspecialchars($descriptionShort !== '' ? $descriptionShort : 'Sin descripción', ENT_QUOTES, 'UTF-8');
        $extra = [];

        if ((string) $r['client'] !== '') $extra[] = 'Cliente: ' . (string) $r['client'];
        if ((string) $r['contract'] !== '') $extra[] = 'Contrato: ' . (string) $r['contract'];
        if ((string) $r['location'] !== '') $extra[] = 'Ubicación: ' . (string) $r['location'];

        echo '<article class="report-table-row" role="row">';
        echo '<div class="report-cell report-main-cell" role="cell">';
        echo '<a class="report-folio-link" href="/reportes/gestionar.php?id=' . (int) $r['id'] . '">' . $folio . '</a>';
        echo '<span class="report-row-description">' . $descriptionHtml . '</span>';
        if ($extra) {
            echo '<span class="report-row-extra">' . htmlspecialchars(implode(' · ', $extra), ENT_QUOTES, 'UTF-8') . '</span>';
        }
        echo '</div>';
        echo '<div class="report-cell" role="cell"><span class="mobile-label">Área</span>' . $area . '</div>';
        echo '<div class="report-cell" role="cell"><span class="mobile-label">Prioridad</span>' . $priority . '</div>';
        echo '<div class="report-cell" role="cell"><span class="mobile-label">Solicitante</span>' . $requester . '</div>';
        echo '<div class="report-cell" role="cell"><span class="mobile-label">Estatus</span><span class="report-row-status ' . $statusClass . '">' . $status . '</span></div>';
        echo '<div class="report-cell report-row-action" role="cell"><a href="/reportes/gestionar.php?id=' . (int) $r['id'] . '">Ver / gestionar</a></div>';
        echo '</article>';
    }

    echo '</div>';
}
?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#ffffff">
  <title>Resumen | Reportes</title>
  <link rel="stylesheet" href="/reportes/styles.css?v=20260918-summary-1">
  <link rel="stylesheet" href="/reportes/styles-sections.css?v=20260918-summary-1">
</head>
<body>
<?php reportes_render_header($user); ?>

<main class="shell reportes-main">
  <section class="report-menu" aria-label="Módulos de Reportes">
    <a class="report-menu-card is-active" href="/reportes/resumen.php">
      <span class="report-menu-icon">▦</span>
      <strong>Resumen</strong>
      <small>Indicadores, filtros y seguimiento.</small>
    </a>
    <?php if ($canCreate): ?>
      <a class="report-menu-card" href="/reportes/nuevo.php">
        <span class="report-menu-icon">＋</span>
        <strong>Nuevo reporte</strong>
        <small>Registrar una nueva incidencia.</small>
      </a>
    <?php endif; ?>
  </section>

  <?php if ($createdFolio !== ''): ?>
    <section class="flash-card flash-success">
      <strong><?= htmlspecialchars($createdFolio, ENT_QUOTES, 'UTF-8') ?> fue creado correctamente.</strong>
      <?php if (is_array($flash) && !empty($flash['notification_ok'])): ?>
        <span>La notificación fue enviada al área correspondiente.</span>
      <?php elseif (is_array($flash) && !empty($flash['notification_warning'])): ?>
        <span>El reporte quedó registrado, pero hubo un problema al enviar la notificación.</span>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <section class="reportes-note reportes-note-error">
      <div><h2>No fue posible cargar el resumen</h2><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p></div>
    </section>
  <?php else: ?>

    <section class="report-toolbar">
      <div class="report-kpis">
        <?php foreach ($counts as $label => $count): ?>
          <div class="report-kpi">
            <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
            <strong><?= (int) $count ?></strong>
          </div>
        <?php endforeach; ?>
      </div>

      <form class="report-filters" method="get">
        <label class="filter-search"><span>Buscar</span><input type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Folio, descripción, cliente, contrato..."></label>
        <label><span>Estatus</span><select name="estatus"><option value="">Todos</option><?php foreach (['Pendiente','En proceso','Solucionado','Cerrado'] as $o): ?><option value="<?= $o ?>" <?= $status === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></label>
        <label><span>Área</span><select name="area"><option value="">Todas</option><option value="Parque" <?= $area === 'Parque' ? 'selected' : '' ?>>Parque</option><option value="Capillas" <?= $area === 'Capillas' ? 'selected' : '' ?>>Capillas</option></select></label>
        <label><span>Prioridad</span><select name="prioridad"><option value="">Todas</option><?php foreach (['Alta','Normal','Baja'] as $o): ?><option value="<?= $o ?>" <?= $priority === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></label>
        <div class="filter-actions"><button type="submit">Filtrar</button><a href="/reportes/resumen.php">Limpiar</a></div>
      </form>
    </section>

    <?php if (count($todo) > 0): ?>
      <section class="report-list-section todo-list-section">
        <div class="reportes-heading compact-heading">
          <div>
            <span class="reportes-kicker">Atención</span>
            <h2>Por hacer</h2>
            <p>Reportes pendientes o en proceso que tu área puede atender.</p>
          </div>
          <span class="reportes-status"><?= count($todo) ?> pendientes</span>
        </div>
        <?php resumen_render_rows($todo, 'No hay reportes pendientes para tu área.'); ?>
      </section>
    <?php endif; ?>

    <section class="report-list-section">
      <div class="reportes-heading compact-heading">
        <div>
          <span class="reportes-kicker">Resultados</span>
          <h2>Reportes filtrados</h2>
          <p>Consulta todos los reportes visibles para tu cuenta.</p>
        </div>
        <span class="reportes-status reportes-status-ok"><?= count($filtered) ?> de <?= count($reports) ?></span>
      </div>
      <?php resumen_render_rows($filtered, 'No se encontraron reportes con los filtros seleccionados.'); ?>
    </section>

  <?php endif; ?>
</main>
</body>
</html>
