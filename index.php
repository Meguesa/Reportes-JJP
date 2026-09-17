<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/reportes-common.php';
portal_require_authentication();

$user = portal_user();
$nameRaw = trim((string) ($user['name'] ?? 'Usuario'));
$emailRaw = strtolower(trim((string) ($user['email'] ?? '')));
$name = htmlspecialchars($nameRaw, ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars($emailRaw, ENT_QUOTES, 'UTF-8');

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
    error_log('Reportes inicio: ' . $ex->getMessage());
    $error = $ex->getMessage();
}

$canCreate = reportes_common_can_create($roles);
?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#ffffff">
  <title>Reportes</title>
  <link rel="stylesheet" href="/reportes-preview/styles.css?v=20260917-pages-1">
  <link rel="stylesheet" href="/reportes-preview/styles-sections.css?v=20260917-pages-1">
</head>
<body>
<header class="reportes-header">
  <div class="shell reportes-header-inner">
    <div class="reportes-brand">
      <div class="reportes-logo" aria-hidden="true"><svg viewBox="0 0 64 64"><g fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M32 6v12M32 46v12M6 32h12M46 32h12M13.6 13.6l8.5 8.5M41.9 41.9l8.5 8.5M50.4 13.6l-8.5 8.5M22.1 41.9l-8.5 8.5"/></g><circle cx="32" cy="32" r="9" fill="currentColor"/></svg></div>
      <div class="reportes-identity"><strong>Reportes</strong><span>Bandeja principal</span></div>
    </div>
    <div class="reportes-header-context">Consulta y seguimiento</div>
    <div class="reportes-header-actions"><a class="header-action" href="/">Volver al Portal</a></div>
  </div>
</header>

<main class="shell reportes-main">
  <section class="report-menu" aria-label="Módulos de Reportes">
    <?php if ($canCreate): ?>
      <a class="report-menu-card" href="/reportes-preview/nuevo.php"><span class="report-menu-icon">＋</span><strong>Nuevo reporte</strong><small>Registrar una nueva incidencia.</small></a>
    <?php endif; ?>
    <a class="report-menu-card" href="/reportes-preview/resumen.php"><span class="report-menu-icon">▦</span><strong>Resumen</strong><small>Indicadores, búsqueda y filtros.</small></a>
    <a class="report-menu-card is-active" href="/reportes-preview/"><span class="report-menu-icon">☷</span><strong>Reportes</strong><small>Consultar y gestionar reportes.</small></a>
  </section>

  <?php if ($error !== ''): ?>
    <section class="reportes-note reportes-note-error"><div><span class="reportes-kicker">Estado</span><h2>Acceso no disponible</h2><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p></div></section>
  <?php else: ?>
    <?php if (count($todo) > 0): ?>
      <section class="todo-section">
        <div class="reportes-heading"><div><span class="reportes-kicker">Atención</span><h2>Por hacer</h2><p>Reportes pendientes o en proceso que tu área puede atender.</p></div><span class="reportes-status"><?= count($todo) ?> pendientes</span></div>
        <div class="report-list">
          <?php foreach ($todo as $report): ?>
            <article class="report-item todo-item">
              <div class="report-item-top"><div><span class="report-area"><?= htmlspecialchars((string) $report['area'], ENT_QUOTES, 'UTF-8') ?></span><h3><?= htmlspecialchars((string) $report['folio'], ENT_QUOTES, 'UTF-8') ?></h3></div><span class="report-type"><?= htmlspecialchars((string) $report['status'], ENT_QUOTES, 'UTF-8') ?></span></div>
              <div class="report-summary"><span>Prioridad: <?= htmlspecialchars((string) $report['priority'], ENT_QUOTES, 'UTF-8') ?></span><?php if ((string) $report['requester'] !== ''): ?><span>Solicitante: <?= htmlspecialchars((string) $report['requester'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></div>
              <p class="report-description"><?= nl2br(htmlspecialchars((string) $report['description'], ENT_QUOTES, 'UTF-8')) ?></p>
              <div class="report-actions"><a href="/reportes-preview/gestionar.php?id=<?= (int) $report['id'] ?>">Atender reporte</a></div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <section>
      <div class="reportes-heading"><div><span class="reportes-kicker">Bandeja</span><h2>Lista de reportes</h2><p>Reportes visibles de acuerdo con tus permisos.</p></div><span class="reportes-status reportes-status-ok"><?= count($reports) ?> reportes</span></div>
      <?php if (count($reports) === 0): ?>
        <section class="reportes-note"><div><h2>No hay reportes disponibles</h2><p>Cuando se registre un reporte visible para tu cuenta aparecerá aquí.</p></div></section>
      <?php else: ?>
        <div class="report-list">
          <?php foreach ($reports as $report): ?>
            <article class="report-item">
              <div class="report-item-top"><div><span class="report-area"><?= htmlspecialchars((string) $report['area'], ENT_QUOTES, 'UTF-8') ?></span><h3><?= htmlspecialchars((string) $report['folio'], ENT_QUOTES, 'UTF-8') ?></h3></div><span class="report-type"><?= htmlspecialchars((string) $report['status'], ENT_QUOTES, 'UTF-8') ?></span></div>
              <div class="report-summary"><span>Prioridad: <?= htmlspecialchars((string) $report['priority'], ENT_QUOTES, 'UTF-8') ?></span><?php if ((string) $report['requester'] !== ''): ?><span>Solicitante: <?= htmlspecialchars((string) $report['requester'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?><?php if ((string) $report['client'] !== ''): ?><span>Cliente: <?= htmlspecialchars((string) $report['client'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></div>
              <p class="report-description"><?= nl2br(htmlspecialchars((string) $report['description'], ENT_QUOTES, 'UTF-8')) ?></p>
              <div class="report-actions"><a href="/reportes-preview/gestionar.php?id=<?= (int) $report['id'] ?>">Ver / gestionar</a></div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
