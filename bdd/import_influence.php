<?php
/**
 * Calcule l'indice d'influence (sitelinks, statements, externalIds, P737 inversé)
 * pour les personnes nées à une date donnée (jour/mois, tous ans confondus),
 * en n'interrogeant Wikidata que pour celles absentes de la table locale
 * wikidata_influence — les personnes déjà calculées ne sont pas refaites.
 *
 * Usage : php bdd/import_influence.php <jour> <mois>
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Ce script ne s'exécute qu'en CLI.\n");
}

$day   = intval($argv[1] ?? 0);
$month = intval($argv[2] ?? 0);
if (!$day || !$month || $day < 1 || $day > 31 || $month < 1 || $month > 12) {
    fwrite(STDERR, "Usage : php bdd/import_influence.php <jour> <mois>\n");
    exit(1);
}
$mmdd = sprintf('%02d-%02d', $month, $day);

// Verrou par date : évite deux exécutions concurrentes (ex : déclenchement web
// répété) qui dupliqueraient les appels SPARQL pour la même journée.
$cacheDir = __DIR__ . '/../cache';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
$lockHandle = fopen($cacheDir . "/influence-$mmdd.lock", 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "Un traitement est déjà en cours pour $mmdd, on quitte.\n";
    exit(0);
}

$cfg = require __DIR__ . '/../config.php';
$dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['dbname']};charset={$cfg['charset']}";
$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec("
    CREATE TABLE IF NOT EXISTS wikidata_influence (
      entity_id VARCHAR(20) NOT NULL,
      influenced_count INT UNSIGNED NOT NULL DEFAULT 0,
      sitelinks INT UNSIGNED NOT NULL DEFAULT 0,
      statements INT UNSIGNED NOT NULL DEFAULT 0,
      externalIds INT UNSIGNED NOT NULL DEFAULT 0,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (entity_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// Migration pour les tables créées par une version antérieure du script
// (CREATE TABLE IF NOT EXISTS ne modifie pas une table déjà existante).
$pdo->exec("
    ALTER TABLE wikidata_influence
      ADD COLUMN IF NOT EXISTS sitelinks INT UNSIGNED NOT NULL DEFAULT 0,
      ADD COLUMN IF NOT EXISTS statements INT UNSIGNED NOT NULL DEFAULT 0,
      ADD COLUMN IF NOT EXISTS externalIds INT UNSIGNED NOT NULL DEFAULT 0
");

// ── Personnes nées le $mmdd (mêmes critères que local-query.php) ──────────
$stmt = $pdo->prepare(
    "SELECT e.id
     FROM wikidata_properties p
     INNER JOIN wikidata_entities e ON p.entity_id = e.id AND p.property = 'P569'
     WHERE SUBSTR(p.value_str, 7, 5) = ?"
);
$stmt->execute([$mmdd]);
$allIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo count($allIds) . " personnes nées le $mmdd (tous ans confondus).\n";

if (!$allIds) {
    echo "Rien à faire.\n";
    exit(0);
}

// ── Ne garder que celles pas encore calculées ──────────────────────────────
$already = $pdo->query('SELECT entity_id FROM wikidata_influence')->fetchAll(PDO::FETCH_COLUMN);
$alreadySet = array_flip($already);
$todo = array_values(array_filter($allIds, fn($id) => !isset($alreadySet[$id])));
echo count($todo) . " restante(s) à calculer (" . (count($allIds) - count($todo)) . " déjà en cache).\n";

if (!$todo) {
    echo "Rien à faire.\n";
    exit(0);
}

$upsert = $pdo->prepare(
    'INSERT INTO wikidata_influence (entity_id, influenced_count, sitelinks, statements, externalIds)
     VALUES (:id, :influ, :sitelinks, :statements, :externalIds)
     ON DUPLICATE KEY UPDATE influenced_count = VALUES(influenced_count),
       sitelinks = VALUES(sitelinks), statements = VALUES(statements),
       externalIds = VALUES(externalIds)'
);

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

$done = 0;
$total = count($todo);
foreach ($todo as $qid) {
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
        fwrite(STDERR, "  ⚠ Échec SPARQL pour $qid, ignoré.\n");
        continue;
    }

    $data = json_decode($raw, true);
    $row  = $data['results']['bindings'][0] ?? null;
    if (!$row) {
        fwrite(STDERR, "  ⚠ Pas de résultat pour $qid, ignoré.\n");
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
    echo "  [$done/$total] $qid : sitelinks=$sitelinks statements=$statements externalIds=$externalIds influencés=$nbInflu\n";

    // Pause courte entre deux requêtes pour ne pas marteler le endpoint public.
    usleep(300000);
}

echo "$done personne(s) mise(s) à jour dans wikidata_influence pour le $mmdd.\n";
