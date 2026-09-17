<?php

declare(strict_types=1);

require_once __DIR__ . '/reportes-sharepoint.php';

function reportes_common_value(array $row, array $candidateKeys, string $default = ''): string
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

function reportes_common_has_role(array $roles, string $role): bool
{
    foreach ($roles as $candidate) {
        if (strcasecmp(trim((string) $candidate), trim($role)) === 0) return true;
    }
    return false;
}

function reportes_common_can_view(array $row, array $roles, string $email): bool
{
    if (reportes_common_has_role($roles, 'Administradores')) return true;

    $area = reportes_common_value($row, ['AreaAsignada', 'Area_x0020_Asignada', 'Area']);
    if (reportes_common_has_role($roles, 'Parque') && strcasecmp($area, 'Parque') === 0) return true;
    if (reportes_common_has_role($roles, 'Capillas') && strcasecmp($area, 'Capillas') === 0) return true;

    if (reportes_common_has_role($roles, 'Vendedores')) {
        $requester = strtolower(reportes_common_value($row, [
            'SolicitanteCorreo',
            'Solicitante_x0020_Correo',
            'CorreoSolicitante',
            'Correo_x0020_Solicitante',
        ]));
        return $requester !== '' && $requester === strtolower(trim($email));
    }

    return false;
}

function reportes_common_can_manage(array $report, array $roles): bool
{
    if (reportes_common_has_role($roles, 'Administradores')) return true;
    $area = trim((string) ($report['area'] ?? ''));
    if (reportes_common_has_role($roles, 'Parque') && strcasecmp($area, 'Parque') === 0) return true;
    if (reportes_common_has_role($roles, 'Capillas') && strcasecmp($area, 'Capillas') === 0) return true;
    return false;
}

function reportes_common_can_create(array $roles): bool
{
    return reportes_common_has_role($roles, 'Vendedores') || reportes_common_has_role($roles, 'Administradores');
}

/** @return array<int,array<string,mixed>> */
function reportes_common_load_reports(array $roles, string $email): array
{
    $reports = [];

    foreach (reportes_list_items(300) as $row) {
        if (!reportes_common_can_view($row, $roles, $email)) continue;

        $itemId = (int) ($row['Id'] ?? $row['ID'] ?? 0);
        $reports[] = [
            'id' => $itemId,
            'folio' => reportes_common_value($row, ['Folio'], $itemId > 0 ? '#' . $itemId : 'Sin folio'),
            'type' => reportes_common_value($row, ['TipoReporte', 'Tipo_x0020_Reporte', 'Tipo'], 'Reporte'),
            'area' => reportes_common_value($row, ['AreaAsignada', 'Area_x0020_Asignada', 'Area'], 'Sin área'),
            'status' => reportes_common_value($row, ['Estatus'], 'Pendiente'),
            'priority' => reportes_common_value($row, ['Prioridad'], 'Normal'),
            'description' => reportes_common_value($row, ['Descripcion', 'Descripción', 'Description', 'Comentarios']),
            'requester' => reportes_common_value($row, ['SolicitanteNombre', 'Solicitante Nombre']),
            'requester_email' => strtolower(reportes_common_value($row, ['SolicitanteCorreo', 'Solicitante Correo'])),
            'client' => reportes_common_value($row, ['ClienteNombre', 'Cliente Nombre']),
            'contract' => reportes_common_value($row, ['Contrato']),
            'location' => reportes_common_value($row, ['Ubicacion', 'Ubicación']),
            'responsible' => reportes_common_value($row, ['ResponsableNombre', 'Responsable Nombre']),
            'responsible_email' => strtolower(reportes_common_value($row, ['ResponsableCorreo', 'Responsable Correo'])),
            'created' => reportes_common_value($row, ['Created']),
        ];
    }

    usort($reports, static function (array $a, array $b): int {
        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    });

    return $reports;
}

/** @return array<int,array<string,mixed>> */
function reportes_common_todo(array $reports, array $roles): array
{
    return array_values(array_filter($reports, static function (array $report) use ($roles): bool {
        if (!reportes_common_can_manage($report, $roles)) return false;
        return in_array((string) ($report['status'] ?? ''), ['Pendiente', 'En proceso'], true);
    }));
}

/** @return array<string,int> */
function reportes_common_counts(array $reports): array
{
    $counts = [
        'Total' => count($reports),
        'Pendiente' => 0,
        'En proceso' => 0,
        'Solucionado' => 0,
        'Cerrado' => 0,
    ];

    foreach ($reports as $report) {
        $status = (string) ($report['status'] ?? '');
        if (array_key_exists($status, $counts)) $counts[$status]++;
    }

    return $counts;
}
