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
$cacheTTL  = 1000 * 24 * 3600;

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


// ── Requête pour récupérer les jumeaux avec toutes les informations
$sql = "SELECT e.id, e.label, e.description, e.sitelinks, e.statements, e.externalIds, e.wikipedia
, pNait.value_str
, pIma.value_str ima
, GROUP_CONCAT(pOccu.value_id) gOccu
, COUNT(DISTINCT pInf.entity_id) nbInflu
, (e.sitelinks + e.statements + e.externalIds) * (COUNT(DISTINCT pInf.entity_id)+1) importance
, g.label lieu, g.latitude, g.longitude 
FROM wikidata_entities e
INNER JOIN  wikidata_properties pNait ON pNait.entity_id = e.id and pNait.property = 'P569'
LEFT JOIN  wikidata_properties pInf ON pInf.value_id = e.id and pInf.property = 'P737'
LEFT JOIN  wikidata_properties pOccu ON pOccu.entity_id = e.id and pOccu.property = 'P106'
LEFT JOIN  wikidata_properties pIma ON pIma.entity_id = e.id and pIma.property = 'P18'
LEFT JOIN  wikidata_properties pGeo ON pGeo.entity_id = e.id and pGeo.property = 'P19'
LEFT JOIN  wikidata_geos g ON g.id = pGeo.value_id
WHERE SUBSTR(pNait.value_str, 7, 5) = ?
GROUP BY e.id
ORDER BY importance DESC";

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
//enregistre les occupations
$occupations = [];
foreach ($rows as $row) {
    $qid         = $row['id'];
    $label       = $row['label'];
    $description = $row['description'];
    $wikipedia    = $row['wikipedia'] ? explode("_",$row['wikipedia']) : '';
    $article     = $wikipedia ? $wikipedia[1] : "";
    $wikilang     = $wikipedia ? $wikipedia[0] : "";
    $valueStr    = $row['value_str']  ?? '';
    $importance  = intval($row['importance'] ?? 0);
    $lieu  = $row['lieu'] ?? "";
    $lat  = $row['latitude'];
    $lng  = $row['longitude'];
    $ima  = $row['ima'] ?? "";
    $occus    = $row['gOccu'] ? explode(",",$row['gOccu']) : [];
    foreach ($occus as $o) {
        if(isset($occupations[$o]))$occupations[$o]++;
        else $occupations[$o] = 1;
    }

    // Extrait l'année depuis value_str (format attendu : "YYYY-MM-DD...")
    $year = intval(substr($valueStr, 1, 4));

    $results[] = [
        'id' => $qid, 
        'label' => $label, 
        'description' => $description,
        'articlename'     => $article,
        'wikilang'        => $wikilang,
        'year'            => $year,
        'importance' => $importance,
        'lieu' => $lieu,
        'lat' => $lat,
        'lng' => $lng,
        'image' => $ima,
        'occus' => $occus
    ];
}
//récupère les informations pour les occupations
$sql = 'SELECT 
o.id, o.label
, GROUP_CONCAT(DISTINCT subclass.id) gClassId
, GROUP_CONCAT(DISTINCT n.label) gClassLabel 
FROM
(SELECT
	DISTINCT sub.subclass id
FROM wikidata_occupations o
INNER JOIN  wikidata_properties pOccu ON pOccu.value_id = o.id and pOccu.property = "P106"
INNER JOIN  wikidata_properties pNait ON pNait.entity_id = pOccu.entity_id and pNait.property = "P569" AND SUBSTR(pNait.value_str, 7, 5) = ?
JOIN JSON_TABLE(
  CONCAT(\'["\', REPLACE(o.subclass_of, \'|\', \'","\'), \'"]\'),
  \'$[*]\' COLUMNS (subclass VARCHAR(100) PATH \'$\')
) sub) subclass
INNER JOIN wikidata_occupations o ON FIND_IN_SET(subclass.id, REPLACE(o.subclass_of, \'|\', \',\')) > 0
INNER JOIN  wikidata_properties pOccu ON pOccu.value_id = o.id and pOccu.property = "P106"
INNER JOIN  wikidata_properties pNait ON pNait.entity_id = pOccu.entity_id and pNait.property = "P569" AND SUBSTR(pNait.value_str, 7, 5) = ?
LEFT JOIN wikidata_nodes n on n.id = subclass.id
GROUP BY o.id';
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$mmdd,$mmdd]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Requête : ' . $e->getMessage()]);
    exit;
}
//ajoute le poids des occupations
$nbRows = count($rows);
for ($i=0; $i < $nbRows; $i++) { 
    $r=$rows[$i];
    if(isset($occupations[$r["id"]])){
        $rows[$i]["poids"] = $occupations[$r["id"]];
    }else{ 
        $rows[$i]["poids"] = 1;
    }
}



$body = json_encode(["jumeaux"=>$results,"occupations"=>$rows], JSON_UNESCAPED_UNICODE);
file_put_contents($cacheFile, $body);
echo $body;

