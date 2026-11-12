<?php
/**
 * Fitosauna - Latvian landing page lead handler.
 * Emails every form submission to LEAD_TO and keeps a backup copy in a CSV file
 * one folder above public_html (not reachable from the web).
 */
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

// Spam trap: real visitors never see or fill this field.
if (!empty($_POST['company'])) respond(true);

$name  = clean($_POST['name']  ?? '', 100);
$phone = clean($_POST['phone'] ?? '', 20);
$email = clean($_POST['email'] ?? '', 150);
$page  = clean($_POST['page']  ?? '', 300);

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
    $saved = fputcsv($fh, [$when, $name, $phone, $email, $page, $ip]) !== false;
    fclose($fh);
}

$subject = 'Jauns pieteikums (LV piedāvājums): ' . $name;
$body = "Jauns pieteikums no Latvijas piedāvājuma lapas\n"
      . "==============================================\n\n"
      . "Vārds:    $name\n"
      . "Tālrunis: $phone\n"
      . "E-pasts:  $email\n\n"
      . "Laiks:    $when\n"
      . "Lapa:     $page\n\n"
      . "Atbildiet uz šo e-pastu, lai rakstītu klientam tieši.\n";
$headers = [
    'From: =?UTF-8?B?' . base64_encode(FROM_NAME) . '?= <' . LEAD_FROM . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
];
$sent = @mail(LEAD_TO, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers), '-f' . LEAD_FROM);

if (!$sent && !$saved) respond(false, 500);
respond(true);
