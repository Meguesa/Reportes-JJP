<?php

declare(strict_types=1);

/**
 * Cliente SharePoint propio de Reportes.
 * La herramienta es dueña de toda la lógica de grupos, lista y adjuntos.
 */

/** @return array{tenantId:string,clientId:string,clientSecret:string,siteId:string,pfxPath:string,pfxPassword:string} */
function reportes_sharepoint_config(): array
{
    $candidatePaths = [
        '/home/juanpab1/reportes-config/config.php',
        '/home/juanpab1/portal-config/config.php',
    ];

    $raw = null;
    foreach ($candidatePaths as $path) {
        if (!is_file($path)) continue;
        $loaded = require $path;
        if (is_array($loaded)) {
            $raw = $loaded;
            break;
        }
    }

    if (!is_array($raw)) {
        throw new RuntimeException('No se encontró la configuración privada para Reportes.');
    }

    $config = [
        'tenantId' => trim((string) ($raw['reportes_tenant_id'] ?? $raw['portal_access_tenant_id'] ?? $raw['solicitud_backend_tenant_id'] ?? '')),
        'clientId' => trim((string) ($raw['reportes_client_id'] ?? $raw['portal_access_client_id'] ?? $raw['solicitud_backend_client_id'] ?? '')),
        'clientSecret' => trim((string) ($raw['reportes_client_secret'] ?? $raw['portal_access_client_secret'] ?? $raw['solicitud_backend_client_secret'] ?? '')),
        'siteId' => trim((string) ($raw['reportes_sharepoint_site_id'] ?? $raw['portal_access_sharepoint_site_id'] ?? $raw['solicitud_sharepoint_site_id'] ?? '')),
        'pfxPath' => trim((string) ($raw['reportes_sharepoint_pfx_path'] ?? $raw['portal_access_sharepoint_pfx_path'] ?? $raw['solicitud_sharepoint_pfx_path'] ?? '')),
        'pfxPassword' => (string) ($raw['reportes_sharepoint_pfx_password'] ?? $raw['portal_access_sharepoint_pfx_password'] ?? $raw['solicitud_sharepoint_pfx_password'] ?? ''),
    ];

    foreach (['tenantId', 'clientId', 'clientSecret', 'siteId', 'pfxPath'] as $key) {
        if ($config[$key] === '') {
            throw new RuntimeException('Falta configurar ' . $key . ' para Reportes.');
        }
    }

    if (!is_file($config['pfxPath']) || !is_readable($config['pfxPath'])) {
        throw new RuntimeException('El certificado PFX de Reportes no está disponible.');
    }

    return $config;
}

/** @return array<string,mixed> */
function reportes_remote_json(string $url, string $method, array $headers, ?string $body = null): array
{
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('No fue posible inicializar cURL.');

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($curl, $options);

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($response === false) throw new RuntimeException('La solicitud remota falló: ' . $error);

    $decoded = json_decode((string) $response, true);
    if ($status < 200 || $status >= 300) {
        $detail = is_array($decoded)
            ? (string) ($decoded['error']['message'] ?? $decoded['error_description'] ?? '')
            : '';
        throw new RuntimeException('Servicio remoto respondió HTTP ' . $status . ($detail !== '' ? ': ' . $detail : '.'));
    }

    return is_array($decoded) ? $decoded : [];
}

function reportes_graph_app_token(array $config): string
{
    $url = 'https://login.microsoftonline.com/' . rawurlencode($config['tenantId']) . '/oauth2/v2.0/token';
    $body = http_build_query([
        'client_id' => $config['clientId'],
        'client_secret' => $config['clientSecret'],
        'scope' => 'https://graph.microsoft.com/.default',
        'grant_type' => 'client_credentials',
    ], '', '&', PHP_QUERY_RFC3986);

    $data = reportes_remote_json($url, 'POST', [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ], $body);

    $token = trim((string) ($data['access_token'] ?? ''));
    if ($token === '') throw new RuntimeException('Microsoft Entra no devolvió token para Reportes.');
    return $token;
}

