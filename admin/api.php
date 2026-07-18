<?php
// admin/api.php  — backend for the admin dashboard
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

session_start();
header('Content-Type: application/json; charset=utf-8');

// ---- Admin credentials ----
// email: sami@gmail.com  /  password: 123123123
const ADMIN_EMAIL = 'sami@gmail.com';
// bcrypt hash of "123123123"
const ADMIN_PASS_HASH = '$2y$10$qK8REAY6n0Zem8aYzjm4y.QJmj4JCxyalUHpD3LS5qUSn8oXSMKwu';

const REQUESTS_BASE = __DIR__ . '/../requests';

function out(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): void {
    out(['ok' => false, 'error' => $msg], $code);
}

function requireAuth(): void {
    if (empty($_SESSION['admin'])) {
        fail('Unauthorized', 401);
    }
}

/**
 * Resolve a client-supplied relative storage path to an absolute, verified
 * directory that lives inside REQUESTS_BASE. Prevents path traversal.
 */
function resolveReqDir(string $rel): string {
    $rel  = str_replace('\\', '/', trim($rel));
    $rel  = ltrim($rel, '/');
    $abs  = realpath(REQUESTS_BASE . '/' . $rel);
    $base = realpath(REQUESTS_BASE);
    if ($abs === false || $base === false || strncmp($abs, $base, strlen($base)) !== 0) {
        fail('Invalid request path', 400);
    }
    if (!is_dir($abs)) {
        fail('Request not found', 404);
    }
    return $abs;
}

