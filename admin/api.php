<?php
// admin/api.php — backend for the IT admin dashboard (new request format)
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../mailer.php';

use PHPMailer\PHPMailer\Exception;

session_start();
header('Content-Type: application/json; charset=utf-8');

// ---- Admin credentials (values live in .env — see .env.example) --------------
define('ADMIN_EMAIL',     env_required('ADMIN_EMAIL'));
define('ADMIN_PASS_HASH', env_required('ADMIN_PASS_HASH'));

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
        fail('غير مصرح', 401);
    }
}

/**
 * Resolve a client-supplied relative path to an existing *.json request file
 * that lives inside REQUESTS_BASE. Prevents path traversal.
 */
function resolveReqFile(string $rel): string {
    $rel  = str_replace('\\', '/', trim($rel));
    $rel  = ltrim($rel, '/');
    $abs  = realpath(REQUESTS_BASE . '/' . $rel);
    $base = realpath(REQUESTS_BASE);
    if ($abs === false || $base === false || strncmp($abs, $base, strlen($base)) !== 0) {
        fail('مسار غير صالح', 400);
    }
    if (!is_file($abs) || strtolower(pathinfo($abs, PATHINFO_EXTENSION)) !== 'json') {
        fail('الطلب غير موجود', 404);
    }
    return $abs;
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
        // requests/<year>/<month>/<department>/<name>_<seq>.json
        foreach (glob(REQUESTS_BASE . '/*/*/*/*.json') ?: [] as $file) {
            $json = json_decode((string)file_get_contents($file), true);
            if (!is_array($json)) continue;
            $rel = str_replace('\\', '/', substr((string)realpath($file), strlen($base) + 1));
            $items[] = [
                'file'           => $rel,
                'reference'      => $json['reference']      ?? '',
                'projectName'    => $json['projectName']    ?? '',
                'department'     => $json['department']     ?? '',
                'applicantName'  => $json['applicantName']  ?? '',
                'applicantEmail' => $json['applicantEmail'] ?? '',
                'requestType'    => $json['requestType']    ?? '',
                'savedAt'        => $json['savedAt']        ?? '',
                'adminStatus'    => $json['adminStatus']    ?? 'new',
                'totalScore'     => $json['evaluation']['total']    ?? null,
                'priority'       => $json['evaluation']['priority'] ?? '',
                'resultSentAt'   => $json['resultSentAt']   ?? null,
            ];
        }
        usort($items, fn($a, $b) => strcmp((string)$b['savedAt'], (string)$a['savedAt'])); // newest first
        out(['ok' => true, 'items' => $items]);
    }

    // ------------------------------------------------------------------ get
    case 'get': {
        requireAuth();
        $file = resolveReqFile((string)($_GET['file'] ?? ''));
        $json = json_decode((string)file_get_contents($file), true);
        if (!is_array($json)) fail('ملف الطلب تالف', 500);
        out(['ok' => true, 'data' => $json]);
    }

    // --------------------------------------------------------------- download
    case 'download': {
        requireAuth();
        $file = resolveReqFile((string)($_GET['file'] ?? ''));
        $name = basename((string)($_GET['name'] ?? ''));
        $path = dirname($file) . '/' . $name;
        $real = realpath($path);
        $base = realpath(REQUESTS_BASE);
        if ($real === false || strncmp($real, $base, strlen($base)) !== 0 || !is_file($real)) {
            http_response_code(404); echo 'Not found'; exit;
        }
        header_remove('Content-Type');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($real));
        readfile($real);
        exit;
    }

    // --------------------------------------------------------------- save
    // Save the IT evaluation (sections 2/3/4) and optionally email the applicant.
    case 'save': {
        requireAuth();
        $file = resolveReqFile((string)($_POST['file'] ?? ''));
        $json = json_decode((string)file_get_contents($file), true);
        if (!is_array($json)) fail('ملف الطلب تالف', 500);

        $evalRaw = (string)($_POST['evaluation'] ?? '');
        $eval    = json_decode($evalRaw, true);
        if (!is_array($eval)) fail('بيانات التقييم غير صالحة', 400);

        $send = ($_POST['sendEmail'] ?? '0') === '1';
        $now  = (new DateTime('now'))->format(DateTime::ATOM);

        $json['evaluation']  = $eval;
        $json['adminStatus'] = 'evaluated';
        $json['evaluatedAt'] = $now;

        $emailError = null;
        if ($send) {
            $to = (string)($json['applicantEmail'] ?? '');
            if ($to === '') {
                $emailError = 'لا يوجد بريد للمتقدّم';
            } else {
                $total = $eval['total']         ?? '';
                $prio  = $eval['priority']      ?? '';
                $appl  = $eval['applicability'] ?? '';
                $notes = $eval['committeeNotes']?? '';
                try {
                    $m = makeMailer((string)env('ADMIN_MAIL_FROM_NAME', ''));
                    $m->addAddress($to, (string)($json['applicantName'] ?? ''));
                    $m->isHTML(true);
                    $m->Subject = 'نتيجة تقييم طلبك - ' . (string)($json['projectName'] ?? '');
                    $m->Body = "
                        <div dir='rtl' style='font-family:Tahoma,Arial,sans-serif;font-size:14px;line-height:1.9'>
                            <p>عزيزي/عزيزتي " . htmlspecialchars((string)($json['applicantName'] ?? '')) . "،</p>
                            <p>بخصوص طلبك <strong>«" . htmlspecialchars((string)($json['projectName'] ?? '')) . "»</strong> (الرقم المرجعي: " . htmlspecialchars((string)($json['reference'] ?? '')) . ")، تم الانتهاء من تقييمه، وفيما يلي النتيجة:</p>
                            <p><strong>قرار قابلية التطبيق:</strong> " . htmlspecialchars((string)$appl) . "</p>
                            <p><strong>إجمالي النقاط المرجّحة:</strong> " . htmlspecialchars((string)$total) . " / 100<br>
                               <strong>الأولوية:</strong> " . htmlspecialchars((string)$prio) . "</p>
                            " . ($notes !== '' ? "<p><strong>ملاحظات وتوصية اللجنة:</strong><br>" . nl2br(htmlspecialchars((string)$notes)) . "</p>" : "") . "
                            <p>مع خالص التقدير،<br>إدارة تقنية المعلومات</p>
                        </div>";
                    $m->AltBody = "نتيجة تقييم طلبك ({$json['projectName']}): $appl — النقاط: $total/100 — الأولوية: $prio";
                    $m->send();
                    $json['resultSentAt'] = $now;
                } catch (Exception $e) {
                    $emailError = $m->ErrorInfo ?? $e->getMessage();
                }
            }
        }

        if (file_put_contents($file, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
            fail('تعذّر حفظ الطلب', 500);
        }
        out(['ok' => true, 'emailSent' => $send && $emailError === null, 'emailError' => $emailError, 'data' => $json]);
    }

    default:
        fail('إجراء غير معروف', 404);
}
