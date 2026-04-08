<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$registrationId = isset($data['registrationId']) ? (int) $data['registrationId'] : 0;
$scoreRaw = $data['score'] ?? null;
if ($registrationId <= 0 || !is_numeric($scoreRaw)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Validation failed']);
    exit;
}

$score = (int) round((float) $scoreRaw);
if ($score < 0 || $score > 100) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Validation failed']);
    exit;
}

$tierLabel = $score >= 90 ? 'Category Leader'
    : ($score >= 70 ? 'Future-Ready School'
        : ($score >= 50 ? 'Developing School' : 'At Risk'));

require_once __DIR__ . '/includes/db.php';

try {
    $pdo = futre_db();
    $st = $pdo->prepare('UPDATE registrations SET score = ?, tier_label = ? WHERE id = ?');
    $st->execute([$score, $tierLabel, $registrationId]);
    echo json_encode(['ok' => true, 'tierLabel' => $tierLabel]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}

