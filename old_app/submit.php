<?php
// submit.php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json; charset=utf-8');

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed', 405);
}

// ---- 1. Read the JSON payload (sent as a form field named "payload") ----
$raw = $_POST['payload'] ?? '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    fail('Invalid or missing payload');
}

// Basic required-field validation
foreach (['projectName', 'requestType', 'requesterName', 'requesterEmail'] as $req) {
    if (empty($data[$req])) {
        fail("Missing required field: $req");
    }
}

// ---- 2. Build the folder path: [YEAR]/[MONTH]/[requestType]/[requesterName]/[SEQ] ----
$baseDir = __DIR__ . '/requests';

// Sanitize path segments so they can't escape the base dir
function segment(string $s): string {
    $s = trim($s);
    // keep Arabic + word chars, replace the rest with underscore
    $s = preg_replace('/[^\p{Arabic}\w\-]+/u', '_', $s);
    $s = trim($s, '_');
    return $s === '' ? 'unknown' : $s;
}

$now   = new DateTime('now');
$year  = $now->format('Y');
$month = $now->format('m');
$type  = segment((string)$data['requestType']);
$name  = segment((string)$data['requesterName']);

$parent = "$baseDir/$year/$month/$type/$name";
if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
    fail('Could not create request directory', 500);
}

// ---- 3. Determine next sequence number (01, 02, ...) ----
$seq = 1;
foreach (glob("$parent/*", GLOB_ONLYDIR) ?: [] as $existing) {
    $base = basename($existing);
    if (ctype_digit($base)) {
        $seq = max($seq, ((int)$base) + 1);
    }
}
$seqStr  = str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
$reqDir  = "$parent/$seqStr";

if (!mkdir($reqDir, 0775, true) && !is_dir($reqDir)) {
    fail('Could not create sequence directory', 500);
}

$relPath = "$year/$month/$type/$name/$seqStr";

// ---- 4. Save the JSON file ----
$data['savedAt']    = $now->format(DateTime::ATOM);
$data['storagePath'] = $relPath;

$jsonPath = "$reqDir/request.json";
if (file_put_contents(
        $jsonPath,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    ) === false) {
    fail('Could not write request.json', 500);
}

// ---- 5. Save uploaded files (input name="attachments[]") ----
$savedFiles = [];
if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
    $files   = $_FILES['attachments'];
    $count   = count($files['name']);
    $maxSize = 15 * 1024 * 1024; // 15 MB per file
    $allowed = [
        'pdf','doc','docx','xls','xlsx','csv','txt',
        'png','jpg','jpeg','gif','zip','json'
    ];

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

        // safe, unique-ish filename, preserve original label
        $safe = segment(pathinfo($orig, PATHINFO_FILENAME)) . '.' . $ext;
        $dest = "$reqDir/$safe";
        $n = 1;
        while (file_exists($dest)) {
            $safe = segment(pathinfo($orig, PATHINFO_FILENAME)) . "_$n.$ext";
            $dest = "$reqDir/$safe";
            $n++;
        }

        if (!move_uploaded_file($files['tmp_name'][$i], $dest)) {
            fail('Could not save uploaded file: ' . $orig);
        }
        $savedFiles[] = $dest;
    }
}

// ---- 6. Send the two emails ----
function makeMailer(): PHPMailer {
    $mail = new PHPMailer(true);
    // --- MailHog (local SMTP catcher) settings ---
    // MailHog listens on 127.0.0.1:1025, requires no auth and no encryption.
    // View captured mail at http://127.0.0.1:8025
    $mail->isSMTP();
    $mail->Host       = '127.0.0.1';
    $mail->Port       = 1025;
    $mail->SMTPAuth   = false;
    $mail->SMTPSecure = '';            // no TLS/SSL for MailHog
    $mail->SMTPAutoTLS = false;        // don't auto-upgrade to TLS
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom('no-reply@iarda.gov.sa', 'IT Project Requests');
    return $mail;
}

$emailErrors = [];

// (a) Thank-you email to the requester
try {
    $m = makeMailer();
    $m->addAddress((string)$data['requesterEmail'], (string)$data['requesterName']);
    $m->isHTML(true);
    $m->Subject = 'تم استلام طلبك - ' . $data['projectName'];
    $m->Body = "
        <div dir='rtl' style='font-family:Tahoma,Arial,sans-serif;font-size:14px;line-height:1.9'>
            <p>عزيزي/عزيزتي " . htmlspecialchars((string)$data['requesterName']) . "،</p>
            <p>نشكرك على تقديم طلبك <strong>«" . htmlspecialchars((string)$data['projectName']) . "»</strong>.</p>
            <p>نود إعلامك بأنه قد تم <strong>استلام طلبك بنجاح</strong> وسيتم مراجعته من قبل إدارة تقنية المعلومات، وسنوافيك بالمستجدات.</p>
            <p>نوع الطلب: " . htmlspecialchars((string)$data['requestType']) . "<br>
               الرقم المرجعي: " . htmlspecialchars($relPath) . "</p>
            <p>مع خالص التقدير،<br>إدارة تقنية المعلومات</p>
        </div>";
    $m->AltBody = "تم استلام طلبك: {$data['projectName']}. سيتم مراجعته والرد عليك.";
    $m->send();
} catch (Exception $e) {
    $emailErrors[] = 'requester email: ' . $m->ErrorInfo;
}

// (b) Full-details email to admin, with JSON + attachments
try {
    $m = makeMailer();
    $m->addAddress('sami@gmail.com', 'Sami Mansour');
    $m->isHTML(true);
    $m->Subject = 'طلب مشروع جديد - ' . $data['projectName'];

    // Build an HTML table of all details
    $rows = '';
    foreach ($data as $k => $v) {
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
            <p>مسار الحفظ: <strong>$relPath</strong></p>
            <table style='border-collapse:collapse;width:100%'>$rows</table>
        </div>";
    $m->AltBody = "طلب جديد: {$data['projectName']} — المسار: $relPath";

    // Attach the JSON and any uploaded files
    $m->addAttachment($jsonPath, 'request.json');
    foreach ($savedFiles as $f) {
        $m->addAttachment($f, basename($f));
    }
    $m->send();
} catch (Exception $e) {
    $emailErrors[] = 'admin email: ' . $m->ErrorInfo;
}

// ---- 7. Respond ----
echo json_encode([
    'ok'          => true,
    'path'        => $relPath,
    'sequence'    => $seqStr,
    'files'       => array_map('basename', $savedFiles),
    'emailErrors' => $emailErrors, // non-fatal; request is already saved
], JSON_UNESCAPED_UNICODE);