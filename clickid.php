<?php
// clickid.php — fetch RedTrack clickid once per session via ?format=json and return JSON
// Call from JS: fetch('/clickid.php', { method:'POST', credentials:'include', body: new URLSearchParams({ qs: location.search, fbp: getCookie('_fbp'), fbc: getCookie('_fbc') }) })

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* --- Config --- */
$cmpId = "686521af0ee01d9295078a90";

const SESSION_KEY  = 'rt_clickid';
const SESSION_TTL  = 6 * 3600;                // 6h cache
const RT_BASE      = 'https://dx8jy.ttrk.io';
const COOKIE_NAME  = 'rtkclickid-store';      // parity with RT JS

/* --- Headers / CORS --- */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

/* --- Inputs --- */
$referrer = $_SERVER['HTTP_REFERER'] ?? '';   // full current page URL (ensure Referrer-Policy allows query)

/* --- Domain Detection Logic --- */
$monitoredDomains = [
  "adspy.com",
  "bigspy.com",
  "minea.com",
  "adspyder.io",
  "adflex.io",
  "poweradspy.com",
  "dropispy.com",
  "socialpeta.com",
  "adstransparency.google.com",
  "facebook.com/ads/library",
  "adbeat.com",
  "anstrex.com",
  "semrush.com",
  "autods.com",
  "foreplay.co",
  "spyfu.com",
  "adplexity.com",
  "spypush.com",
  "nativeadbuzz.com",
  "spyover.com",
  "videoadvault.com",
  "admobispy.com",
  "ispionage.com",
  "similarweb.com",
  "pipiads.com",
  "adespresso.com"
];
$originalPhoneNumber = '+13213980346';
$monitoringPhoneNumber = '+18335942920';

function isMonitoredDomain($referrer, $monitoredDomains)
{
  if (empty($referrer)) {
    return false;
  }

  $parsedUrl = parse_url($referrer);
  if (!$parsedUrl || !isset($parsedUrl['host'])) {
    return false;
  }

  $referrerHost = strtolower($parsedUrl['host']);
  $referrerPath = isset($parsedUrl['path']) ? strtolower($parsedUrl['path']) : '';
  $referrerFull = $referrerHost . $referrerPath;

  foreach ($monitoredDomains as $domain) {
    $domainLower = strtolower($domain);

    // Check if the domain matches exactly (for simple domains)
    if ($domainLower === $referrerHost) {
      return true;
    }

    // Check if the full domain+path matches (for complex domains like facebook.com/ads/library)
    if ($domainLower === $referrerFull) {
      return true;
    }

    // Check if the referrer starts with the monitored domain (for subdomain matching)
    if (strpos($referrerFull, $domainLower) === 0) {
      return true;
    }
  }

  return false;
}

$isMonitoredDomain = isMonitoredDomain($referrer, $monitoredDomains);

// Additional verification checks
$shouldUseMonitoringNumber = $isMonitoredDomain;

// Parse URL parameters from POST body if available
$urlParams = [];
if (isset($_POST['qs']) && !empty($_POST['qs'])) {
  parse_str($_POST['qs'], $urlParams);
}

// Check for sub6 parameter - if present, DO NOT use monitoring number
$hasSub6 = (isset($_GET['sub6']) && !empty($_GET['sub6'])) || (isset($urlParams['sub6']) && !empty($urlParams['sub6']));
if ($hasSub6) {
  $shouldUseMonitoringNumber = false;
}

// Check for key="X184GA" parameter - if NOT present, use monitoring number
$hasCorrectKey = (isset($_GET['key']) && $_GET['key'] === 'X184GA') || (isset($urlParams['key']) && $urlParams['key'] === 'X184GA');
if (!$hasCorrectKey) {
  $shouldUseMonitoringNumber = true;
}

$phoneNumber = $shouldUseMonitoringNumber ? $monitoringPhoneNumber : $originalPhoneNumber;

