<?php

declare(strict_types=1);

require_once __DIR__ . '/reportes-sharepoint.php';

function reportes_list_api_base(): string
{
    $session = reportes_sharepoint_session();
    return rtrim($session['siteUrl'], '/') . "/_api/web/lists/getbytitle('Reportes')";
}

function reportes_normalize_column_name(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';

    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') $value = $ascii;
    }

    return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $value));
}

/** @return array<string,string[]> */
function reportes_column_aliases(): array
{
    return [
        'Title' => ['Title', 'Título', 'Titulo'],
        'Folio' => ['Folio'],
        'TipoReporte' => ['TipoReporte', 'Tipo Reporte', 'Tipo_x0020_Reporte'],
        'Origen' => ['Origen'],
        'AreaAsignada' => ['AreaAsignada', 'Área Asignada', 'Area Asignada', 'Area_x0020_Asignada'],
        'Ubicacion' => ['Ubicacion', 'Ubicación'],
        'Categoria' => ['Categoria', 'Categoría'],
        'Prioridad' => ['Prioridad'],
        'Estatus' => ['Estatus'],
        'SolicitanteNombre' => ['SolicitanteNombre', 'Solicitante Nombre'],
        'SolicitanteCorreo' => ['SolicitanteCorreo', 'Solicitante Correo'],
        'SolicitanteArea' => ['SolicitanteArea', 'Solicitante Área', 'Solicitante Area'],
        'ClienteNombre' => ['ClienteNombre', 'Cliente Nombre'],
        'Contrato' => ['Contrato'],
        'Descripcion' => ['Descripcion', 'Descripción'],
        'ResponsableNombre' => ['ResponsableNombre', 'Responsable Nombre'],
        'ResponsableCorreo' => ['ResponsableCorreo', 'Responsable Correo'],
        'Seguimiento' => ['Seguimiento'],
        'Solucion' => ['Solucion', 'Solución'],
        'FechaCierre' => ['FechaCierre', 'Fecha Cierre'],
        'CerradoPor' => ['CerradoPor', 'Cerrado Por'],
    ];
}