function makeMailer(): PHPMailer {
    $mail = new PHPMailer(true);
    // MailHog (local SMTP catcher): 127.0.0.1:1025, no auth, no encryption.
    $mail->isSMTP();
    $mail->Host        = '127.0.0.1';
    $mail->Port        = 1025;
    $mail->SMTPAuth    = false;
    $mail->SMTPSecure  = '';
    $mail->SMTPAutoTLS = false;
    $mail->CharSet     = 'UTF-8';
    $mail->setFrom('no-reply@iarda.gov.sa', 'IT Project Requests');
    return $mail;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // ---------------------------------------------------------------- login
    case 'login': {
        $email = trim((string)($_POST['email'] ?? ''));
        $pass  = (string)($_POST['password'] ?? '');
        if (strcasecmp($email, ADMIN_EMAIL) === 0 && password_verify($pass, ADMIN_PASS_HASH)) {
            session_regenerate_id(true);
            $_SESSION['admin'] = $email;
            out(['ok' => true, 'email' => $email]);
        }
        fail('البريد الإلكتروني أو كلمة المرور غير صحيحة', 401);
    }

    // --------------------------------------------------------------- logout
    case 'logout': {
        $_SESSION = [];
        session_destroy();
        out(['ok' => true]);
    }

    // ------------------------------------------------------------------- me
    case 'me': {
        out(['ok' => true, 'authenticated' => !empty($_SESSION['admin']), 'email' => $_SESSION['admin'] ?? null]);
    }

    // ----------------------------------------------------------------- list
    case 'list': {
        requireAuth();
        $items = [];
        $base = (string)realpath(REQUESTS_BASE);
        // requests/<year>/<month>/<type>/<name>/<seq>/request.json
        foreach (glob(REQUESTS_BASE . '/*/*/*/*/*/request.json') ?: [] as $file) {
            $json = json_decode((string)file_get_contents($file), true);
            if (!is_array($json)) continue;
            // resolve the '/../' in the glob path so the prefix strip is exact
            $dir = dirname((string)realpath($file));
            $rel = str_replace('\\', '/', substr($dir, strlen($base) + 1));
            $items[] = [
                'path'          => $rel,
                'projectName'   => $json['projectName']   ?? '',
                'requesterName' => $json['requesterName'] ?? '',
                'requesterEmail'=> $json['requesterEmail']?? '',
                'requestType'   => $json['requestType']   ?? '',
                'savedAt'       => $json['savedAt']        ?? '',
                'totalScore'    => $json['evaluationResult']['totalScore'] ?? null,
                'priority'      => $json['evaluationResult']['priority']   ?? '',
                'adminStatus'   => $json['adminStatus']    ?? 'new',
                'resultSentAt'  => $json['resultSentAt']   ?? null,
            ];
        }
        // newest first
        usort($items, fn($a, $b) => strcmp((string)$b['savedAt'], (string)$a['savedAt']));
        out(['ok' => true, 'items' => $items]);
    }

    // ------------------------------------------------------------------ get
    case 'get': {
        requireAuth();
        $dir  = resolveReqDir((string)($_GET['path'] ?? ''));
        $file = $dir . '/request.json';
        if (!is_file($file)) fail('Request not found', 404);
        $json = json_decode((string)file_get_contents($file), true);
        if (!is_array($json)) fail('Corrupt request file', 500);

        // list attachment files (everything except request.json)
        $attachments = [];
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f) && basename($f) !== 'request.json') {
                $attachments[] = basename($f);
            }
        }
        out(['ok' => true, 'data' => $json, 'attachments' => $attachments]);
    }

    // --------------------------------------------------------------- update
    // Save admin decision and (optionally) email the final result to applicant
    case 'update': {
        requireAuth();
        $dir  = resolveReqDir((string)($_POST['path'] ?? ''));
        $file = $dir . '/request.json';
        if (!is_file($file)) fail('Request not found', 404);
        $json = json_decode((string)file_get_contents($file), true);
        if (!is_array($json)) fail('Corrupt request file', 500);

        $status  = trim((string)($_POST['adminStatus']  ?? ''));
        $result  = trim((string)($_POST['finalResult']  ?? ''));
        $message = trim((string)($_POST['adminMessage'] ?? ''));
        $send    = ($_POST['sendEmail'] ?? '0') === '1';

        $allowedStatus = ['new', 'in-review', 'approved', 'rejected', 'needs-info'];
        if ($status !== '' && !in_array($status, $allowedStatus, true)) {
            fail('Invalid status', 400);
        }

        if ($status !== '')  $json['adminStatus']  = $status;
        $json['finalResult']  = $result;
        $json['adminMessage'] = $message;
        $json['adminUpdatedAt'] = (new DateTime('now'))->format(DateTime::ATOM);

        // Merge the IT evaluation fields (technical assessment, timings, decision)
        // that the admin fills in the dashboard. Only allowlisted keys are accepted.
        $fieldsRaw = (string)($_POST['fields'] ?? '');
        if ($fieldsRaw !== '') {
            $fields = json_decode($fieldsRaw, true);
            if (is_array($fields)) {
                $allow = [
                    'technicalApplicability', 'technicalType',
                    't1', 't2', 't3', 't4', 't5', 't6', 't7',
                    'analysisDuration', 'developmentDuration', 'testingDuration', 'launchDuration',
                    'risks', 'additionalRequirements',
                    'finalDecision', 'executionPath', 'itNotes', 'reviewerName',
                    'evaluationResult',
                ];
                foreach ($allow as $k) {
                    if (array_key_exists($k, $fields)) {
                        $json[$k] = $fields[$k];
                    }
                }
            }
        }

        $emailError = null;
        if ($send) {
            $to = (string)($json['requesterEmail'] ?? '');
            if ($to === '') {
                $emailError = 'No requester email on file';
            } else {
                $statusLabels = [
                    'approved'  => 'تمت الموافقة',
                    'rejected'  => 'تم الرفض',
                    'needs-info'=> 'بحاجة إلى استكمال',
                    'in-review' => 'قيد المراجعة',
                    'new'       => 'جديد',
                ];
                $statusAr = $statusLabels[$json['adminStatus'] ?? 'new'] ?? ($json['adminStatus'] ?? '');
                try {
                    $m = makeMailer();
                    $m->addAddress($to, (string)($json['requesterName'] ?? ''));
                    $m->isHTML(true);
                    $m->Subject = 'نتيجة طلبك - ' . (string)($json['projectName'] ?? '');
                    $m->Body = "
                        <div dir='rtl' style='font-family:Tahoma,Arial,sans-serif;font-size:14px;line-height:1.9'>
                            <p>عزيزي/عزيزتي " . htmlspecialchars((string)($json['requesterName'] ?? '')) . "،</p>
                            <p>بخصوص طلبك <strong>«" . htmlspecialchars((string)($json['projectName'] ?? '')) . "»</strong>، نفيدكم بما يلي:</p>
                            <p><strong>الحالة:</strong> " . htmlspecialchars($statusAr) . "</p>
                            " . ($result  !== '' ? "<p><strong>النتيجة النهائية:</strong><br>" . nl2br(htmlspecialchars($result))  . "</p>" : "") . "
                            " . ($message !== '' ? "<p><strong>ملاحظات:</strong><br>"       . nl2br(htmlspecialchars($message)) . "</p>" : "") . "
                            <p>مع خالص التقدير،<br>إدارة تقنية المعلومات</p>
                        </div>";
                    $m->AltBody = "نتيجة طلبك ({$json['projectName']}): $statusAr\n$result\n$message";
                    $m->send();
                    $json['resultSentAt'] = (new DateTime('now'))->format(DateTime::ATOM);
                } catch (Exception $e) {
                    $emailError = $m->ErrorInfo ?? $e->getMessage();
                }
            }
        }

        if (file_put_contents($file, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
            fail('Could not save request', 500);
        }

        out(['ok' => true, 'emailSent' => $send && $emailError === null, 'emailError' => $emailError, 'data' => $json]);
    }

    // -------------------------------------------------------------- default
    default:
        fail('Unknown action', 404);
}