// Debug logging (remove in production)
error_log("Domain Detection Debug - Referrer: " . $referrer);
error_log("Domain Detection Debug - Is Monitored Domain: " . ($isMonitoredDomain ? 'true' : 'false'));
error_log("Domain Detection Debug - URL Params from POST: " . json_encode($urlParams));
error_log("Domain Detection Debug - Has sub6: " . ($hasSub6 ? 'true' : 'false'));
error_log("Domain Detection Debug - Has correct key: " . ($hasCorrectKey ? 'true' : 'false'));
error_log("Domain Detection Debug - Final decision: " . ($shouldUseMonitoringNumber ? 'MONITORING' : 'ORIGINAL'));
error_log("Domain Detection Debug - Phone number: " . $phoneNumber);

/* --- Build mint URL (Variant A): encoded referrer + UTMs as separate params --- */
$rtUrl = RT_BASE . '/' . rawurlencode($cmpId) . '?format=json';

if ($referrer !== '') {
  // 1) encoded referrer
  $rtUrl .= '&referrer=' . rawurlencode($referrer);

  // 2) forward page query as top-level params (KEEP sub1..sub10; drop only noise)
  $qs = parse_url($referrer, PHP_URL_QUERY) ?: '';
  if ($qs !== '') {
    parse_str($qs, $params);

    // drop known noise only
    unset($params['cost'], $params['ref_id']);

    // IMPORTANT: do NOT unset sub1..sub10 — we want sub1
    $cleanQs = http_build_query($params);
    if ($cleanQs !== '') $rtUrl .= '&' . $cleanQs;
  }
}

/* --- Cache hit? --- */
$now = time();
if (!empty($_SESSION[SESSION_KEY]) && !empty($_SESSION[SESSION_KEY . '_ts']) && ($now - $_SESSION[SESSION_KEY . '_ts']) < SESSION_TTL) {
  echo json_encode([
    'ok'      => true,
    'clickid' => (string)$_SESSION[SESSION_KEY],
    'cached'  => true,
    'ref'     => $referrer,
    'mint_url' => null,
    'phone_number' => $phoneNumber
  ]);
  exit;
}

/* --- Mint clickid --- */
$ua       = $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0';
$clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');

$ch = curl_init($rtUrl);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_CONNECTTIMEOUT => 8,
  CURLOPT_TIMEOUT        => 15,
  CURLOPT_SSL_VERIFYPEER => true,
  CURLOPT_SSL_VERIFYHOST => 2,
  CURLOPT_USERAGENT      => $ua,
  CURLOPT_HTTPHEADER     => [
    'Accept: application/json',
    'X-Forwarded-For: ' . $clientIp,
    'X-Real-IP: ' . $clientIp,
  ],
]);
$body = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err || $code !== 200) {
  http_response_code(502);
  echo json_encode([
    'ok'    => false,
    'error' => 'RT request failed',
    'status' => $code,
    'detail' => $err,
    'url'   => $rtUrl,
    'ref'   => $referrer
  ]);
  exit;
}

$payload = json_decode($body, true);
$clickid = $payload['clickid'] ?? null;
if (!$clickid) {
  http_response_code(502);
  echo json_encode([
    'ok'    => false,
    'error' => 'No clickid in JSON',
    'url'   => $rtUrl,
    'raw'   => $payload,
    'ref'   => $referrer
  ]);
  exit;
}

/* --- Cache & cookie --- */
$_SESSION[SESSION_KEY] = $clickid;
$_SESSION[SESSION_KEY . '_ts'] = time();

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
setcookie(COOKIE_NAME, $clickid, [
  'expires'  => time() + 86400 * 30,
  'path'     => '/',
  'secure'   => $secure,
  'httponly' => false,   // RT JS reads it
  'samesite' => 'Lax',
]);

/* --- Return --- */
echo json_encode([
  'ok'      => true,
  'clickid' => $clickid,
  'cached'  => false,
  'ref'     => $referrer,
  'mint_url' => $rtUrl,   // helpful for debugging; remove if you prefer
  'phone_number' => $phoneNumber
]);
