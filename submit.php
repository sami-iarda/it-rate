<?php
// submit.php — receives an applicant request (steps 1 & 2 only),
// stores it as JSON + attachments, and notifies the applicant and IT owner.
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ---- Config -----------------------------------------------------------------
const IT_OWNER_EMAIL = 'abdulrahman.muhanna@iarda.gov.sa';          // IT owner (receives full details)
const IT_OWNER_NAME  = 'إدارة تقنية المعلومات';
const MAIL_FROM      = 'no-reply@iarda.gov.sa';
const MAIL_FROM_NAME = 'نظام طلبات المشاريع التقنية';

/**
 * Local SMTP settings (MailHog: 127.0.0.1:1025, no auth, no TLS).
 * View captured mail at http://127.0.0.1:8025
 * Swap these for a real SMTP host/port/credentials in production.
 */
function makeMailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();                                            //Send using SMTP
    $mail->Host       = "smtp.office365.com";                   //Set the SMTP server to send through
    $mail->SMTPAuth   = true;                                   //Enable SMTP authentication
    $mail->Username   = "no-reply@iarda.gov.sa";                //SMTP username
    $mail->Password   = "ItHrA#u@!Pj9*alnn#";                   //SMTP password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;         //Enable implicit TLS encryption
    $mail->Port       = 587;

    // $mail->isSMTP();
    // $mail->Host        = '127.0.0.1';
    // $mail->Port        = 1025;
    // $mail->SMTPAuth    = false;
    // $mail->SMTPSecure  = '';
    // $mail->SMTPAutoTLS = false;
    $mail->CharSet     = 'UTF-8';
    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    return $mail;
}
// -----------------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed', 405);
}

// ---- 1. Read the JSON payload (form field named "payload") -------------------
$raw  = $_POST['payload'] ?? '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    fail('Invalid or missing payload');
}

// Required fields for an applicant submission
foreach (['projectName', 'department', 'applicantName', 'applicantEmail'] as $req) {
    if (empty($data[$req])) {
        fail("Missing required field: $req");
    }
}
if (!filter_var($data['applicantEmail'], FILTER_VALIDATE_EMAIL)) {
    fail('Invalid applicant email');
}

// ---- 2. Build folder path: requests/[YEAR]/[MONTH]/[DEPARTMENT] --------------
$baseDir = __DIR__ . '/requests';

// Sanitize a path segment so it can't escape the base dir
function segment(string $s): string {
    $s = trim($s);
    $s = preg_replace('/[^\p{Arabic}\w\-]+/u', '_', $s); // keep Arabic + word chars + dash
    $s = trim($s, '_');
    return $s === '' ? 'unknown' : $s;
}

// Next global serial for a given day (01, 02, ...), stored in a locked counter file.
function nextDailySerial(string $baseDir, string $dayKey): string {
    $dir = "$baseDir/.serials";
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $fp = fopen("$dir/$dayKey.seq", 'c+');
    if ($fp === false) {
        return '01';
    }
    flock($fp, LOCK_EX);
    $next = ((int)trim((string)fread($fp, 32))) + 1;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string)$next);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return str_pad((string)$next, 2, '0', STR_PAD_LEFT);
}

$now   = new DateTime('now');
$year  = $now->format('Y');
$month = $now->format('m');
$dept  = segment((string)$data['department']);
$name  = segment((string)$data['applicantName']);

$deptDir = "$baseDir/$year/$month/$dept";
if (!is_dir($deptDir) && !mkdir($deptDir, 0775, true) && !is_dir($deptDir)) {
    fail('Could not create request directory', 500);
}

// ---- 3. Next sequence number for this applicant (01, 02, ...) ----------------
$seq = 1;
foreach (glob("$deptDir/*.json") ?: [] as $existing) {
    $base = basename($existing, '.json');
    if (preg_match('/^' . preg_quote($name, '/') . '_(\d+)$/u', $base, $m)) {
        $seq = max($seq, ((int)$m[1]) + 1);
    }
}
$seqStr   = str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
$fileStem = "{$name}_{$seqStr}";
$relPath  = "requests/$year/$month/$dept/$fileStem.json";

// Reference number: it-proj-[YEAR]-[MONTH]-[DAY]-[SERIAL] (serial resets daily)
$day       = $now->format('d');
$serial    = nextDailySerial($baseDir, "$year-$month-$day");
$reference = "it-proj-$year-$month-$day-$serial";

