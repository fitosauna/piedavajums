<?php
/**
 * Fitosauna LV landing page - shared helpers for Klaviyo and the spin-the-wheel.
 * Included by send-lead.php and spin.php. Opening this file directly does nothing.
 *
 * Klaviyo is called through its public "client" subscription API (company ID only,
 * no private key needed on the server). Every call is fail-safe: if Klaviyo is slow
 * or down, the lead is still emailed and saved to CSV as before.
 */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

const KLAVIYO_COMPANY_ID = 'S7WMfm';
const KLAVIYO_LIST_LV_LEADS = 'XvrbFv';  // "LV LP - piedavajums.fitosauna.com leads" -> triggers LV Email 1 flow
const KLAVIYO_LIST_LV_WHEEL = 'RKXZxQ';  // "LV LP - spin wheel (piedavajums)" -> no flow, only stores the prize
const KLAVIYO_REVISION = '2024-10-15';

// Wheel prizes. Keys are what Klaviyo stores in the profile property "LV LP prize"
// and what the LV Email 1 flow branches on. 'nospin' = lead never spun the wheel.
const LV_PRIZES = [
    '150'  => '€150 papildu atlaide',
    '100'  => '€100 papildu atlaide',
    '50'   => '€50 papildu atlaide',
    'gift' => 'Slepenā dāvana - bezmaksas tvaika ģeneratora kaste',
];

function lv_wheel_csv() { return dirname(__DIR__) . '/lv-wheel-spins.csv'; }

/** Prize already won by this email (case-insensitive), or null. */
function lv_prize_for_email($email) {
    $email = strtolower(trim($email));
    if ($email === '' || !($fh = @fopen(lv_wheel_csv(), 'r'))) return null;
    $found = null;
    while (($row = fgetcsv($fh)) !== false) {
        if (isset($row[1], $row[2]) && strtolower($row[1]) === $email && isset(LV_PRIZES[$row[2]])) $found = $row[2];
    }
    fclose($fh);
    return $found;
}

/** Same odds as the Irish wheel: 33% each for €150 / €100 / €50, 1% secret gift. */
function lv_draw_prize() {
    $n = random_int(1, 100);
    if ($n <= 33) return '150';
    if ($n <= 66) return '100';
    if ($n <= 99) return '50';
    return 'gift';
}

/**
 * Subscribe an email to a Klaviyo list (email marketing consent) and set profile fields.
 * $profile: extra profile attributes (first_name, last_name, phone_number).
 * Returns true on success. Never throws.
 */
function klaviyo_subscribe($listId, $email, array $profile, array $properties, $source) {
    if (!function_exists('curl_init')) { error_log('[klaviyo] curl missing'); return false; }
    $attrs = array_filter($profile, function ($v) { return $v !== null && $v !== ''; });
    $attrs['email'] = $email;
    $attrs['properties'] = (object) $properties;
    $body = ['data' => [
        'type' => 'subscription',
        'attributes' => [
            'custom_source' => $source,
            'profile' => ['data' => ['type' => 'profile', 'attributes' => $attrs]],
        ],
        'relationships' => ['list' => ['data' => ['type' => 'list', 'id' => $listId]]],
    ]];
    $url = (getenv('KLAVIYO_ENDPOINT') ?: 'https://a.klaviyo.com/client/subscriptions') . '?company_id=' . KLAVIYO_COMPANY_ID;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/vnd.api+json', 'Accept: application/vnd.api+json', 'revision: ' . KLAVIYO_REVISION],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($code >= 200 && $code < 300) return true;
    // A bad phone number makes Klaviyo reject the whole request: retry once without it.
    if ($code === 400 && isset($profile['phone_number']) && stripos((string) $resp, 'phone') !== false) {
        unset($profile['phone_number']);
        $properties['LV LP phone'] = $attrs['phone_number'] ?? '';
        return klaviyo_subscribe($listId, $email, $profile, $properties, $source);
    }
    error_log('[klaviyo] subscribe failed list=' . $listId . ' code=' . $code . ' err=' . $err . ' resp=' . substr((string) $resp, 0, 300));
    return false;
}
