<?php
/**
 * Fitosauna LV landing page - spin-the-wheel.
 * The prize is drawn here on the server (not in the browser), saved to a CSV one folder above
 * public_html, and stored on the visitor's Klaviyo profile ("LV LP prize") in the LV wheel list.
 * One prize per email: spinning again with the same email returns the same prize.
 */
require __DIR__ . '/klaviyo.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false], 405);

$email = strtolower(trim(mb_substr((string) ($_POST['email'] ?? ''), 0, 150)));
// Spam trap (hidden field): pretend it worked, store nothing.
if (!empty($_POST['company'])) out(['ok' => true, 'prize' => 'tea']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(['ok' => false], 400);

// Same email = same prize, no second draw.
$existing = lv_prize_for_email($email);
if ($existing !== null) out(['ok' => true, 'prize' => $existing, 'repeat' => true]);

// Flood protection: max 5 spins per IP per 10 minutes.
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$rl = sys_get_temp_dir() . '/fitosauna-lv-spin-' . md5($ip);
$hits = array_filter(array_map('intval', @file($rl, FILE_IGNORE_NEW_LINES) ?: []), function ($t) { return $t > time() - 600; });
if (count($hits) >= 5) out(['ok' => false], 429);
$hits[] = time();
@file_put_contents($rl, implode("\n", $hits), LOCK_EX);

$prize = lv_draw_prize();
$when = (new DateTime('now', new DateTimeZone('Europe/Riga')))->format('d.m.Y H:i');
if ($fh = @fopen(lv_wheel_csv(), 'a')) {
    fputcsv($fh, [$when, $email, $prize, $ip]);
    fclose($fh);
}

// Reply first, then store the prize in Klaviyo.
http_response_code(200);
echo json_encode(['ok' => true, 'prize' => $prize]);
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

klaviyo_subscribe(KLAVIYO_LIST_LV_WHEEL, $email, [], [
    'LV LP prize' => $prize,
    'LV LP prize label' => LV_PRIZES[$prize],
    'LV LP prize date' => $when,
], 'LV LP - spin wheel');
exit;