/** @return array<int,array{title:string,internal:string,type:string,readonly:bool,hidden:bool,required:bool}> */
function reportes_list_fields(): array
{
    static $fields = null;
    if (is_array($fields)) return $fields;

    $session = reportes_sharepoint_session();
    $url = reportes_list_api_base()
        . "/fields?\$select=Title,InternalName,TypeAsString,ReadOnlyField,Hidden,Required";

    $data = reportes_remote_json($url, 'GET', [
        'Authorization: Bearer ' . $session['token'],
        'Accept: application/json;odata=nometadata',
        'Content-Type: application/json;odata=nometadata',
    ]);

    $fields = [];
    foreach (($data['value'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $internal = trim((string) ($row['InternalName'] ?? ''));
        if ($internal === '') continue;
        $fields[] = [
            'title' => trim((string) ($row['Title'] ?? $internal)),
            'internal' => $internal,
            'type' => trim((string) ($row['TypeAsString'] ?? '')),
            'readonly' => !empty($row['ReadOnlyField']),
            'hidden' => !empty($row['Hidden']),
            'required' => !empty($row['Required']),
        ];
    }

    return $fields;
}

function reportes_resolve_internal_field(string $canonical): ?string
{
    $aliases = reportes_column_aliases();
    $candidates = $aliases[$canonical] ?? [$canonical];
    $fields = reportes_list_fields();

    foreach ($fields as $field) {
        if ($field['readonly'] || $field['hidden']) continue;
        foreach ($candidates as $candidate) {
            if (strcasecmp($field['internal'], $candidate) === 0) return $field['internal'];
        }
    }

    foreach ($fields as $field) {
        if ($field['readonly'] || $field['hidden']) continue;
        $fieldTitle = reportes_normalize_column_name($field['title']);
        $fieldInternal = reportes_normalize_column_name($field['internal']);
        foreach ($candidates as $candidate) {
            $needle = reportes_normalize_column_name($candidate);
            if ($needle !== '' && ($needle === $fieldTitle || $needle === $fieldInternal)) {
                return $field['internal'];
            }
        }
    }

    return null;
}

/**
 * @param array<string,mixed> $canonicalValues
 * @param string[] $requiredCanonical
 * @return array<string,mixed>
 */
function reportes_map_fields(array $canonicalValues, array $requiredCanonical = []): array
{
    $mapped = [];
    $missing = [];

    foreach ($canonicalValues as $canonical => $value) {
        $internal = reportes_resolve_internal_field((string) $canonical);
        if ($internal === null) {
            if (in_array((string) $canonical, $requiredCanonical, true)) $missing[] = (string) $canonical;
            continue;
        }
        $mapped[$internal] = $value;
    }

    if (count($missing) > 0) {
        throw new RuntimeException('Faltan columnas requeridas en BI_Reportes: ' . implode(', ', $missing) . '.');
    }

    return $mapped;
}

/** @param array<string,mixed> $canonicalValues @return array<string,mixed> */
function reportes_create_item(array $canonicalValues): array
{
    // Los reportes de Capillas sólo requieren la descripción de la incidencia.
    // Limpiamos cualquier dato que pudiera quedar en el navegador si el usuario
    // cambió el tipo desde Parque antes de enviar el formulario.
    if (strcasecmp(trim((string) ($canonicalValues['TipoReporte'] ?? '')), 'Capillas') === 0) {
        $canonicalValues['ClienteNombre'] = '';
        $canonicalValues['Contrato'] = '';
        $canonicalValues['Ubicacion'] = '';
    }

    $session = reportes_sharepoint_session();
    $required = ['Title', 'TipoReporte', 'AreaAsignada', 'Estatus', 'SolicitanteCorreo', 'Descripcion'];
    $payload = reportes_map_fields($canonicalValues, $required);

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) throw new RuntimeException('No fue posible preparar el reporte para SharePoint.');

    $data = reportes_remote_json(reportes_list_api_base() . '/items', 'POST', [
        'Authorization: Bearer ' . $session['token'],
        'Accept: application/json;odata=nometadata',
        'Content-Type: application/json;odata=nometadata',
    ], $json);

    $id = (int) ($data['Id'] ?? $data['ID'] ?? 0);
    if ($id <= 0) {
        $title = trim((string) ($canonicalValues['Title'] ?? ''));
        if ($title !== '') $id = reportes_find_item_id_by_title($title);
    }
    if ($id <= 0) throw new RuntimeException('SharePoint creó el registro, pero no devolvió su identificador.');

    $data['Id'] = $id;
    return $data;
}

function reportes_find_item_id_by_title(string $title): int
{
    $session = reportes_sharepoint_session();
    $escaped = str_replace("'", "''", trim($title));
    if ($escaped === '') return 0;

    $filter = rawurlencode("Title eq '" . $escaped . "'");
    $url = reportes_list_api_base()
        . "/items?\$select=Id,Title&\$filter=" . $filter . "&\$orderby=Id desc&\$top=1";

    $data = reportes_remote_json($url, 'GET', [
        'Authorization: Bearer ' . $session['token'],
        'Accept: application/json;odata=nometadata',
        'Content-Type: application/json;odata=nometadata',
    ]);

    $first = $data['value'][0] ?? null;
    return is_array($first) ? (int) ($first['Id'] ?? $first['ID'] ?? 0) : 0;
}

/** @return array<string,mixed> */
function reportes_get_item(int $itemId): array
{
    if ($itemId <= 0) throw new InvalidArgumentException('ID de reporte inválido.');

    $session = reportes_sharepoint_session();
    return reportes_remote_json(reportes_list_api_base() . '/items(' . $itemId . ')', 'GET', [
        'Authorization: Bearer ' . $session['token'],
        'Accept: application/json;odata=nometadata',
        'Content-Type: application/json;odata=nometadata',
    ]);
}

/** @param array<string,mixed> $canonicalValues */
function reportes_update_item(int $itemId, array $canonicalValues): void
{
    if ($itemId <= 0) throw new InvalidArgumentException('ID de reporte inválido.');

    $session = reportes_sharepoint_session();
    $payload = reportes_map_fields($canonicalValues);
    if (count($payload) === 0) return;

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) throw new RuntimeException('No fue posible preparar la actualización del reporte.');

    reportes_remote_json(reportes_list_api_base() . '/items(' . $itemId . ')', 'POST', [
        'Authorization: Bearer ' . $session['token'],
        'Accept: application/json;odata=nometadata',
        'Content-Type: application/json;odata=nometadata',
        'IF-MATCH: *',
        'X-HTTP-Method: MERGE',
    ], $json);
}

