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

//requête poru mettre à jour les influences
$upsert = $pdo->prepare(
    'INSERT INTO wikidata_influence (entity_id, influenced_count, sitelinks, statements, externalIds)
     VALUES (:id, :influ, :sitelinks, :statements, :externalIds)
     ON DUPLICATE KEY UPDATE influenced_count = VALUES(influenced_count),
       sitelinks = VALUES(sitelinks), statements = VALUES(statements),
       externalIds = VALUES(externalIds)'
);


// ── Requête pour récupérer les influences non calculées
$sql = "SELECT e.*, p.value_str, i.statements AS influence
        FROM wikidata_properties p
        INNER JOIN wikidata_entities e ON p.entity_id = e.id and p.property = 'P569'
        LEFT JOIN wikidata_influence i ON i.entity_id = e.id
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

//filtre les données non influencées
$todo = array_filter($rows, function($v) {
    return $v['influence'] == 0;
});

//lance le traitement de calcul de l'influence
$done = 0;
$total = count($todo);
foreach ($todo as $r) {
    $qid = $r["id"]; 
    if (!preg_match('/^Q\d+$/', $qid)) continue;

    $url = 'https://query.wikidata.org/sparql?' . http_build_query([
        'query'  => personQuery($qid),
        'format' => 'json',
    ]);
    $ctx = stream_context_create(['http' => [
        'header'  => "Accept: application/sparql-results+json\r\nUser-Agent: mesJumeaux/1.0 (local import script)\r\n",
        'timeout' => 30,
    ]]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        continue;
    }

    $data = json_decode($raw, true);
    $row  = $data['results']['bindings'][0] ?? null;
    if (!$row) {
        continue;
    }

    $sitelinks   = intval($row['sitelinks']['value'] ?? 0);
    $statements  = intval($row['statements']['value'] ?? 0);
    $externalIds = intval($row['externalIds']['value'] ?? 0);
    $nbInflu     = intval($row['nbInflu']['value'] ?? 0);

    $upsert->execute([
        'id'          => $qid,
        'influ'       => $nbInflu,
        'sitelinks'   => $sitelinks,
        'statements'  => $statements,
        'externalIds' => $externalIds,
    ]);
    $done++;
    //echo "  [$done/$total] $qid : sitelinks=$sitelinks statements=$statements externalIds=$externalIds influencés=$nbInflu\n";

    // Pause courte entre deux requêtes pour ne pas marteler le endpoint public.
    usleep(300000);
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
    $influenced  = intval($row['influenced_count'] ?? 0);

    // Extrait l'année depuis value_str (format attendu : "YYYY-MM-DD...")
    $year = intval(substr($valueStr, 1, 4));

    $results[] = [
        'item'            => ['value' => $qid, 'label' => $label, 'description' => $description],
        'articlename'     => $article,
        'wikilang'        => $wikilang,
        'year'            => $year,
        'influencedCount' => $influenced,
    ];
}

$body = json_encode($results, JSON_UNESCAPED_UNICODE);
file_put_contents($cacheFile, $body);
echo $body;

function personQuery(string $qid): string
{
    return <<<SPARQL
        SELECT ?sitelinks ?statements ?externalIds ?nbInflu WHERE {
          BIND(wd:$qid AS ?person)

          # 1. Nombre de sitelinks (liens interlangues Wikipédia/Wikimedia)
          ?person wikibase:sitelinks ?sitelinks .

          # 2. Nombre total de déclarations (statements)
          ?person wikibase:statements ?statements .

          # 3. Nombre d'identifiants externes
          {
            SELECT (COUNT(?extId) AS ?externalIds) WHERE {
              BIND(wd:$qid AS ?p)
              ?p ?prop ?extId .
              ?property wikibase:directClaim ?prop ;
                        wikibase:propertyType wikibase:ExternalId .
            }
          }

          # 4. Nombre de personnes influencées par cette personne (P737 inversé)
          {
            SELECT (COUNT(?influenced) AS ?nbInflu) WHERE {
              BIND(wd:$qid AS ?p)
              ?influenced wdt:P737 ?p .
            }
          }
        }
        SPARQL;
}


