<?php

declare(strict_types=1);

require_once __DIR__ . '/reportes-sharepoint.php';

/** @return array{tenantId:string,clientId:string,clientSecret:string,sender:string} */
function reportes_notification_config(): array
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
        throw new RuntimeException('No se encontró la configuración privada para notificaciones de Reportes.');
    }

    $config = [
        'tenantId' => trim((string) (
            $raw['reportes_notification_tenant_id']
            ?? $raw['solicitud_backend_tenant_id']
            ?? $raw['reportes_tenant_id']
            ?? $raw['portal_access_tenant_id']
            ?? ''
        )),
        'clientId' => trim((string) (
            $raw['reportes_notification_client_id']
            ?? $raw['solicitud_backend_client_id']
            ?? $raw['reportes_client_id']
            ?? $raw['portal_access_client_id']
            ?? ''
        )),
        'clientSecret' => trim((string) (
            $raw['reportes_notification_client_secret']
            ?? $raw['solicitud_backend_client_secret']
            ?? $raw['reportes_client_secret']
            ?? $raw['portal_access_client_secret']
            ?? ''
        )),
        'sender' => strtolower(trim((string) (
            $raw['reportes_notification_sender']
            ?? $raw['solicitud_notification_sender']
            ?? ''
        ))),
    ];

    foreach (['tenantId', 'clientId', 'clientSecret', 'sender'] as $key) {
        if ($config[$key] === '') {
            throw new RuntimeException('Falta configurar ' . $key . ' para las notificaciones de Reportes.');
        }
    }

    if (!filter_var($config['sender'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('El remitente configurado para Reportes no es un correo válido.');
    }

    return $config;
}

function reportes_notification_graph_token(array $config): string
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
    if ($token === '') throw new RuntimeException('Microsoft Entra no devolvió token para enviar notificaciones.');
    return $token;
}

/** @param string[] $emails @return string[] */
function reportes_notification_normalize_recipients(array $emails): array
{
    $result = [];
    foreach ($emails as $email) {
        $email = strtolower(trim((string) $email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $result[$email] = true;
    }
    return array_keys($result);
}

/** @return string[] */
function reportes_notification_recipients_for_area(string $area): array
{
    $aliases = reportes_group_aliases();
    $groupNames = $aliases[$area] ?? [];
    $recipients = [];

    foreach ($groupNames as $groupName) {
        try {
            $users = reportes_sharepoint_group_users($groupName);
        } catch (Throwable $error) {
            $message = $error->getMessage();
            if (strpos($message, 'HTTP 403') !== false || strpos($message, 'HTTP 404') !== false) continue;
            throw $error;
        }

        foreach ($users as $user) {
            $email = strtolower(trim((string) ($user['email'] ?? '')));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[$email] = true;
            }
        }

        if ($recipients) break;
    }

    return array_keys($recipients);
}

/** @param string[] $recipients */
function reportes_notification_send_mail(
    array $recipients,
    string $subject,
    string $html
): void {
    $recipients = reportes_notification_normalize_recipients($recipients);
    if (!$recipients) throw new RuntimeException('No hay destinatarios válidos para la notificación de Reportes.');

    $config = reportes_notification_config();
    $token = reportes_notification_graph_token($config);

    $toRecipients = [];
    foreach ($recipients as $email) {
        $toRecipients[] = ['emailAddress' => ['address' => $email]];
    }

    $payload = json_encode([
        'message' => [
            'subject' => $subject,
            'body' => [
                'contentType' => 'HTML',
                'content' => $html,
            ],
            'toRecipients' => $toRecipients,
        ],
        'saveToSentItems' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($payload)) {
        throw new RuntimeException('No fue posible construir el correo de Reportes.');
    }

    reportes_remote_json(
        'https://graph.microsoft.com/v1.0/users/' . rawurlencode($config['sender']) . '/sendMail',
        'POST',
        [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        $payload
    );
}

function reportes_notification_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function reportes_notification_new_report(
    int $itemId,
    string $folio,
    string $area,
    string $priority,
    string $requesterName,
    string $requesterEmail,
    string $description,
    string $client = '',
    string $contract = '',
    string $location = ''
): void {
    $recipients = reportes_notification_recipients_for_area($area);
    if (!$recipients) {
        throw new RuntimeException('El grupo Reportes - ' . $area . ' no tiene miembros con correo para notificar.');
    }

    $link = 'https://portal.juanpablo.com.mx/reportes-preview/gestionar.php?id=' . $itemId;
    $rows = '';

    $details = [
        'Folio' => $folio,
        'Área' => $area,
        'Prioridad' => $priority,
        'Solicitante' => $requesterName,
        'Correo del solicitante' => $requesterEmail,
    ];

    if (strcasecmp($area, 'Parque') === 0) {
        if ($client !== '') $details['Cliente'] = $client;
        if ($contract !== '') $details['Contrato'] = $contract;
        if ($location !== '') $details['Ubicación'] = $location;
    }

    foreach ($details as $label => $value) {
        $rows .= '<tr>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #eadfce;font-weight:700;color:#4b210f;width:180px">'
            . reportes_notification_escape($label)
            . '</td>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #eadfce;color:#2d241f">'
            . reportes_notification_escape((string) $value)
            . '</td>'
            . '</tr>';
    }

    $safeDescription = nl2br(reportes_notification_escape($description));
    $safeLink = reportes_notification_escape($link);
    $safeArea = reportes_notification_escape($area);
    $safeFolio = reportes_notification_escape($folio);

    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f5f2ed;font-family:Arial,sans-serif;color:#2d241f">'
        . '<div style="max-width:720px;margin:0 auto;padding:28px 18px">'
        . '<div style="background:#ffffff;border:1px solid #e4dbcf;border-radius:14px;overflow:hidden">'
        . '<div style="padding:22px 26px;background:#4b1808;color:#ffffff;border-bottom:4px solid #f3a51a">'
        . '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#ffd37b">Nuevo reporte</div>'
        . '<h1 style="margin:7px 0 0;font-size:24px">' . $safeFolio . ' · ' . $safeArea . '</h1>'
        . '</div>'
        . '<div style="padding:24px 26px">'
        . '<p style="margin:0 0 18px;font-size:15px;line-height:1.55">Se registró un nuevo reporte que corresponde a tu área.</p>'
        . '<table style="width:100%;border-collapse:collapse;background:#fbf9f6;border:1px solid #eadfce;border-radius:10px">' . $rows . '</table>'
        . '<div style="margin-top:20px;padding:16px 18px;background:#fff8e8;border-left:4px solid #f3a51a;border-radius:8px">'
        . '<div style="font-size:12px;font-weight:700;text-transform:uppercase;color:#6f4a10;margin-bottom:7px">Descripción</div>'
        . '<div style="font-size:15px;line-height:1.55">' . $safeDescription . '</div>'
        . '</div>'
        . '<div style="margin-top:24px"><a href="' . $safeLink . '" style="display:inline-block;padding:12px 18px;background:#f3a51a;color:#351200;text-decoration:none;font-weight:700;border-radius:8px">Abrir y gestionar reporte</a></div>'
        . '<p style="margin:22px 0 0;font-size:12px;color:#73685f">Este correo fue generado automáticamente por la herramienta Reportes de Jardines de Juan Pablo.</p>'
        . '</div></div></div></body></html>';

    reportes_notification_send_mail(
        $recipients,
        '[Reportes] Nuevo ' . $folio . ' - ' . $area . ' - ' . $priority,
        $html
    );
}
