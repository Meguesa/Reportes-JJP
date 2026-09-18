<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
portal_require_authentication();

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '/reportes/resumen.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $target, true, 302);
exit;
