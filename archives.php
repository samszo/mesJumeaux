<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$archivesDir = __DIR__ . '/archives';
if (!is_dir($archivesDir)) mkdir($archivesDir, 0755, true);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Sanitise un nom de fichier (garde lettres, chiffres, tirets, underscores, points)
function safeName(string $name): string {
    return preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $name);
}

switch ($action) {

    // ── Liste des archives JSON ────────────────────────────────────────────
    case 'list':
        $files = glob($archivesDir . '/*.json') ?: [];
        $archives = [];
        foreach ($files as $f) {
            $meta = json_decode(file_get_contents($f), true);
            $archives[] = [
                'filename'  => basename($f),
                'saved'     => date('Y-m-d H:i', filemtime($f)),
                'label'     => $meta['label']     ?? '',
                'day'       => $meta['day']       ?? '',
                'month'     => $meta['month']     ?? '',
                'startYear' => $meta['startYear'] ?? '',
                'endYear'   => $meta['endYear']   ?? '',
                'count'     => $meta['count']     ?? 0,
            ];
        }
        usort($archives, fn($a, $b) => strcmp($b['saved'], $a['saved']));
        echo json_encode($archives);
        break;

    // ── Enregistrement JSON (résultats bruts) ─────────────────────────────
    case 'save-json':
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) { http_response_code(400); echo json_encode(['error' => 'Corps JSON invalide']); exit; }
        $filename = safeName($body['filename'] ?? 'archive') . '.json';
        file_put_contents($archivesDir . '/' . $filename, json_encode($body['data'], JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok' => true, 'filename' => $filename]);
        break;

    // ── Enregistrement HTML ───────────────────────────────────────────────
    case 'save-html':
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) { http_response_code(400); echo json_encode(['error' => 'Corps JSON invalide']); exit; }
        $filename = safeName($body['filename'] ?? 'archive') . '.html';
        file_put_contents($archivesDir . '/' . $filename, $body['content']);
        echo json_encode(['ok' => true, 'filename' => $filename, 'url' => 'archives/' . $filename]);
        break;

    // ── Chargement d'une archive JSON ─────────────────────────────────────
    case 'load':
        $filename = safeName($_GET['file'] ?? '');
        $path = $archivesDir . '/' . $filename;
        if (!$filename || !file_exists($path)) {
            http_response_code(404); echo json_encode(['error' => 'Fichier non trouvé']); exit;
        }
        readfile($path);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action inconnue : ' . htmlspecialchars($action)]);
}
