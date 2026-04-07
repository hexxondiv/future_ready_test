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

$firstName = trim((string)($data['firstName'] ?? ''));
$lastName = trim((string)($data['lastName'] ?? ''));
$school = trim((string)($data['school'] ?? ''));
$email = trim((string)($data['email'] ?? ''));
$phone = trim((string)($data['phone'] ?? ''));
$designation = trim((string)($data['designation'] ?? ''));

$errors = [];
if (strlen($firstName) < 2) {
    $errors[] = 'firstName';
}
if (strlen($lastName) < 2) {
    $errors[] = 'lastName';
}
if (strlen($school) < 3) {
    $errors[] = 'school';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'email';
}
if (!preg_match('/^[\d\s\+\-\(\)]{7,20}$/', $phone)) {
    $errors[] = 'phone';
}
if ($designation === '' || strlen($designation) > 120) {
    $errors[] = 'designation';
}
if (!isset($data['score']) || !is_numeric($data['score'])) {
    $errors[] = 'score';
} else {
    $score = (int) round((float) $data['score']);
    if ($score < 0 || $score > 100) {
        $errors[] = 'score';
    }
}

if ($errors !== []) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Validation failed', 'fields' => $errors]);
    exit;
}

$tierLabel = $score >= 90 ? 'Category Leader'
    : ($score >= 70 ? 'Future-Ready School'
        : ($score >= 50 ? 'Developing School' : 'At Risk'));

require_once __DIR__ . '/includes/db.php';

try {
    $pdo = futre_db();

    $stmt = $pdo->prepare(
        'INSERT INTO cohort_membership (first_name, last_name, designation, school, email, phone, score, tier_label)
         VALUES (:first_name, :last_name, :designation, :school, :email, :phone, :score, :tier_label)'
    );
    $stmt->execute([
        ':first_name' => $firstName,
        ':last_name' => $lastName,
        ':designation' => $designation,
        ':school' => $school,
        ':email' => $email,
        ':phone' => $phone,
        ':score' => $score,
        ':tier_label' => $tierLabel,
    ]);

    $id = (int) $pdo->lastInsertId();
    echo json_encode(['ok' => true, 'id' => $id, 'tierLabel' => $tierLabel]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
