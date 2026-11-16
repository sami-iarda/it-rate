<?php
// admin/api.php — backend for the IT admin dashboard (new request format)
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../mailer.php';

use PHPMailer\PHPMailer\Exception;

session_start();
header('Content-Type: application/json; charset=utf-8');

// ---- Admin credentials (values live in .env — see .env.example) --------------
/**
 * Admin accounts, read from ADMIN_EMAIL/ADMIN_PASS_HASH plus the numbered
 * pairs ADMIN_EMAIL_2/ADMIN_PASS_HASH_2, _3, … (stops at the first gap).
 *
 * @return array<string,string> lowercased email => bcrypt hash
 */
function adminUsers(): array {
    $users = [strtolower(env_required('ADMIN_EMAIL')) => env_required('ADMIN_PASS_HASH')];
    for ($i = 2; env("ADMIN_EMAIL_$i") !== null; $i++) {
        $users[strtolower(env_required("ADMIN_EMAIL_$i"))] = env_required("ADMIN_PASS_HASH_$i");
    }
    return $users;
}

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
        $hash  = adminUsers()[strtolower($email)] ?? null;
        if ($hash !== null && password_verify($pass, $hash)) {
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
                    'managerName'    => $json['managerName']    ?? '',
                    'applicantName'  => $json['applicantName']  ?? '',
                    'applicantEmail' => $json['applicantEmail'] ?? '',
                    'requestType'    => $json['requestType']    ?? '',
                    'hasSupportingDepartments' => $json['hasSupportingDepartments'] ?? '',
                    'hasExternalBeneficiary'   => $json['hasExternalBeneficiary']   ?? '',
                    'beneficiariesCount'       => $json['beneficiariesCount']       ?? '',
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

                // Detailed notes/justifications
                $detailNotes = '';
                $noteLabels = [
                    'automateNotes' => 'مبرر آتمتة الخدمة',
                    'stOneNotes'    => 'مبرر دعم هدف استراتيجي واحد',
                    'stMultiNotes'  => 'مبرر دعم عدة أهداف استراتيجية',
                    'tc_infra1Notes'=> 'مبرر توفر البنية التحتية',
                    'tc_infra2Notes'=> 'مبرر توفر الأنظمة الداعمة',
                    'tc_infra3Notes'=> 'مبرر توفر الموارد التقنية',
                    'tc_f1Notes'    => 'مبرر التوافق مع المعمارية',
                    'tc_f2Notes'    => 'مبرر عدم التعارض مع الأنظمة',
                    'tc_f3Notes'    => 'مبرر إمكانية التنفيذ بالموارد',
                    'tc_f4Notes'    => 'مبرر توفر البيانات',
                    'tc_f5Notes'    => 'مبرر إمكانية التكامل',
                    'rk_impactNotes'=> 'مبرر التأثير على الأنظمة',
                    'rk_levelNotes' => 'مبرر المخاطر التقنية'
                ];
                foreach ($noteLabels as $key => $label) {
                    if (!empty($eval[$key])) {
                        $detailNotes .= "<p><strong>$label:</strong><br>" . nl2br(htmlspecialchars((string)$eval[$key])) . "</p>";
                    }
                }

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
                            $detailNotes
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

    // -------------------------------------------------------------- reject
    // Reject the request due to missing gate items and email the applicant.
    case 'reject': {
        requireAuth();
        $file = resolveReqFile((string)($_POST['file'] ?? ''));
        $json = json_decode((string)file_get_contents($file), true);
        if (!is_array($json)) fail('ملف الطلب تالف', 500);

        $evalRaw = (string)($_POST['evaluation'] ?? '');
        $eval    = json_decode($evalRaw, true);
        if (!is_array($eval)) fail('بيانات التقييم غير صالحة', 400);

        $now  = (new DateTime('now'))->format(DateTime::ATOM);

        $json['evaluation']  = $eval;
        $json['adminStatus'] = 'rejected';
        $json['rejectedAt']  = $now;

        $emailError = null;
        $to = (string)($json['applicantEmail'] ?? '');
        if ($to === '') {
            $emailError = 'لا يوجد بريد للمتقدّم';
        } else {
            try {
                $m = makeMailer((string)env('ADMIN_MAIL_FROM_NAME', ''));
                $m->addAddress($to, (string)($json['applicantName'] ?? ''));
                $m->isHTML(true);
                $m->Subject = 'تحديث بشأن طلبك - ' . (string)($json['projectName'] ?? '');
                
                $noItemsStr = "";
                $gateItems = [
                    "وضوح أهداف الإدارة الطالبة ومواءمتها مع الأهداف الاستراتيجية",
                    "وجود مالك المشروع (Project Owner) محدد",
                    "توفر فريق داعم من الإدارة الطالبة",
                    "الاستعداد للمشاركة في مراحل تحليل المتطلبات والاختبارات والإطلاق",
                    "توضيح الإجراءات الحالية (As-Is) والمستهدفة (To-Be) المعتمدة",
                    "إقرار رسمي من الإدارة الطالبة بصحة واكتمال البيانات والمعلومات المقدمة",
                ];
                if (isset($eval['gate']['answers'])) {
                    foreach ($eval['gate']['answers'] as $k => $v) {
                        if ($v === 'لا') {
                            $idx = (int)str_replace('gate_', '', $k);
                            $noItemsStr .= "<li>" . htmlspecialchars($gateItems[$idx] ?? $k) . "</li>";
                        }
                    }
                }

                $m->Body = "
                    <div dir='rtl' style='font-family:Tahoma,Arial,sans-serif;font-size:14px;line-height:1.9'>
                        <p>عزيزي/عزيزتي " . htmlspecialchars((string)($json['applicantName'] ?? '')) . "،</p>
                        <p>نشكركم على تقديم طلب <strong>«" . htmlspecialchars((string)($json['projectName'] ?? '')) . "»</strong> (الرقم المرجعي: " . htmlspecialchars((string)($json['reference'] ?? '')) . ").</p>
                        <p>يؤسفنا إبلاغكم بأنه تعذر قبول الطلب في المرحلة الحالية نظراً لعدم استيفاء بعض المتطلبات الأساسية في بوابة اكتمال الطلب:</p>
                        <ul>$noItemsStr</ul>
                        <p>يمكنكم إعادة تقديم الطلب بعد استكمال العناصر المذكورة أعلاه.</p>
                        <p>مع خالص التقدير،<br>إدارة تقنية المعلومات</p>
                    </div>";
                $m->AltBody = "تحديث بشأن طلبك ({$json['projectName']}): الطلب مرفوض لعدم اكتمال المتطلبات الأساسية.";
                $m->send();
                $json['resultSentAt'] = $now;
            } catch (Exception $e) {
                $emailError = $m->ErrorInfo ?? $e->getMessage();
            }
        }

        if (file_put_contents($file, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
            fail('تعذّر حفظ الطلب', 500);
        }
        out(['ok' => true, 'emailSent' => $emailError === null, 'emailError' => $emailError, 'data' => $json]);
    }

    // -------------------------------------------------------------- delete
    case 'delete': {
        requireAuth();
        $rel  = (string)($_POST['file'] ?? '');
        $file = resolveReqFile($rel);
        
        $dir  = dirname($file);
        $stem = basename($file, '.json');
        
        // Delete the JSON file and all attachments (they start with the same stem)
        $filesToDelete = glob("$dir/{$stem}*");
        if ($filesToDelete) {
            foreach ($filesToDelete as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }

        // Clean up empty parent directories up to REQUESTS_BASE
        $base = realpath(REQUESTS_BASE);
        $current = $dir;
        while ($current && $current !== $base && strncmp($current, $base, strlen($base)) === 0) {
            // Check if directory is empty (ignoring . and ..)
            $files = scandir($current);
            if (count($files) <= 2) { // only . and ..
                if (@rmdir($current)) {
                    $current = dirname($current);
                } else {
                    break;
                }
            } else {
                break;
            }
        }

        // Also check if .serials directory exists and is empty
        $serialsDir = $base . '/.serials';
        if (is_dir($serialsDir)) {
            // Only delete the whole folder if there are no requests left at all
            $allRequests = glob(REQUESTS_BASE . '/*/*/*/*.json');
            if (empty($allRequests)) {
                // Delete all .seq files and then the folder
                $seqFiles = glob("$serialsDir/*.seq");
                if ($seqFiles) {
                    foreach ($seqFiles as $sf) @unlink($sf);
                }
                @rmdir($serialsDir);
            }
        }
        
        out(['ok' => true]);
    }

    default:
        fail('إجراء غير معروف', 404);
}
