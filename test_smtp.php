<?php
// Test SMTP connection using the settings in .env
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/mailer.php';

use PHPMailer\PHPMailer\Exception;

try {
    $mail = makeMailer();

    // Verbose debug output for this test run only
    $mail->SMTPDebug   = 3; // 0=off, 1=client, 2=client+server, 3=detailed
    $mail->Debugoutput = function ($str, $level) {
        echo "DEBUG level $level; message: $str\n";
    };

    $mail->addAddress(env_required('TEST_RECIPIENT'), 'Test Recipient');

    $mail->isHTML(true);
    $mail->Subject = 'Test Email - ' . date('Y-m-d H:i:s');
    $mail->Body    = '<div dir="rtl">هذه رسالة اختبار من نظام PHPMailer</div>';
    $mail->AltBody = 'This is a test email from PHPMailer';

    echo "\n=== Attempting to send email ===\n\n";
    $mail->send();
    echo "\n=== SUCCESS: Email has been sent ===\n";
} catch (Exception $e) {
    echo "\n=== FAILED: Email could not be sent ===\n";
    echo "Error: " . ($mail->ErrorInfo ?? '') . "\n";
    echo "Exception: {$e->getMessage()}\n";
    echo "\nTrace:\n";
    echo $e->getTraceAsString() . "\n";
}
