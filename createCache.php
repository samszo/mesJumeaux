<?php
/**
 * createCache.php — Pré-génère le cache en appelant local-query.php via curl pour chaque jour.
 * Usage :
 *   CLI  : php createCache.php [--force] [--month=M]
 *   HTTP : http://…/createCache.php?force=1&month=6
 */

set_time_limit(0);
ignore_user_abort(true);

$isCli     = (php_sapi_name() === 'cli');
$force     = $isCli ? in_array('--force', $argv ?? []) : !empty($_GET['force']);
$onlyMonth = null;
if ($isCli) {
    foreach (($argv ?? []) as $arg) {
        if (preg_match('/^--month=(\d+)$/', $arg, $m)) $onlyMonth = (int) $m[1];
    }
} else {
    if (!empty($_GET['month'])) $onlyMonth = (int) $_GET['month'];
}

// ── URL de base ───────────────────────────────────────────────────────────
// En CLI on construit l'URL depuis le nom d'hôte connu ; en HTTP on utilise le host courant.
if ($isCli) {
    $baseUrl = 'http://localhost/mesJumeaux/local-query.php';
} else {
    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir     = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $baseUrl = "{$scheme}://{$host}{$dir}/local-query.php";
}

// ── Sortie ────────────────────────────────────────────────────────────────
if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8">
<title>Création du cache</title>
<style>
  body { font-family: monospace; background:#111; color:#ccc; padding:1rem; }
  .ok  { color:#4f4; } .skip { color:#fa0; } .err { color:#f44; }
  h1   { color:#fff; }
  progress { width:400px; vertical-align:middle; }
</style></head><body>
<h1>Création du cache</h1>';
    flush();
}

// ── Boucle sur tous les jours ─────────────────────────────────────────────
$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

$months = $onlyMonth ? [$onlyMonth] : range(1, 12);

// Compter les jours valides
$total = 0;
foreach ($months as $m) {
    for ($d = 1; $d <= 31; $d++) {
        if (checkdate($m, $d, 2000)) $total++;
    }
}

out("Base URL : {$baseUrl}");
out("Jours à traiter : {$total}" . ($force ? " (force=oui)" : "") . ($onlyMonth ? " (mois={$onlyMonth})" : ""));
if (!$isCli) echo "<progress id='pg' max='{$total}' value='0'></progress> <span id='pct'>0%</span><br><br>\n";
flush();

$done    = 0;
$skipped = 0;
$errors  = 0;
$cacheTTL = 7 * 24 * 3600;

foreach ($months as $month) {
    for ($day = 1; $day <= 31; $day++) {
        if (!checkdate($month, $day, 2000)) continue;

        $mmdd      = sprintf('%02d-%02d', $month, $day);
        $cacheFile = $cacheDir . '/local-' . $mmdd . '.json';

        if (!$force && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
            out("{$mmdd} → ignoré (cache valide)", 'skip');
            $skipped++;
            $done++;
            progress($done, $total);
            continue;
        }

        $url = $baseUrl . '?day=' . $day . '&month=' . $month;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr || $httpCode !== 200) {
            out("{$mmdd} → ERREUR HTTP {$httpCode} " . ($curlErr ?: ''), 'err');
            $errors++;
        } else {
            $count = count(json_decode($body, true)['jumeaux'] ?? []);
            out("{$mmdd} → {$count} jumeaux", 'ok');
        }

        $done++;
        progress($done, $total);
        flush();
    }
}

out("");
out("Terminé. {$total} jours — ignorés : {$skipped} — erreurs : {$errors}", 'ok');
if (!$isCli) echo "</body></html>\n";

// ── Helpers ───────────────────────────────────────────────────────────────
function out(string $msg, string $cls = ''): void {
    global $isCli;
    if ($isCli) {
        echo $msg . "\n";
    } else {
        $tag = $cls ? "<span class=\"{$cls}\">" . htmlspecialchars($msg) . "</span><br>\n"
                    : htmlspecialchars($msg) . "<br>\n";
        echo $tag;
        if (ob_get_level()) ob_flush();
        flush();
    }
}

function progress(int $done, int $total): void {
    global $isCli;
    if ($isCli) return;
    $pct = $total ? round($done / $total * 100) : 100;
    echo "<script>
      var pg=document.getElementById('pg'); if(pg) pg.value={$done};
      var pc=document.getElementById('pct'); if(pc) pc.textContent='{$pct}%';
    </script>\n";
    if (ob_get_level()) ob_flush();
    flush();
}