// ---- 4. Save uploaded attachments in the SAME folder ------------------------
$savedFiles = [];
if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
    $files   = $_FILES['attachments'];
    $count   = count($files['name']);
    $maxSize = 15 * 1024 * 1024; // 15 MB per file
    $allowed = ['pdf','doc','docx','xls','xlsx','csv','txt','png','jpg','jpeg','gif','zip','json'];

    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            fail('Upload error on file: ' . $files['name'][$i]);
        }
        if ($files['size'][$i] > $maxSize) {
            fail('File too large: ' . $files['name'][$i]);
        }

        $orig = basename($files['name'][$i]);
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            fail('File type not allowed: ' . $orig);
        }

        // prefix with the applicant stem so files stay grouped and never collide
        $label = segment(pathinfo($orig, PATHINFO_FILENAME));
        $safe  = "{$fileStem}_{$label}.{$ext}";
        $dest  = "$deptDir/$safe";
        $n = 1;
        while (file_exists($dest)) {
            $safe = "{$fileStem}_{$label}_{$n}.{$ext}";
            $dest = "$deptDir/$safe";
            $n++;
        }

        if (!move_uploaded_file($files['tmp_name'][$i], $dest)) {
            fail('Could not save uploaded file: ' . $orig);
        }
        $savedFiles[] = $dest;
    }
}

// ---- 5. Save the JSON file ---------------------------------------------------
$data['reference']   = $reference;
$data['sequence']    = $seqStr;
$data['savedAt']     = $now->format(DateTime::ATOM);
$data['storagePath'] = $relPath;
$data['attachments'] = array_map('basename', $savedFiles);

$jsonPath = "$deptDir/$fileStem.json";
if (file_put_contents($jsonPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
    fail('Could not write request file', 500);
}

// ---- 6. Send the two emails --------------------------------------------------
$emailErrors = [];

// (a) Acknowledgement to the applicant — "received and under process"
try {
    $m = makeMailer();
    $m->addAddress((string)$data['applicantEmail'], (string)$data['applicantName']);
    $m->isHTML(true);
    $m->Subject = 'تم استلام طلبك - ' . $data['projectName'];
    $m->Body = "
        <div dir='rtl' style='font-family:Tahoma,Arial,sans-serif;font-size:14px;line-height:1.9'>
            <p>عزيزي/عزيزتي " . htmlspecialchars((string)$data['applicantName']) . "،</p>
            <p>نشكرك على تقديم طلبك <strong>«" . htmlspecialchars((string)$data['projectName']) . "»</strong>.</p>
            <p>نود إعلامك بأنه قد تم <strong>استلام طلبك بنجاح</strong>، وهو الآن <strong>قيد المراجعة والمعالجة</strong>
               من قبل إدارة تقنية المعلومات، وسنوافيك بالمستجدات في حينه.</p>
            <p>الإدارة الطالبة: " . htmlspecialchars((string)$data['department']) . "<br>
               الرقم المرجعي: " . htmlspecialchars($reference) . "</p>
            <p>مع خالص التقدير،<br>إدارة تقنية المعلومات</p>
        </div>";
    $m->AltBody = "تم استلام طلبك: {$data['projectName']}. طلبك الآن قيد المراجعة والمعالجة.";
    $m->send();
} catch (Exception $e) {
    $emailErrors[] = 'applicant email: ' . ($m->ErrorInfo ?? $e->getMessage());
}

// (b) Full details to the IT owner, with JSON + attachments
try {
    $m = makeMailer();
    $m->addAddress(IT_OWNER_EMAIL, IT_OWNER_NAME);
    $m->addReplyTo((string)$data['applicantEmail'], (string)$data['applicantName']);
    $m->isHTML(true);
    $m->Subject = 'طلب مشروع جديد - ' . $data['projectName'];

    $skip = ['savedAt','storagePath','attachments','sequence'];
    $rows = '';
    foreach ($data as $k => $v) {
        if (in_array($k, $skip, true)) continue;
        if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        if (is_bool($v))  $v = $v ? 'نعم' : 'لا';
        $rows .= '<tr>'
            . '<td style="border:1px solid #ddd;padding:6px;font-weight:bold;background:#f6f8fb">'
            . htmlspecialchars((string)$k) . '</td>'
            . '<td style="border:1px solid #ddd;padding:6px">'
            . nl2br(htmlspecialchars((string)$v)) . '</td></tr>';
    }
    $m->Body = "
        <div dir='rtl' style='font-family:Tahoma,Arial,sans-serif;font-size:13px'>
            <h3>طلب مشروع تقني جديد</h3>
            <p>الرقم المرجعي: <strong>$reference</strong></p>
            <p>مسار الحفظ: <strong>$relPath</strong></p>
            <table style='border-collapse:collapse;width:100%'>$rows</table>
        </div>";
    $m->AltBody = "طلب جديد: {$data['projectName']} — المسار: $relPath";

    $m->addAttachment($jsonPath, "$fileStem.json");
    foreach ($savedFiles as $f) {
        $m->addAttachment($f, basename($f));
    }
    $m->send();
} catch (Exception $e) {
    $emailErrors[] = 'IT owner email: ' . ($m->ErrorInfo ?? $e->getMessage());
}

// ---- 7. Respond (request is already saved; email errors are non-fatal) -------
echo json_encode([
    'ok'          => true,
    'reference'   => $reference,
    'path'        => $relPath,
    'sequence'    => $seqStr,
    'files'       => array_map('basename', $savedFiles),
    'emailErrors' => $emailErrors,
], JSON_UNESCAPED_UNICODE);
