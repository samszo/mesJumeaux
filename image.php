<?php
/**
 * Sert les portraits (P18) issus de Wikimedia Commons en les mettant en cache
 * localement dans cache/img/, pour ne pas retélécharger la même image à
 * chaque affichage. Les personnes sans photo connue sont marquées (fichier
 * .none) pour ne pas retenter la résolution à chaque fois.
 *
 * Paramètres : qid (requis), file (nom de fichier P18 déjà résolu, optionnel
 * — évite un aller-retour Wikidata si l'appelant le connaît déjà), width.
 */

$qid   = $_GET['qid']   ?? '';
$file  = $_GET['file']  ?? '';
$width = intval($_GET['width'] ?? 120);

if (!preg_match('/^Q\d+$/', $qid)) {
    http_response_code(400);
    exit;
}

$cacheDir = __DIR__ . '/cache/img';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

function serveFromCache(string $path): never
{
    if (str_ends_with($path, '.none')) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . (mime_content_type($path) ?: 'application/octet-stream'));
    header('Cache-Control: public, max-age=2592000, immutable');
    readfile($path);
    exit;
}

// Déjà en cache localement (image ou marqueur "pas d'image") ?
$existing = glob("$cacheDir/$qid.*");
if ($existing) {
    serveFromCache($existing[0]);
}

$ctx = stream_context_create(['http' => [
    'header'  => "User-Agent: mesJumeaux/1.0 (image cache)\r\n",
    'timeout' => 15,
]]);

// Résout le nom de fichier P18 si l'appelant ne l'a pas déjà fourni
if (!$file) {
    $apiUrl = 'https://www.wikidata.org/w/api.php?' . http_build_query([
        'action' => 'wbgetentities',
        'ids'    => $qid,
        'props'  => 'claims',
        'format' => 'json',
    ]);
    $raw  = @file_get_contents($apiUrl, false, $ctx);
    $data = $raw ? json_decode($raw, true) : null;
    $file = $data['entities'][$qid]['claims']['P18'][0]['mainsnak']['datavalue']['value'] ?? '';
}

if (!$file) {
    touch("$cacheDir/$qid.none");
    http_response_code(404);
    exit;
}

$filename   = str_replace(' ', '_', $file);
$commonsUrl = 'https://commons.wikimedia.org/w/index.php?' . http_build_query([
    'title' => 'Special:Redirect/file/' . $filename,
    'width' => $width,
]);
$imgData = @file_get_contents($commonsUrl, false, $ctx);

if ($imgData === false || $imgData === '') {
    touch("$cacheDir/$qid.none");
    http_response_code(404);
    exit;
}

$mime = 'image/jpeg';
$ext  = 'jpg';
foreach ($http_response_header ?? [] as $h) {
    if (stripos($h, 'Content-Type:') === 0) {
        $mime = trim(substr($h, strpos($h, ':') + 1));
        if (str_contains($mime, 'png'))       $ext = 'png';
        elseif (str_contains($mime, 'gif'))   $ext = 'gif';
        elseif (str_contains($mime, 'svg'))   $ext = 'svg';
        elseif (str_contains($mime, 'webp'))  $ext = 'webp';
    }
}

file_put_contents("$cacheDir/$qid.$ext", $imgData);
header("Content-Type: $mime");
header('Cache-Control: public, max-age=2592000, immutable');
echo $imgData;
