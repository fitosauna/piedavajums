<?php
/**
 * Fitosauna - Latvian landing page lead handler.
 * Emails every form submission to LEAD_TO and keeps a backup copy in a CSV file
 * one folder above public_html (not reachable from the web), then adds the lead to the
 * Latvian Klaviyo list (triggers the LV Email 1 flow) with the spin-the-wheel prize, if any.
 */
require __DIR__ . '/klaviyo.php';

const LEAD_TO   = 'info@fitosauna.com';
const LEAD_FROM = 'noreply@fitosauna.com';
const FROM_NAME = 'Fitosauna LV piedāvājums';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond($ok, $code = 200) {
    http_response_code($code);
    echo json_encode(['ok' => $ok]);
    exit;
}
function clean($v, $max) {
    $v = trim((string)$v);
    $v = preg_replace('/[\r\n\t]+/', ' ', $v);           // no header injection / line breaks
    return mb_substr($v, 0, $max);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, 405);

// Spam trap: a hidden field real visitors never see. It is NOT used to drop the lead silently any more
// (browser autofill once filled the old "company" field and real leads were lost); a filled trap only
// marks the email as possible spam and keeps the lead out of Klaviyo. 'company' is still read for old cached pages.
$suspect = trim((string) ($_POST['fs_hp_check'] ?? '')) !== '' || trim((string) ($_POST['company'] ?? '')) !== '';

$name  = clean($_POST['name']  ?? '', 100);
$phone = clean($_POST['phone'] ?? '', 20);
$email = clean($_POST['email'] ?? '', 150);
$page  = clean($_POST['page']  ?? '', 300);
// Lead id generated in the browser; the same id is sent to GTM/Meta, so leads can be matched to conversions later.
$leadId = preg_replace('/[^A-Za-z0-9-]/', '', clean($_POST['lead_id'] ?? '', 64));
// Wheel prize: the server record for this email wins; otherwise the one the browser remembered
// (visitor may have spun with another email); otherwise 'nospin'.
$postedPrize = clean($_POST['prize'] ?? '', 10);
$prize = lv_prize_for_email($email) ?? (isset(LV_PRIZES[$postedPrize]) ? $postedPrize : 'nospin');
$prizeLabel = LV_PRIZES[$prize] ?? 'Nav griezis ratu';
// Where on the page the lead came from (shown in the email subject + body).
$sourceLabels = ['top' => 'augšējā forma', 'bottom' => 'apakšējā forma', 'wheel' => 'laimes rats'];
$source = clean($_POST['source'] ?? '', 10);
$sourceLabel = $sourceLabels[$source] ?? 'nav zināms';
// Same email already sent a lead before (e.g. claimed in the wheel pop-up, then filled the page form too).
$repeat = lv_lead_exists($email);

if ($name === '' || !preg_match('/^\+\d{8,15}$/', $phone) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 400);
}

// Basic flood protection: max 5 submissions per IP per 10 minutes.
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$rl = sys_get_temp_dir() . '/fitosauna-lv-' . md5($ip);
$hits = array_filter(array_map('intval', @file($rl, FILE_IGNORE_NEW_LINES) ?: []), function ($t) { return $t > time() - 600; });
if (count($hits) >= 5) respond(false, 429);
$hits[] = time();
@file_put_contents($rl, implode("\n", $hits), LOCK_EX);

$tz = new DateTimeZone('Europe/Riga');
$when = (new DateTime('now', $tz))->format('d.m.Y H:i');

// Backup copy (one folder above public_html, so it is not public).
$csv = dirname(__DIR__) . '/lv-leads.csv';
$saved = false;
if ($fh = @fopen($csv, 'a')) {
    $saved = fputcsv($fh, [$when, $name, $phone, $email, $page, $ip, $leadId, $prize, $source]) !== false;
    fclose($fh);
}

$subject = ($suspect ? '[IESPĒJAMS SPAMS] ' : '') . ($repeat ? 'Atkārtots pieteikums' : 'Jauns pieteikums') . ' (LV piedāvājums, ' . $sourceLabel . '): ' . $name;
$body = "Jauns pieteikums no Latvijas piedāvājuma lapas\n"
      . "==============================================\n\n"
      . "Vārds:    $name\n"
      . "Tālrunis: $phone\n"
      . "E-pasts:  $email\n"
      . "Avots:    " . mb_strtoupper(mb_substr($sourceLabel, 0, 1)) . mb_substr($sourceLabel, 1) . "\n"
      . "Dāvana:   $prizeLabel\n"
      . ($repeat ? "PIEZĪME:  šis e-pasts jau iepriekš atstāja pieteikumu.\n" : "")
      . "\n"
      . "Laiks:    $when\n"
      . "Lapa:     $page\n"
      . "Lead ID:  $leadId\n\n"
      . "Atbildiet uz šo e-pastu, lai rakstītu klientam tieši.\n";
$headers = [
    'From: =?UTF-8?B?' . base64_encode(FROM_NAME) . '?= <' . LEAD_FROM . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
];
$subjectPrize = $prize === 'nospin' ? '' : ' [' . $prizeLabel . ']';
$subject .= $subjectPrize;
$sent = @mail(LEAD_TO, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers), '-f' . LEAD_FROM);

if (!$sent && !$saved) respond(false, 500);

// Reply to the browser first, then talk to Klaviyo (so a slow Klaviyo never delays the form).
// Klaviyo errors only go to the PHP error log; the lead is already emailed + saved.
http_response_code(200);
echo json_encode(['ok' => true]);
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

if ($suspect) exit;  // possible bot: emailed to info@ above, but not added to Klaviyo
$parts = preg_split('/\s+/', $name, 2);
klaviyo_subscribe(KLAVIYO_LIST_LV_LEADS, strtolower($email), [
    'first_name'   => $parts[0] ?? '',
    'last_name'    => $parts[1] ?? '',
    'phone_number' => $phone,
], [
    'LV LP prize'   => $prize,
    'LV LP lead id' => $leadId,
    'LV LP page'    => $page,
    'LV LP lead date' => $when,
], 'LV LP - lead form');
exit;