function reportes_generate_folio(int $itemId): string
{
    if ($itemId <= 0) throw new InvalidArgumentException('ID de reporte inválido para generar folio.');
    return sprintf('REP-%s-%06d', date('Y'), $itemId);
}

function reportes_safe_attachment_name(string $name): string
{
    $name = basename(str_replace('\\', '/', trim($name)));
    $name = preg_replace('/[^\pL\pN._() -]+/u', '_', $name) ?: 'archivo';
    $name = trim($name, " .\t\n\r\0\x0B");
    return $name !== '' ? mb_substr($name, 0, 120) : 'archivo';
}

function reportes_add_attachment(int $itemId, string $tmpPath, string $originalName): void
{
    if ($itemId <= 0) throw new InvalidArgumentException('ID de reporte inválido para adjunto.');
    if (!is_file($tmpPath) || !is_readable($tmpPath)) throw new RuntimeException('El archivo adjunto temporal no está disponible.');

    $size = filesize($tmpPath);
    if ($size === false || $size <= 0) throw new RuntimeException('El archivo adjunto está vacío.');
    if ($size > 15 * 1024 * 1024) throw new RuntimeException('Cada adjunto debe pesar máximo 15 MB.');

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
    $safeName = reportes_safe_attachment_name($originalName);
    $extension = strtolower((string) pathinfo($safeName, PATHINFO_EXTENSION));
    if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Tipo de archivo no permitido: ' . $safeName . '.');
    }

    $bytes = file_get_contents($tmpPath);
    if ($bytes === false) throw new RuntimeException('No fue posible leer el archivo adjunto.');

    $session = reportes_sharepoint_session();
    $odataName = str_replace("'", "''", $safeName);
    $url = reportes_list_api_base()
        . '/items(' . $itemId . ")/AttachmentFiles/add(FileName='" . rawurlencode($odataName) . "')";

    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('No fue posible inicializar la carga del adjunto.');

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $bytes,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $session['token'],
            'Accept: application/json;odata=nometadata',
            'Content-Type: application/octet-stream',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($response === false) throw new RuntimeException('Falló la carga del adjunto: ' . $error);
    if ($status < 200 || $status >= 300) {
        $decoded = json_decode((string) $response, true);
        $detail = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';
        throw new RuntimeException('SharePoint rechazó el adjunto HTTP ' . $status . ($detail !== '' ? ': ' . $detail : '.'));
    }
}

/** @return string[] */
function reportes_upload_posted_attachments(int $itemId, array $files): array
{
    $warnings = [];
    $names = $files['name'] ?? [];
    $tmpNames = $files['tmp_name'] ?? [];
    $errors = $files['error'] ?? [];

    if (!is_array($names)) $names = [$names];
    if (!is_array($tmpNames)) $tmpNames = [$tmpNames];
    if (!is_array($errors)) $errors = [$errors];

    foreach ($names as $index => $name) {
        $name = trim((string) $name);
        if ($name === '') continue;
        $error = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) {
            $warnings[] = 'No se pudo cargar ' . $name . ' (código ' . $error . ').';
            continue;
        }

        try {
            reportes_add_attachment($itemId, (string) ($tmpNames[$index] ?? ''), $name);
        } catch (Throwable $attachmentError) {
            $warnings[] = $attachmentError->getMessage();
        }
    }

    return $warnings;
}
