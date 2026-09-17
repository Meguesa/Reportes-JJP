<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/reportes-common.php';
portal_require_authentication();

$user = portal_user();
$emailRaw = strtolower(trim((string) ($user['email'] ?? '')));
$error = '';
$roles = [];
$reports = [];

try {
    $roles = reportes_user_areas($emailRaw);
    if (count($roles) === 0) throw new RuntimeException('Tu cuenta no pertenece a un grupo con acceso a Reportes.');
    $reports = reportes_common_load_reports($roles, $emailRaw);
} catch (Throwable $ex) {
    $error = $ex->getMessage();
}

$counts = reportes_common_counts($reports);
$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['estatus'] ?? ''));
$area = trim((string) ($_GET['area'] ?? ''));
$priority = trim((string) ($_GET['prioridad'] ?? ''));

$filtered = array_values(array_filter($reports, static function(array $r) use ($q,$status,$area,$priority): bool {
    if ($status !== '' && strcasecmp((string) $r['status'], $status) !== 0) return false;
    if ($area !== '' && strcasecmp((string) $r['area'], $area) !== 0) return false;
    if ($priority !== '' && strcasecmp((string) $r['priority'], $priority) !== 0) return false;
    if ($q !== '') {
        $h = implode(' ', [(string)$r['folio'],(string)$r['description'],(string)$r['requester'],(string)$r['client'],(string)$r['contract'],(string)$r['location']]);
        if (stripos($h,$q) === false) return false;
    }
    return true;
}));
?>
<!doctype html><html lang="es-MX"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Resumen | Reportes</title><link rel="stylesheet" href="/reportes-preview/styles.css?v=20260917-pages-1"><link rel="stylesheet" href="/reportes-preview/styles-sections.css?v=20260917-pages-1"></head><body>
<header class="reportes-header"><div class="shell reportes-header-inner"><div class="reportes-brand"><div class="reportes-identity"><strong>Reportes</strong><span>Resumen</span></div></div><div class="reportes-header-context">Indicadores y filtros</div><div class="reportes-header-actions"><a class="header-action" href="/reportes-preview/">Volver a Reportes</a></div></div></header>
<main class="shell reportes-main">
<section class="report-menu"><a class="report-menu-card" href="/reportes-preview/nuevo.php"><strong>Nuevo reporte</strong><small>Registrar una incidencia.</small></a><a class="report-menu-card is-active" href="/reportes-preview/resumen.php"><strong>Resumen</strong><small>Indicadores y filtros.</small></a><a class="report-menu-card" href="/reportes-preview/"><strong>Reportes</strong><small>Bandeja principal.</small></a></section>
<?php if ($error !== ''): ?><section class="reportes-note reportes-note-error"><div><h2>No fue posible cargar el resumen</h2><p><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></p></div></section><?php else: ?>
<section class="report-toolbar"><div class="report-kpis"><?php foreach($counts as $label=>$count): ?><div class="report-kpi"><span><?= htmlspecialchars($label,ENT_QUOTES,'UTF-8') ?></span><strong><?= (int)$count ?></strong></div><?php endforeach; ?></div>
<form class="report-filters" method="get"><label class="filter-search"><span>Buscar</span><input type="search" name="q" value="<?= htmlspecialchars($q,ENT_QUOTES,'UTF-8') ?>"></label><label><span>Estatus</span><select name="estatus"><option value="">Todos</option><?php foreach(['Pendiente','En proceso','Solucionado','Cerrado'] as $o): ?><option value="<?= $o ?>" <?= $status===$o?'selected':'' ?>><?= $o ?></option><?php endforeach; ?></select></label><label><span>Área</span><select name="area"><option value="">Todas</option><option value="Parque" <?= $area==='Parque'?'selected':'' ?>>Parque</option><option value="Capillas" <?= $area==='Capillas'?'selected':'' ?>>Capillas</option></select></label><label><span>Prioridad</span><select name="prioridad"><option value="">Todas</option><?php foreach(['Alta','Normal','Baja'] as $o): ?><option value="<?= $o ?>" <?= $priority===$o?'selected':'' ?>><?= $o ?></option><?php endforeach; ?></select></label><div class="filter-actions"><button type="submit">Filtrar</button><a href="/reportes-preview/resumen.php">Limpiar</a></div></form></section>
<div class="reportes-heading"><div><span class="reportes-kicker">Resultados</span><h2>Reportes filtrados</h2></div><span class="reportes-status reportes-status-ok"><?= count($filtered) ?> de <?= count($reports) ?></span></div>
<div class="report-list"><?php foreach($filtered as $r): ?><article class="report-item"><div class="report-item-top"><div><span class="report-area"><?= htmlspecialchars((string)$r['area'],ENT_QUOTES,'UTF-8') ?></span><h3><?= htmlspecialchars((string)$r['folio'],ENT_QUOTES,'UTF-8') ?></h3></div><span class="report-type"><?= htmlspecialchars((string)$r['status'],ENT_QUOTES,'UTF-8') ?></span></div><div class="report-summary"><span>Prioridad: <?= htmlspecialchars((string)$r['priority'],ENT_QUOTES,'UTF-8') ?></span><?php if((string)$r['requester']!==''): ?><span>Solicitante: <?= htmlspecialchars((string)$r['requester'],ENT_QUOTES,'UTF-8') ?></span><?php endif; ?></div><p class="report-description"><?= nl2br(htmlspecialchars((string)$r['description'],ENT_QUOTES,'UTF-8')) ?></p><div class="report-actions"><a href="/reportes-preview/gestionar.php?id=<?= (int)$r['id'] ?>">Ver / gestionar</a></div></article><?php endforeach; ?></div>
<?php endif; ?></main></body></html>