function reportes_base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function reportes_sharepoint_token(array $config, string $host): string
{
    $bytes = file_get_contents($config['pfxPath']);
    if ($bytes === false || $bytes === '') throw new RuntimeException('No fue posible leer el PFX de Reportes.');

    $certs = [];
    if (!openssl_pkcs12_read($bytes, $certs, $config['pfxPassword'])) {
        throw new RuntimeException('No fue posible abrir el PFX de Reportes.');
    }

    $privateKey = $certs['pkey'] ?? null;
    $certificate = (string) ($certs['cert'] ?? '');
    if ($privateKey === null || $certificate === '') {
        throw new RuntimeException('El PFX no contiene credenciales utilizables.');
    }

    $der = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $certificate);
    $derBytes = is_string($der) ? base64_decode($der, true) : false;
    if ($derBytes === false) throw new RuntimeException('No fue posible convertir el certificado.');

    $thumbprint = reportes_base64url(hash('sha1', $derBytes, true));
    $tokenUrl = 'https://login.microsoftonline.com/' . rawurlencode($config['tenantId']) . '/oauth2/v2.0/token';
    $now = time();

    $header = reportes_base64url((string) json_encode([
        'alg' => 'RS256',
        'typ' => 'JWT',
        'x5t' => $thumbprint,
    ], JSON_UNESCAPED_SLASHES));

    $claims = reportes_base64url((string) json_encode([
        'aud' => $tokenUrl,
        'iss' => $config['clientId'],
        'sub' => $config['clientId'],
        'jti' => bin2hex(random_bytes(16)),
        'nbf' => $now - 30,
        'iat' => $now,
        'exp' => $now + 300,
    ], JSON_UNESCAPED_SLASHES));

    $unsigned = $header . '.' . $claims;
    $signature = '';
    if (!openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('No fue posible firmar el assertion de Reportes.');
    }

    $assertion = $unsigned . '.' . reportes_base64url($signature);
    $body = http_build_query([
        'client_id' => $config['clientId'],
        'scope' => 'https://' . strtolower($host) . '/.default',
        'grant_type' => 'client_credentials',
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        'client_assertion' => $assertion,
    ], '', '&', PHP_QUERY_RFC3986);

    $data = reportes_remote_json($tokenUrl, 'POST', [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ], $body);

    $token = trim((string) ($data['access_token'] ?? ''));
    if ($token === '') throw new RuntimeException('Microsoft Entra no devolvió token de SharePoint para Reportes.');
    return $token;
}

function reportes_sharepoint_site_url(string $graphToken, string $siteId): string
{
    $data = reportes_remote_json(
        'https://graph.microsoft.com/v1.0/sites/' . rawurlencode($siteId) . '?$select=webUrl',
        'GET',
        ['Authorization: Bearer ' . $graphToken, 'Accept: application/json']
    );

    $url = rtrim(trim((string) ($data['webUrl'] ?? '')), '/');
    if ($url === '') throw new RuntimeException('Graph no devolvió la URL del sitio de SharePoint.');
    return $url;
}

/** @return array{siteUrl:string,token:string} */
function reportes_sharepoint_session(): array
{
    static $session = null;
    if (is_array($session)) return $session;

    $config = reportes_sharepoint_config();
    $graphToken = reportes_graph_app_token($config);
    $siteUrl = reportes_sharepoint_site_url($graphToken, $config['siteId']);
    $host = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
    if ($host === '') throw new RuntimeException('No fue posible determinar el host de SharePoint.');

    $session = [
        'siteUrl' => $siteUrl,
        'token' => reportes_sharepoint_token($config, $host),
    ];
    return $session;
}

/**
 * Alias de grupos aceptados por área. Se soportan tanto los nombres simples
 * como los nombres prefijados usados durante la configuración inicial.
 *
 * @return array<string,string[]>
 */
function reportes_group_aliases(): array
{
    return [
        'Vendedores' => ['Reportes - Vendedores', 'Vendedores'],
        'Parque' => ['Reportes - Parque', 'Parque'],
        'Capillas' => ['Reportes - Capillas', 'Capillas'],
        'Administradores' => ['Reportes - Administradores', 'Administradores'],
    ];
}

