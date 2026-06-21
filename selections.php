<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$dir = __DIR__ . '/selections';
if (!is_dir($dir)) mkdir($dir, 0755, true);

function safeName(string $name): string {
    return preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $name);
}

$action = $_GET['action'] ?? '';

switch ($action) {

    case 'list':
        $files = glob($dir . '/*.json') ?: [];
        $list = [];
        foreach ($files as $f) {
            $data = json_decode(file_get_contents($f), true);
            $meta = $data['recherche'] ?? [];
            $list[] = [
                'filename'  => basename($f),
                'saved'     => date('Y-m-d H:i', filemtime($f)),
                'date'      => $meta['date']        ?? '',
                'debut'     => $meta['periodeDebut'] ?? '',
                'fin'       => $meta['periodeFin']   ?? '',
                'total'     => $meta['total']        ?? count($data['items'] ?? []),
            ];
        }
        usort($list, fn($a, $b) => strcmp($b['saved'], $a['saved']));
        echo json_encode($list);
        break;

    case 'load':
        $filename = safeName($_GET['file'] ?? '');
        $path = $dir . '/' . $filename;
        if (!$filename || !file_exists($path)) {
            http_response_code(404);
            echo json_encode(['error' => 'Fichier non trouvé']);
            exit;
        }
        readfile($path);
        break;

    case 'save':
        $filename = safeName($_GET['file'] ?? 'selection') . '';
        if (!str_ends_with($filename, '.json')) $filename .= '.json';
        $body = file_get_contents('php://input');
        if (!$body) { http_response_code(400); echo json_encode(['error' => 'Corps vide']); exit; }
        file_put_contents($dir . '/' . $filename, $body);
        echo json_encode(['ok' => true, 'filename' => $filename]);
        break;

    case 'delete':
        $filename = safeName($_GET['file'] ?? '');
        $path = $dir . '/' . $filename;
        if (!$filename || !file_exists($path)) {
            http_response_code(404);
            echo json_encode(['error' => 'Fichier non trouvé']);
            exit;
        }
        unlink($path);
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action inconnue']);
}
