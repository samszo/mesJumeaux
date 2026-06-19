<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$query = $_POST['query'] ?? $_GET['query'] ?? '';
if (!$query) {
    http_response_code(400);
    echo json_encode(['error' => 'Paramètre query manquant']);
    exit;
}

// ── Cache ──────────────────────────────────────────────────────────────────
$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

$cacheFile = $cacheDir . '/' . md5($query) . '.json';
$cacheTTL  = 7 * 24 * 3600; // 7 jours

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
    header('X-Cache: HIT');
    readfile($cacheFile);
    exit;
}
header('X-Cache: MISS');
// ──────────────────────────────────────────────────────────────────────────

$headers    = ['Accept: application/sparql-results+json'];
$url        = 'https://query.wikidata.org/sparql';
$postFields = http_build_query(['query' => $query]);

$req = curl_init();
curl_setopt($req, CURLOPT_URL,            $url);
curl_setopt($req, CURLOPT_POSTFIELDS,     $postFields);
curl_setopt($req, CURLOPT_USERAGENT,      'MesJumeaux/1.0');
curl_setopt($req, CURLOPT_HTTPHEADER,     $headers);
curl_setopt($req, CURLOPT_RETURNTRANSFER, true);
curl_setopt($req, CURLOPT_TIMEOUT,        30);

$body   = curl_exec($req);
$status = curl_getinfo($req, CURLINFO_HTTP_CODE);
$error  = curl_error($req);

if ($error) {
    http_response_code(502);
    echo json_encode(['error' => 'cURL : ' . $error]);
    exit;
}

if ($status === 200 && $body) {
    file_put_contents($cacheFile, $body);
}

http_response_code($status);
echo $body;