/** @return array<int,array{email:string,loginName:string}> */
function reportes_sharepoint_group_users(string $groupName): array
{
    $session = reportes_sharepoint_session();
    $odataName = str_replace("'", "''", trim($groupName));
    $url = rtrim($session['siteUrl'], '/')
        . "/_api/web/sitegroups/getbyname('"
        . rawurlencode($odataName)
        . "')/users?$select=Email,LoginName";

    $data = reportes_remote_json($url, 'GET', [
        'Authorization: Bearer ' . $session['token'],
        'Accept: application/json;odata=nometadata',
        'Content-Type: application/json;odata=nometadata',
    ]);

    $result = [];
    foreach (($data['value'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $result[] = [
            'email' => strtolower(trim((string) ($row['Email'] ?? ''))),
            'loginName' => strtolower(trim((string) ($row['LoginName'] ?? ''))),
        ];
    }
    return $result;
}

/**
 * Devuelve un diagnóstico seguro: no expone tokens ni credenciales, solo
 * cuáles alias de grupo existen y si el correo consultado aparece en ellos.
 *
 * @return array<int,array{area:string,group:string,status:string,members:int,matched:bool}>
 */
function reportes_group_diagnostics(string $email): array
{
    $email = strtolower(trim($email));
    $diagnostics = [];

    foreach (reportes_group_aliases() as $area => $aliases) {
        foreach ($aliases as $groupName) {
            try {
                $users = reportes_sharepoint_group_users($groupName);
                $matched = false;
                foreach ($users as $user) {
                    if ($user['email'] === $email || ($user['loginName'] !== '' && strpos($user['loginName'], $email) !== false)) {
                        $matched = true;
                        break;
                    }
                }
                $diagnostics[] = [
                    'area' => $area,
                    'group' => $groupName,
                    'status' => 'encontrado',
                    'members' => count($users),
                    'matched' => $matched,
                ];
            } catch (Throwable $error) {
                $message = $error->getMessage();
                $status = (strpos($message, 'HTTP 404') !== false) ? 'no existe'
                    : ((strpos($message, 'HTTP 403') !== false) ? 'sin permiso' : 'error');
                $diagnostics[] = [
                    'area' => $area,
                    'group' => $groupName,
                    'status' => $status,
                    'members' => 0,
                    'matched' => false,
                ];
            }
        }
    }

    return $diagnostics;
}

/** @return string[] */
function reportes_user_areas(string $email): array
{
    $email = strtolower(trim($email));
    if ($email === '') return [];

    $areas = [];
    foreach (reportes_group_aliases() as $area => $aliases) {
        foreach ($aliases as $groupName) {
            try {
                $users = reportes_sharepoint_group_users($groupName);
            } catch (Throwable $error) {
                $message = $error->getMessage();
                if (strpos($message, 'HTTP 403') !== false || strpos($message, 'HTTP 404') !== false) continue;
                throw $error;
            }

            foreach ($users as $user) {
                if ($user['email'] === $email || ($user['loginName'] !== '' && strpos($user['loginName'], $email) !== false)) {
                    $areas[strtolower($area)] = $area;
                    break 2;
                }
            }
        }
    }

    return array_values($areas);
}

/** @return array<int,array<string,mixed>> */
function reportes_list_items(int $top = 300): array
{
    $session = reportes_sharepoint_session();
    $top = max(1, min($top, 500));
    $escaped = rawurlencode("Reportes");
    $url = rtrim($session['siteUrl'], '/') . "/_api/web/lists/getbytitle('" . $escaped . "')/items?\$top=" . $top;

    $data = reportes_remote_json($url, 'GET', [
        'Authorization: Bearer ' . $session['token'],
        'Accept: application/json;odata=nometadata',
        'Content-Type: application/json;odata=nometadata',
    ]);

    $items = [];
    foreach (($data['value'] ?? []) as $row) {
        if (is_array($row)) $items[] = $row;
    }
    return $items;
}

/** @return array<int,array{name:string,url:string}> */
function reportes_item_attachments(int $itemId): array
{
    if ($itemId <= 0) return [];

    $session = reportes_sharepoint_session();
    $url = rtrim($session['siteUrl'], '/') . "/_api/web/lists/getbytitle('Reportes')/items(" . $itemId . ")/AttachmentFiles?$select=FileName,ServerRelativeUrl";

    $data = reportes_remote_json($url, 'GET', [
        'Authorization: Bearer ' . $session['token'],
        'Accept: application/json;odata=nometadata',
        'Content-Type: application/json;odata=nometadata',
    ]);

    $scheme = (string) (parse_url($session['siteUrl'], PHP_URL_SCHEME) ?: 'https');
    $host = (string) parse_url($session['siteUrl'], PHP_URL_HOST);
    $origin = $host !== '' ? $scheme . '://' . $host : rtrim($session['siteUrl'], '/');

    $result = [];
    foreach (($data['value'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $relative = trim((string) ($row['ServerRelativeUrl'] ?? ''));
        if ($relative === '') continue;
        $result[] = [
            'name' => trim((string) ($row['FileName'] ?? 'Archivo')),
            'url' => $origin . '/' . ltrim($relative, '/'),
        ];
    }
    return $result;
}
