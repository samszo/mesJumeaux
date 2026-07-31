<?php
/**
 * Déclenche bdd/import_influence.php en arrière-plan (processus détaché,
 * non bloquant) pour compléter la table wikidata_influence sur une date
 * donnée. Appelé en fire-and-forget par l'interface après chaque recherche.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$day   = intval($_GET['day']   ?? 0);
$month = intval($_GET['month'] ?? 0);

if (!$day || !$month || $day < 1 || $day > 31 || $month < 1 || $month > 12) {
    http_response_code(400);
    echo json_encode(['error' => 'Paramètres day et month requis (entiers valides)']);
    exit;
}

if (!function_exists('exec')) {
    http_response_code(501);
    echo json_encode(['error' => "exec() est désactivé sur cet hébergement, traitement impossible en arrière-plan."]);
    exit;
}

$logDir = __DIR__ . '/cache';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);

$script  = escapeshellarg(__DIR__ . '/bdd/import_influence.php');
$logFile = escapeshellarg($logDir . sprintf('/influence-%02d-%02d.log', $month, $day));
$php     = escapeshellarg(PHP_BINARY);

// Le "&" final détache le process : exec() rend la main dès que le shell
// a lancé la commande, sans attendre sa fin (qui peut prendre plusieurs minutes).
// xdebug.mode=off évite que le process se bloque indéfiniment en attendant un
// débogueur si une session Xdebug est active sur la machine de dev.
exec(sprintf('%s -dxdebug.mode=off %s %d %d >> %s 2>&1 &', $php, $script, $day, $month, $logFile));

echo json_encode(['status' => 'started', 'day' => $day, 'month' => $month]);
