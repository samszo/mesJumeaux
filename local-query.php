<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ── Paramètres ────────────────────────────────────────────────────────────
$day   = intval($_GET['day']   ?? 0);
$month = intval($_GET['month'] ?? 0);

if (!$day || !$month || $day < 1 || $day > 31 || $month < 1 || $month > 12) {
    http_response_code(400);
    echo json_encode(['error' => 'Paramètres day et month requis (entiers valides)']);
    exit;
}

$mmdd = sprintf('%02d-%02d', $month, $day);

// ── Cache ─────────────────────────────────────────────────────────────────
$cacheDir  = __DIR__ . '/cache';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

$cacheFile = $cacheDir . '/local-' . $mmdd . '.json';
$cacheTTL  = 7 * 24 * 3600;

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
    header('X-Cache: HIT');
    readfile($cacheFile);
    exit;
}
header('X-Cache: MISS');

try {
    $cfg = require __DIR__ . '/config.php';
    $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset={$cfg['charset']}";
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Connexion BDD : ' . $e->getMessage()]);
    exit;
}

// ── Requête ───────────────────────────────────────────────────────────────
$sql = "SELECT e.*, p.value_str
        FROM wikidata_properties p
        INNER JOIN wikidata_entities e ON p.entity_id = e.id and p.property = 'P569' 
        WHERE SUBSTR(p.value_str, 7, 5) = ?
        ORDER BY p.value_str DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$mmdd]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Requête : ' . $e->getMessage()]);
    exit;
}

// ── Normalisation → format attendu par le JS ──────────────────────────────
// Colonnes supposées dans wikidata_entities :
//   entity_id (ex: "Q123"), label, description, article (sitelink fr)
// Adaptez les noms de colonnes ci-dessous si nécessaire.
$results = [];
foreach ($rows as $row) {
    $qid         = $row['id'];
    $label       = $row['label'];
    $description = $row['description'];
    $wikipedia    = $row['wikipedia'] ? explode("_",$row['wikipedia']) : '';
    $article     = $wikipedia ? $wikipedia[1] : "";
    $wikilang     = $wikipedia ? $wikipedia[0] : "";
    $valueStr    = $row['value_str']  ?? '';

    // Extrait l'année depuis value_str (format attendu : "YYYY-MM-DD...")
    $year = intval(substr($valueStr, 1, 4));

    $results[] = [
        'item'        => ['value' => $qid, 'label' => $label, 'description' => $description],
        'articlename' => $article,
        'wikilang'    => $wikilang,
        'year'        => $year,
    ];
}

$body = json_encode($results, JSON_UNESCAPED_UNICODE);
file_put_contents($cacheFile, $body);
echo $body;
