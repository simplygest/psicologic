<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail_helpers.php';

function system_mail_settings()
{
    return [
        'host' => trim((string) psicologic_config_value('system_smtp_host', '')),
        'port' => (int) psicologic_config_value('system_smtp_port', 587),
        'secure' => strtolower(trim((string) psicologic_config_value('system_smtp_secure', 'tls'))),
        'username' => trim((string) psicologic_config_value('system_smtp_username', '')),
        'password' => (string) psicologic_config_value('system_smtp_password', ''),
        'from_email' => trim((string) psicologic_config_value('system_email_from', '')),
        'from_name' => trim((string) psicologic_config_value('system_email_from_name', 'SimplyGest Praxis')),
        'reply_to' => trim((string) psicologic_config_value('system_email_reply_to', '')),
        'notification_email' => trim((string) psicologic_config_value(
            'system_notification_email',
            psicologic_config_value('system_email_from', '')
        )),
    ];
}

function system_mail_is_configured()
{
    $settings = system_mail_settings();
    return $settings['host'] !== ''
        && $settings['port'] > 0
        && $settings['username'] !== ''
        && $settings['password'] !== ''
        && filter_var($settings['from_email'], FILTER_VALIDATE_EMAIL);
}

function send_system_email($to, $subject, $html_body)
{
    global $SYSTEM_EMAIL_LAST_ERROR;
    $SYSTEM_EMAIL_LAST_ERROR = '';

    $to = trim((string) $to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $SYSTEM_EMAIL_LAST_ERROR = 'Dirección de destino no válida.';
        return false;
    }
    if (!system_mail_is_configured()) {
        $SYSTEM_EMAIL_LAST_ERROR = 'El correo interno de SimplyGest Praxis no está configurado.';
        return false;
    }
    if (!load_phpmailer()) {
        $SYSTEM_EMAIL_LAST_ERROR = 'PHPMailer no está disponible.';
        return false;
    }

    $settings = system_mail_settings();
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->Host = $settings['host'];
        $mail->Port = $settings['port'];
        $mail->Timeout = 15;
        $mail->SMTPAuth = true;
        $mail->Username = $settings['username'];
        $mail->Password = $settings['password'];

        if ($settings['secure'] === 'ssl' || $settings['secure'] === 'smtps') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($settings['secure'] === 'tls' || $settings['secure'] === 'starttls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom($settings['from_email'], $settings['from_name'] ?: 'SimplyGest Praxis');
        $mail->addAddress($to);
        if (filter_var($settings['reply_to'], FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($settings['reply_to']);
        }
        $mail->isHTML(true);
        $mail->Subject = (string) $subject;
        $mail->Body = (string) $html_body;
        $mail->AltBody = trim(html_entity_decode(
            strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", (string) $html_body)),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        ));
        $mail->send();
        return true;
    } catch (Throwable $exception) {
        $SYSTEM_EMAIL_LAST_ERROR = $exception->getMessage();
        error_log('Correo interno SGPraxis: ' . $exception->getMessage());
        return false;
    }
}

function system_mail_minimal_layout($title, $body_html, $logo_url = '')
{
    $logo_html = '';
    $logo_url = trim((string) $logo_url);
    if ($logo_url !== '') {
        $logo_html = '<div style="text-align:center;margin-bottom:24px">'
            . '<img src="' . htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8') . '" alt="SimplyGest Praxis" '
            . 'style="display:inline-block;max-width:240px;max-height:72px;width:auto;height:auto">'
            . '</div>';
    }

    return '<div style="background:#f5f7fa;padding:28px;font-family:Arial,sans-serif;color:#263238">'
        . '<div style="max-width:620px;margin:0 auto;background:#fff;border:1px solid #dfe4ea;padding:28px">'
        . $logo_html
        . '<div style="font-size:22px;font-weight:700;margin-bottom:18px">' . htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8') . '</div>'
        . $body_html
        . '<hr style="border:0;border-top:1px solid #e1e5ec;margin:24px 0 14px">'
        . '<div style="font-size:12px;color:#7a8791">SimplyGest Praxis</div>'
        . '</div></div>';
}
