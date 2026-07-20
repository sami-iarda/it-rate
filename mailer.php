<?php
// mailer.php — single place where SMTP settings are applied to PHPMailer.
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Build a configured PHPMailer instance from the .env SMTP settings.
 *
 * @param string|null $fromName Override the display name on the From header.
 */
function makeMailer(?string $fromName = null): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = env_required('SMTP_HOST');
    $mail->Port       = (int)env('SMTP_PORT', 587);
    $mail->SMTPAuth   = (bool)env('SMTP_AUTH', true);
    $mail->SMTPSecure = (string)env('SMTP_SECURE', PHPMailer::ENCRYPTION_STARTTLS);
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = (int)env('SMTP_TIMEOUT', 30);
    $mail->SMTPKeepAlive = false;

    if ($mail->SMTPAuth) {
        $mail->Username = env_required('SMTP_USERNAME');
        $mail->Password = env_required('SMTP_PASSWORD');
    }

    $debug = (int)env('SMTP_DEBUG', 0);
    if ($debug > 0) {
        $mail->SMTPDebug   = $debug;
        $mail->Debugoutput = 'error_log';
    }

    $mail->setFrom(
        env_required('MAIL_FROM'),
        $fromName ?? (string)env('MAIL_FROM_NAME', '')
    );
    return $mail;
}
