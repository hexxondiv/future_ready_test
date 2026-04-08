<?php
declare(strict_types=1);

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

$configPath = __DIR__ . '/config.php';
if (!is_readable($configPath)) {
    http_response_code(500);
    exit('Admin config missing. Copy admin/config.php from the project.');
}

/** @var array{username: string, password_hash: string} $config */
$config = require $configPath;

require_once __DIR__ . '/../includes/db.php';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$err = '';
$loggedIn = !empty($_SESSION['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $csrf = (string)($_POST['csrf'] ?? '');

    if (!hash_equals($_SESSION['csrf'], $csrf)) {
        if ($loggedIn && ($action === 'delete_registration' || $action === 'delete_cohort' || $action === 'export_registrations' || $action === 'export_cohort' || $action === 'backfill_reg_scores')) {
            header('Location: index.php');
            exit;
        }
        $err = 'Invalid session. Please refresh the page.';
    } elseif ($action === 'logout' && $loggedIn) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
        header('Location: index.php');
        exit;
    } elseif ($action === 'login' && !$loggedIn) {
        $u = trim((string)($_POST['username'] ?? ''));
        $p = (string)($_POST['password'] ?? '');
        if ($u === $config['username'] && password_verify($p, $config['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            header('Location: index.php');
            exit;
        }
        $err = 'Invalid username or password.';
    } elseif ($loggedIn && $action === 'delete_registration') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $st = futre_db()->prepare('DELETE FROM registrations WHERE id = ?');
                $st->execute([$id]);
            } catch (Throwable $e) {
                // ignore; redirect anyway
            }
        }
        header('Location: index.php?tab=reg');
        exit;
    } elseif ($loggedIn && $action === 'delete_cohort') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $st = futre_db()->prepare('DELETE FROM cohort_membership WHERE id = ?');
                $st->execute([$id]);
            } catch (Throwable $e) {
                // ignore
            }
        }
        header('Location: index.php?tab=cohort');
        exit;
    } elseif ($loggedIn && ($action === 'export_registrations' || $action === 'export_cohort')) {
        try {
            $pdo = futre_db();
            if ($action === 'export_registrations') {
                $stmt = $pdo->query(
                    'SELECT id, created_at, first_name, last_name, designation, school, email, phone, score, tier_label
                     FROM registrations ORDER BY id DESC'
                );
                $filename = 'registrations_' . gmdate('Y-m-d') . '.csv';
                $headers = ['ID', 'Registered At', 'First Name', 'Last Name', 'Designation', 'School', 'Email', 'Phone', 'Score', 'Tier'];
            } else {
                $stmt = $pdo->query(
                    'SELECT id, created_at, first_name, last_name, designation, school, email, phone, score, tier_label
                     FROM cohort_membership ORDER BY id DESC'
                );
                $filename = 'cohort_memberships_' . gmdate('Y-m-d') . '.csv';
                $headers = ['ID', 'Joined At', 'First Name', 'Last Name', 'Designation', 'School', 'Email', 'Phone', 'Score', 'Tier'];
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('X-Content-Type-Options: nosniff');

            $out = fopen('php://output', 'w');
            if ($out === false) {
                throw new RuntimeException('Could not open output');
            }
            fputcsv($out, $headers);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($out, [
                    $row['id'] ?? '',
                    $row['created_at'] ?? '',
                    $row['first_name'] ?? '',
                    $row['last_name'] ?? '',
                    $row['designation'] ?? '',
                    $row['school'] ?? '',
                    $row['email'] ?? '',
                    $row['phone'] ?? '',
                    $row['score'] ?? '',
                    $row['tier_label'] ?? '',
                ]);
            }
            fclose($out);
            exit;
        } catch (Throwable $e) {
            header('Location: index.php');
            exit;
        }
    } elseif ($loggedIn && $action === 'backfill_reg_scores') {
        try {
            $pdo = futre_db();
            // Backfill registrations.score/tier_label from the latest cohort_membership row for the same email.
            // Only fills missing values to avoid overwriting newer captures.
            $pdo->exec(<<<'SQL'
UPDATE registrations r
JOIN (
  SELECT cm.email, cm.score, cm.tier_label
  FROM cohort_membership cm
  JOIN (
    SELECT email, MAX(id) AS max_id
    FROM cohort_membership
    GROUP BY email
  ) latest ON latest.email = cm.email AND latest.max_id = cm.id
) x ON x.email = r.email
SET r.score = COALESCE(r.score, x.score),
    r.tier_label = COALESCE(r.tier_label, x.tier_label)
SQL);
        } catch (Throwable $e) {
            // ignore
        }
        header('Location: index.php?tab=reg');
        exit;
    }
}

$rows = [];
$cohortRows = [];
$dbError = null;
$regMissingCount = 0;
$regTotal = 0;
$cohortTotal = 0;
$analytics = [
    'tier_reg' => [],
    'tier_cohort' => [],
    'days' => [],
    'regs' => [],
    'cohorts' => [],
    'last7_regs' => 0,
    'prev7_regs' => 0,
    'last7_cohort' => 0,
    'prev7_cohort' => 0,
];

$regPage = isset($_GET['reg_page']) ? (int) $_GET['reg_page'] : 1;
$cohortPage = isset($_GET['cohort_page']) ? (int) $_GET['cohort_page'] : 1;
$regLimit = isset($_GET['reg_limit']) ? (int) $_GET['reg_limit'] : 25;
$cohortLimit = isset($_GET['cohort_limit']) ? (int) $_GET['cohort_limit'] : 25;

if ($regPage < 1) $regPage = 1;
if ($cohortPage < 1) $cohortPage = 1;
$allowedLimits = [10, 25, 50, 100];
if (!in_array($regLimit, $allowedLimits, true)) $regLimit = 25;
if (!in_array($cohortLimit, $allowedLimits, true)) $cohortLimit = 25;

function build_admin_url(array $overrides = []): string
{
    $q = array_merge($_GET, $overrides);
    foreach ($q as $k => $v) {
        if ($v === null) unset($q[$k]);
    }
    $qs = http_build_query($q);
    return 'index.php' . ($qs ? ('?' . $qs) : '');
}

function clamp_page(int $page, int $total, int $limit): int
{
    if ($limit <= 0) return 1;
    $maxPage = max(1, (int) ceil($total / $limit));
    if ($page > $maxPage) return $maxPage;
    if ($page < 1) return 1;
    return $page;
}

function render_pager(string $prefix, int $page, int $limit, int $total): string
{
    $maxPage = max(1, (int) ceil($total / $limit));
    $from = $total === 0 ? 0 : (($page - 1) * $limit + 1);
    $to = min($total, $page * $limit);
    $prevDisabled = $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '';
    $nextDisabled = $page >= $maxPage ? 'aria-disabled="true" tabindex="-1"' : '';
    $prevHref = $page <= 1 ? '#' : h(build_admin_url([$prefix . '_page' => $page - 1]));
    $nextHref = $page >= $maxPage ? '#' : h(build_admin_url([$prefix . '_page' => $page + 1]));

    return '<div class="pager">'
        . '<div class="pager-meta">' . h((string) $from) . '–' . h((string) $to) . ' of ' . h((string) $total) . '</div>'
        . '<div class="pager-actions">'
        . '<a class="pager-btn" href="' . $prevHref . '" ' . $prevDisabled . '>Prev</a>'
        . '<span class="pager-page">Page ' . h((string) $page) . ' / ' . h((string) $maxPage) . '</span>'
        . '<a class="pager-btn" href="' . $nextHref . '" ' . $nextDisabled . '>Next</a>'
        . '</div>'
        . '</div>';
}

if ($loggedIn) {
    try {
        $pdo = futre_db();
        $regTotal = (int) ($pdo->query('SELECT COUNT(*) FROM registrations')->fetchColumn() ?: 0);
        $cohortTotal = (int) ($pdo->query('SELECT COUNT(*) FROM cohort_membership')->fetchColumn() ?: 0);

        $regPage = clamp_page($regPage, $regTotal, $regLimit);
        $cohortPage = clamp_page($cohortPage, $cohortTotal, $cohortLimit);

        $regOffset = ($regPage - 1) * $regLimit;
        $cohortOffset = ($cohortPage - 1) * $cohortLimit;

        $stmt = $pdo->prepare(
            'SELECT id, first_name, last_name, school, email, phone, designation, score, tier_label, created_at
             FROM registrations ORDER BY id DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':lim', $regLimit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $regOffset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        try {
            $mstmt = $pdo->query('SELECT COUNT(*) AS c FROM registrations WHERE score IS NULL OR tier_label IS NULL');
            $regMissingCount = (int)($mstmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $regMissingCount = 0;
        }
        $cstmt = $pdo->prepare(
            'SELECT id, first_name, last_name, school, email, phone, designation, score, tier_label, created_at
             FROM cohort_membership ORDER BY id DESC LIMIT :lim OFFSET :off'
        );
        $cstmt->bindValue(':lim', $cohortLimit, PDO::PARAM_INT);
        $cstmt->bindValue(':off', $cohortOffset, PDO::PARAM_INT);
        $cstmt->execute();
        $cohortRows = $cstmt->fetchAll(PDO::FETCH_ASSOC);

        // ── Analytics (server-side aggregates) ────────────────────────────────
        // Tier distributions
        $t1 = $pdo->query('SELECT COALESCE(tier_label, "Unscored") AS tier, COUNT(*) AS c FROM registrations GROUP BY tier ORDER BY c DESC');
        $analytics['tier_reg'] = $t1->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $t2 = $pdo->query('SELECT tier_label AS tier, COUNT(*) AS c FROM cohort_membership GROUP BY tier ORDER BY c DESC');
        $analytics['tier_cohort'] = $t2->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Daily counts (last 14 days)
        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $days[] = gmdate('Y-m-d', time() - $i * 86400);
        }
        $analytics['days'] = $days;

        $regMap = array_fill_keys($days, 0);
        $cohortMap = array_fill_keys($days, 0);

        $rs = $pdo->query("SELECT DATE(created_at) AS d, COUNT(*) AS c FROM registrations WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 13 DAY) GROUP BY DATE(created_at)");
        foreach (($rs->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $d = (string)($r['d'] ?? '');
            if (isset($regMap[$d])) $regMap[$d] = (int)$r['c'];
        }
        $cs = $pdo->query("SELECT DATE(created_at) AS d, COUNT(*) AS c FROM cohort_membership WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 13 DAY) GROUP BY DATE(created_at)");
        foreach (($cs->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            $d = (string)($r['d'] ?? '');
            if (isset($cohortMap[$d])) $cohortMap[$d] = (int)$r['c'];
        }
        $analytics['regs'] = array_values($regMap);
        $analytics['cohorts'] = array_values($cohortMap);

        // Date-based comparisons: last 7 days vs previous 7 days (UTC)
        $analytics['last7_regs'] = (int)($pdo->query("SELECT COUNT(*) FROM registrations WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)")->fetchColumn() ?: 0);
        $analytics['prev7_regs'] = (int)($pdo->query("SELECT COUNT(*) FROM registrations WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 7 DAY) AND created_at >= (UTC_TIMESTAMP() - INTERVAL 14 DAY)")->fetchColumn() ?: 0);
        $analytics['last7_cohort'] = (int)($pdo->query("SELECT COUNT(*) FROM cohort_membership WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)")->fetchColumn() ?: 0);
        $analytics['prev7_cohort'] = (int)($pdo->query("SELECT COUNT(*) FROM cohort_membership WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 7 DAY) AND created_at >= (UTC_TIMESTAMP() - INTERVAL 14 DAY)")->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $dbError = 'Could not connect to MySQL. Check: (1) MySQL is running, (2) FUTRE_DB_* in .env or the environment — on Linux use FUTRE_DB_HOST=127.0.0.1 (not localhost) if root uses a password, (3) php -m includes pdo_mysql. See env.example.';
    }
}

$csrf = $_SESSION['csrf'];
$count = count($rows);
$cohortCount = count($cohortRows);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin · Dashboard</title>
  <link rel="icon" href="../logo.png" type="image/png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
  <style>
    :root {
      --navy: #0A1628;
      --navy-mid: #12213D;
      --gold: #E8A427;
      --gold-light: #F5C96A;
      --white: #FFFFFF;
      --ash: #8A8176;
      --danger: #C13A2E;
      --teal: #00C6A7;
      --border: rgba(255,255,255,0.08);
      --glass: rgba(18, 33, 61, 0.75);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { min-height: 100%; }
    body {
      font-family: 'DM Sans', system-ui, sans-serif;
      background: var(--navy);
      color: var(--white);
      line-height: 1.5;
    }
    .bg {
      position: fixed;
      inset: 0;
      z-index: 0;
      background: var(--navy);
      overflow: hidden;
      pointer-events: none;
    }
    .bg::before {
      content: '';
      position: absolute;
      width: 70vmax;
      height: 70vmax;
      border-radius: 50%;
      background: var(--gold);
      filter: blur(100px);
      opacity: 0.1;
      top: -25%;
      right: -15%;
    }
    .bg::after {
      content: '';
      position: absolute;
      width: 50vmax;
      height: 50vmax;
      border-radius: 50%;
      background: var(--teal);
      filter: blur(90px);
      opacity: 0.06;
      bottom: -20%;
      left: -10%;
    }
    .wrap {
      position: relative;
      z-index: 1;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      padding: clamp(1rem, 4vw, 2rem);
      max-width: 1400px;
      margin: 0 auto;
    }

    /* Login */
    .login-shell {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2rem 0;
    }
    .login-card {
      width: 100%;
      max-width: 420px;
      background: var(--glass);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid var(--border);
      border-radius: 20px;
      padding: 2.25rem 2rem 2rem;
      box-shadow: 0 24px 80px rgba(0,0,0,0.35);
    }
    .login-brand {
      font-family: 'Playfair Display', serif;
      font-size: 1.5rem;
      font-weight: 600;
      margin-bottom: 0.25rem;
    }
    .login-sub {
      color: var(--ash);
      font-size: 0.9rem;
      margin-bottom: 1.75rem;
    }
    .field { margin-bottom: 1.15rem; }
    .field label {
      display: block;
      font-size: 0.75rem;
      font-weight: 600;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: rgba(255,255,255,0.55);
      margin-bottom: 0.45rem;
    }
    .field input {
      width: 100%;
      padding: 0.75rem 1rem;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: rgba(10,22,40,0.6);
      color: var(--white);
      font: inherit;
      font-size: 1rem;
    }
    .field input:focus {
      outline: none;
      border-color: rgba(232,164,39,0.45);
      box-shadow: 0 0 0 3px rgba(232,164,39,0.12);
    }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      font: inherit;
      font-weight: 600;
      font-size: 0.95rem;
      padding: 0.85rem 1.25rem;
      border-radius: 10px;
      border: none;
      cursor: pointer;
      transition: transform 0.15s, box-shadow 0.15s, background 0.15s;
    }
    .btn:active { transform: scale(0.98); }
    .btn-primary {
      width: 100%;
      margin-top: 0.5rem;
      background: linear-gradient(135deg, var(--gold) 0%, #c98912 100%);
      color: var(--navy);
      box-shadow: 0 8px 28px rgba(232,164,39,0.25);
    }
    .btn-primary:hover {
      box-shadow: 0 10px 36px rgba(232,164,39,0.35);
    }
    .btn-ghost {
      background: transparent;
      color: rgba(255,255,255,0.7);
      border: 1px solid var(--border);
      padding: 0.55rem 1rem;
      font-size: 0.85rem;
    }
    .btn-ghost:hover { background: rgba(255,255,255,0.05); color: var(--white); }
    .alert {
      padding: 0.75rem 1rem;
      border-radius: 10px;
      background: rgba(193,58,46,0.15);
      border: 1px solid rgba(193,58,46,0.35);
      color: #f0a8a0;
      font-size: 0.9rem;
      margin-bottom: 1.25rem;
    }

    /* Dashboard */
    .dash-header {
      display: flex;
      flex-wrap: wrap;
      align-items: flex-start;
      justify-content: space-between;
      gap: 1rem;
      margin-bottom: 1.5rem;
    }
    .dash-title {
      font-family: 'Playfair Display', serif;
      font-size: clamp(1.75rem, 4vw, 2.25rem);
      font-weight: 700;
    }
    .dash-title span { color: var(--gold-light); font-weight: 600; }
    .dash-meta {
      color: var(--ash);
      font-size: 0.9rem;
      margin-top: 0.35rem;
    }
    .dash-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 0.65rem;
      align-items: center;
    }
    .badge {
      display: inline-flex;
      align-items: center;
      padding: 0.35rem 0.75rem;
      border-radius: 999px;
      background: rgba(232,164,39,0.12);
      border: 1px solid rgba(232,164,39,0.25);
      color: var(--gold-light);
      font-size: 0.8rem;
      font-weight: 600;
    }
    .toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      align-items: center;
      margin-bottom: 1rem;
    }
    .search-wrap {
      flex: 1;
      min-width: 200px;
      max-width: 360px;
      position: relative;
    }
    .search-wrap svg {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      width: 18px;
      height: 18px;
      opacity: 0.4;
      pointer-events: none;
    }
    .search-wrap input {
      width: 100%;
      padding: 0.65rem 0.85rem 0.65rem 2.5rem;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: rgba(18,33,61,0.6);
      color: var(--white);
      font: inherit;
    }
    .search-wrap input:focus {
      outline: none;
      border-color: rgba(232,164,39,0.35);
    }
    .filter-select {
      padding: 0.65rem 0.85rem;
      border-radius: 10px;
      border: 1px solid var(--border);
      background: rgba(18,33,61,0.6);
      color: var(--white);
      font: inherit;
      min-width: 190px;
    }
    .filter-select:focus {
      outline: none;
      border-color: rgba(232,164,39,0.35);
      box-shadow: 0 0 0 3px rgba(232,164,39,0.12);
    }
    .pager {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      padding: 0.75rem 0.9rem;
      border-top: 1px solid var(--border);
      background: rgba(10,22,40,0.25);
    }
    .pager-meta { color: rgba(255,255,255,0.55); font-size: 0.85rem; }
    .pager-actions { display: inline-flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
    .pager-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0.45rem 0.7rem;
      border-radius: 10px;
      border: 1px solid var(--border);
      color: rgba(255,255,255,0.75);
      text-decoration: none;
      font-size: 0.85rem;
      font-weight: 600;
      background: rgba(255,255,255,0.03);
    }
    .pager-btn:hover { background: rgba(255,255,255,0.06); color: var(--white); }
    .pager-btn[aria-disabled="true"] { opacity: 0.45; pointer-events: none; }
    .pager-page { color: rgba(255,255,255,0.55); font-size: 0.85rem; padding: 0 0.25rem; }
    .table-card {
      background: var(--glass);
      backdrop-filter: blur(12px);
      border: 1px solid var(--border);
      border-radius: 16px;
      overflow: hidden;
    }
    .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.875rem;
    }
    th, td {
      padding: 0.85rem 1rem;
      text-align: left;
      border-bottom: 1px solid var(--border);
    }
    th {
      font-size: 0.7rem;
      font-weight: 600;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: rgba(255,255,255,0.45);
      background: rgba(10,22,40,0.35);
      white-space: nowrap;
    }
    tr:last-child td { border-bottom: none; }
    tbody tr:hover td { background: rgba(255,255,255,0.02); }
    tbody tr.hidden { display: none; }
    .cell-muted { color: rgba(255,255,255,0.55); }
    .cell-strong { font-weight: 500; }
    .cell-email a {
      color: var(--teal);
      text-decoration: none;
    }
    .cell-email a:hover { text-decoration: underline; }
    .empty {
      text-align: center;
      padding: 3rem 1.5rem;
      color: var(--ash);
    }
    .empty-icon {
      width: 48px;
      height: 48px;
      margin: 0 auto 1rem;
      border-radius: 12px;
      background: rgba(255,255,255,0.05);
      border: 1px dashed rgba(255,255,255,0.12);
    }
    .info-banner {
      padding: 1rem 1.25rem;
      border-radius: 12px;
      background: rgba(0,198,167,0.08);
      border: 1px solid rgba(0,198,167,0.2);
      color: #9ee8d9;
      font-size: 0.9rem;
      margin-bottom: 1rem;
    }
    .tabs { margin-top: 0.25rem; }
    .tab-list {
      display: flex;
      gap: 0.35rem;
      padding: 0.4rem;
      background: var(--glass);
      backdrop-filter: blur(12px);
      border: 1px solid var(--border);
      border-radius: 14px;
      margin-bottom: 1.25rem;
    }
    .tab {
      flex: 1;
      min-width: 0;
      padding: 0.8rem 0.85rem;
      border: none;
      border-radius: 10px;
      background: transparent;
      color: rgba(255,255,255,0.5);
      font: inherit;
      font-weight: 600;
      font-size: 0.88rem;
      cursor: pointer;
      transition: background 0.15s, color 0.15s, box-shadow 0.15s;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.45rem;
      flex-wrap: wrap;
    }
    .tab:hover {
      color: rgba(255,255,255,0.88);
      background: rgba(255,255,255,0.05);
    }
    .tab:focus {
      outline: none;
      box-shadow: 0 0 0 2px var(--navy), 0 0 0 4px rgba(232,164,39,0.45);
    }
    .tab[aria-selected="true"] {
      background: rgba(232,164,39,0.14);
      color: var(--gold-light);
      box-shadow: inset 0 0 0 1px rgba(232,164,39,0.22);
    }
    .tab-count {
      font-size: 0.72rem;
      font-weight: 700;
      padding: 0.2rem 0.5rem;
      border-radius: 999px;
      background: rgba(255,255,255,0.08);
      color: rgba(255,255,255,0.65);
    }
    .tab[aria-selected="true"] .tab-count {
      background: rgba(232,164,39,0.28);
      color: var(--navy);
    }
    .tab-label-short { display: none; }
    @media (max-width: 520px) {
      .tab-label-full { display: none; }
      .tab-label-short { display: inline; }
    }
    .tab-panel-desc {
      color: var(--ash);
      font-size: 0.875rem;
      margin-bottom: 1rem;
      line-height: 1.45;
    }
    .cards {
      display: grid;
      grid-template-columns: repeat(12, 1fr);
      gap: 1rem;
      margin-bottom: 1rem;
    }
    .card {
      grid-column: span 12;
      background: var(--glass);
      backdrop-filter: blur(12px);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 1rem 1.1rem;
    }
    .card h3 {
      font-size: 0.8rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: rgba(255,255,255,0.5);
      margin-bottom: 0.5rem;
    }
    .metric {
      font-family: 'Playfair Display', serif;
      font-size: 2rem;
      font-weight: 700;
      line-height: 1.1;
    }
    .metric-sub {
      color: rgba(255,255,255,0.55);
      font-size: 0.9rem;
      margin-top: 0.35rem;
    }
    .delta {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      font-size: 0.85rem;
      font-weight: 600;
      margin-top: 0.5rem;
      color: #9ee8d9;
    }
    .delta.down { color: #f0a8a0; }
    .delta .pill {
      padding: 0.2rem 0.55rem;
      border-radius: 999px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,0.04);
      color: rgba(255,255,255,0.7);
      font-weight: 700;
      font-size: 0.75rem;
    }
    @media (min-width: 860px) {
      .card.sm { grid-column: span 4; }
      .card.md { grid-column: span 6; }
    }
    .chart-title { display:flex; justify-content:space-between; gap:0.75rem; align-items:baseline; margin-bottom:0.75rem;}
    .chart-title .label { font-weight: 700; }
    .chart-title .hint { color: rgba(255,255,255,0.5); font-size: 0.85rem; }
    .bars {
      display: grid;
      grid-template-columns: repeat(14, 1fr);
      gap: 0.5rem;
      align-items: end;
      height: 170px;
      padding: 0.5rem 0.25rem 0.25rem;
    }
    .bar {
      height: 6px;
      border-radius: 8px;
      background: rgba(255,255,255,0.08);
      position: relative;
      overflow: hidden;
    }
    .bar > span {
      position: absolute;
      inset: auto 0 0 0;
      height: 100%;
      background: linear-gradient(180deg, rgba(232,164,39,0.85), rgba(232,164,39,0.35));
      border-radius: 8px;
    }
    .bar.teal > span {
      background: linear-gradient(180deg, rgba(0,198,167,0.85), rgba(0,198,167,0.35));
    }
    .bar-labels {
      display: grid;
      grid-template-columns: repeat(14, 1fr);
      gap: 0.5rem;
      margin-top: 0.35rem;
      color: rgba(255,255,255,0.45);
      font-size: 0.72rem;
    }
    .bar-labels span { text-align: center; }
    .pie-wrap { display: grid; grid-template-columns: 140px 1fr; gap: 1rem; align-items: center; }
    .pie {
      width: 140px;
      height: 140px;
      border-radius: 50%;
      background: conic-gradient(from 90deg, rgba(255,255,255,0.08) 0 100%);
      border: 1px solid var(--border);
      box-shadow: inset 0 0 0 8px rgba(10,22,40,0.35);
    }
    .legend { display: grid; gap: 0.4rem; }
    .legend-row { display:flex; align-items:center; justify-content:space-between; gap:0.75rem; }
    .legend-left { display:flex; align-items:center; gap:0.5rem; min-width:0; }
    .dot { width:10px; height:10px; border-radius:3px; flex: 0 0 auto; }
    .legend-name { color: rgba(255,255,255,0.8); font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .legend-val { color: rgba(255,255,255,0.55); font-size: 0.85rem; font-weight: 700; }
    .score-pill {
      display: inline-block;
      font-weight: 600;
      font-size: 0.8rem;
      padding: 0.2rem 0.55rem;
      border-radius: 6px;
      background: rgba(232,164,39,0.15);
      color: var(--gold-light);
    }
    .cell-actions {
      width: 1%;
      white-space: nowrap;
      vertical-align: middle;
    }
    .row-delete-form { display: inline; margin: 0; }
    .btn-delete {
      font: inherit;
      font-size: 0.75rem;
      font-weight: 600;
      padding: 0.35rem 0.65rem;
      border-radius: 8px;
      border: 1px solid rgba(193,58,46,0.45);
      background: rgba(193,58,46,0.12);
      color: #f0a8a0;
      cursor: pointer;
      transition: background 0.15s, border-color 0.15s;
    }
    .btn-delete:hover {
      background: rgba(193,58,46,0.22);
      border-color: rgba(193,58,46,0.65);
      color: #ffc8c2;
    }
  </style>
</head>
<body>
  <div class="bg" aria-hidden="true"></div>
  <div class="wrap">
    <?php if (!$loggedIn): ?>
      <div class="login-shell">
        <div class="login-card">
          <div class="login-brand">Educare Admin</div>
          <p class="login-sub">Sign in to view registrations and cohort sign-ups.</p>
          <?php if ($err !== ''): ?>
            <div class="alert" role="alert"><?= h($err) ?></div>
          <?php endif; ?>
          <form method="post" action="index.php" autocomplete="on">
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <div class="field">
              <label for="username">Username</label>
              <input id="username" name="username" type="text" required autocomplete="username" autofocus>
            </div>
            <div class="field">
              <label for="password">Password</label>
              <input id="password" name="password" type="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary">Sign in</button>
          </form>
        </div>
      </div>
    <?php else: ?>
      <header class="dash-header">
        <div>
          <h1 class="dash-title">Dashboard</h1>
          <p class="dash-meta">Future-Ready School Test · <?= (string)$count ?> registration<?= $count === 1 ? '' : 's' ?> · <?= (string)$cohortCount ?> cohort<?= $cohortCount === 1 ? '' : 's' ?></p>
        </div>
        <div class="dash-actions">
          <span class="badge"><?= h((string)$count) ?> reg</span>
          <span class="badge" style="border-color:rgba(0,198,167,0.25);background:rgba(0,198,167,0.1);color:#9ee8d9"><?= h((string)$cohortCount) ?> cohort</span>
          <a class="btn btn-ghost" href="../index.html" target="_blank" rel="noopener noreferrer">← Public site</a>
          <form method="post" action="index.php" style="display:inline">
            <input type="hidden" name="action" value="logout">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <button type="submit" class="btn btn-ghost">Log out</button>
          </form>
        </div>
      </header>

      <?php if ($dbError !== null): ?>
        <div class="info-banner" role="status"><?= h($dbError) ?></div>
      <?php else: ?>
        <div class="tabs" id="admin-tabs">
          <div class="tab-list" role="tablist" aria-label="Database tables">
            <button type="button" class="tab" role="tab" id="tab-analytics" aria-selected="true" aria-controls="panel-analytics" tabindex="0">
              <span class="tab-label-full">Analytics</span>
              <span class="tab-label-short">Analytics</span>
              <span class="tab-count" aria-hidden="true">Live</span>
            </button>
            <button type="button" class="tab" role="tab" id="tab-reg" aria-selected="false" aria-controls="panel-reg" tabindex="-1">
              <span class="tab-label-full">Step 1 · Registrations</span>
              <span class="tab-label-short">Registrations</span>
              <span class="tab-count" aria-hidden="true"><?= h((string)$count) ?></span>
            </button>
            <button type="button" class="tab" role="tab" id="tab-cohort" aria-selected="false" aria-controls="panel-cohort" tabindex="-1">
              <span class="tab-label-full">Cohort Memberships</span>
              <span class="tab-label-short">Memberships</span>
              <span class="tab-count" aria-hidden="true"><?= h((string)$cohortCount) ?></span>
            </button>
          </div>

          <div class="tab-panel" role="tabpanel" id="panel-analytics" aria-labelledby="tab-analytics">
            <p class="tab-panel-desc">High-level performance and trends (UTC time).</p>

            <?php
              $conv = $regTotal > 0 ? round(($cohortTotal / $regTotal) * 100, 1) : 0.0;
              $deltaRegs = $analytics['prev7_regs'] > 0 ? round((($analytics['last7_regs'] - $analytics['prev7_regs']) / $analytics['prev7_regs']) * 100, 1) : null;
              $deltaCoh = $analytics['prev7_cohort'] > 0 ? round((($analytics['last7_cohort'] - $analytics['prev7_cohort']) / $analytics['prev7_cohort']) * 100, 1) : null;
            ?>

            <div class="cards">
              <div class="card sm">
                <h3>Total registrations</h3>
                <div class="metric"><?= h((string)$regTotal) ?></div>
                <div class="metric-sub">Last 7 days: <strong><?= h((string)$analytics['last7_regs']) ?></strong> · Prev 7: <strong><?= h((string)$analytics['prev7_regs']) ?></strong></div>
                <?php if ($deltaRegs !== null): ?>
                  <div class="delta <?= $deltaRegs < 0 ? 'down' : '' ?>">
                    <span class="pill"><?= h(($deltaRegs >= 0 ? '+' : '') . (string)$deltaRegs . '%') ?></span>
                    <span>vs previous 7 days</span>
                  </div>
                <?php endif; ?>
              </div>

              <div class="card sm">
                <h3>Total cohort memberships</h3>
                <div class="metric"><?= h((string)$cohortTotal) ?></div>
                <div class="metric-sub">Last 7 days: <strong><?= h((string)$analytics['last7_cohort']) ?></strong> · Prev 7: <strong><?= h((string)$analytics['prev7_cohort']) ?></strong></div>
                <?php if ($deltaCoh !== null): ?>
                  <div class="delta <?= $deltaCoh < 0 ? 'down' : '' ?>">
                    <span class="pill"><?= h(($deltaCoh >= 0 ? '+' : '') . (string)$deltaCoh . '%') ?></span>
                    <span>vs previous 7 days</span>
                  </div>
                <?php endif; ?>
              </div>

              <div class="card sm">
                <h3>Conversion</h3>
                <div class="metric"><?= h((string)$conv) ?>%</div>
                <div class="metric-sub">Cohort memberships / registrations</div>
              </div>

              <div class="card md">
                <div class="chart-title">
                  <div class="label">Daily registrations (last 14 days)</div>
                  <div class="hint">Gold</div>
                </div>
                <div class="bars" id="bars-reg"></div>
                <div class="bar-labels" id="labels-reg"></div>
              </div>

              <div class="card md">
                <div class="chart-title">
                  <div class="label">Daily cohort memberships (last 14 days)</div>
                  <div class="hint">Teal</div>
                </div>
                <div class="bars" id="bars-cohort"></div>
                <div class="bar-labels" id="labels-cohort"></div>
              </div>

              <div class="card md">
                <div class="chart-title">
                  <div class="label">Registrations by tier</div>
                  <div class="hint">Includes “Unscored”</div>
                </div>
                <div class="pie-wrap">
                  <div class="pie" id="pie-reg" aria-label="Registrations by tier pie chart"></div>
                  <div class="legend" id="legend-reg"></div>
                </div>
              </div>

              <div class="card md">
                <div class="chart-title">
                  <div class="label">Cohort memberships by tier</div>
                  <div class="hint">Scored only</div>
                </div>
                <div class="pie-wrap">
                  <div class="pie" id="pie-cohort" aria-label="Cohort memberships by tier pie chart"></div>
                  <div class="legend" id="legend-cohort"></div>
                </div>
              </div>
            </div>

            <script>
              window.__ANALYTICS__ = <?= json_encode($analytics, JSON_UNESCAPED_SLASHES) ?>;
            </script>
          </div>

          <div class="tab-panel" role="tabpanel" id="panel-reg" aria-labelledby="tab-reg" hidden>
            <p class="tab-panel-desc">Captured when visitors start the test (before questions).</p>
            <?php if ($count === 0): ?>
              <div class="table-card">
                <div class="empty">
                  <div class="empty-icon" aria-hidden="true"></div>
                  <p>No registrations yet.</p>
                </div>
              </div>
            <?php else: ?>
              <div class="toolbar">
                <div class="search-wrap">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                  <input type="search" id="reg-search" placeholder="Search name, school, email, phone, tier…" aria-label="Filter registrations">
                </div>
                <form method="get" action="index.php" style="display:inline">
                  <input type="hidden" name="tab" value="reg">
                  <input type="hidden" name="reg_page" value="1">
                  <label class="cell-muted" style="font-size:0.8rem; margin-right:0.35rem">Per page</label>
                  <select class="filter-select" name="reg_limit" aria-label="Registrations per page" onchange="this.form.submit()">
                    <?php foreach ([10,25,50,100] as $lim): ?>
                      <option value="<?= (int)$lim ?>" <?= $regLimit === (int)$lim ? 'selected' : '' ?>><?= (int)$lim ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input type="hidden" name="cohort_page" value="<?= (int)$cohortPage ?>">
                  <input type="hidden" name="cohort_limit" value="<?= (int)$cohortLimit ?>">
                </form>
                <select class="filter-select" id="reg-tier" aria-label="Filter registrations by tier">
                  <option value="">All tiers</option>
                  <option value="category leader">Category Leader</option>
                  <option value="future-ready school">Future-Ready School</option>
                  <option value="developing school">Developing School</option>
                  <option value="at risk">At Risk</option>
                  <option value="__unscored__">Unscored</option>
                </select>
                <form method="post" action="index.php" style="display:inline">
                  <input type="hidden" name="action" value="export_registrations">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <button type="submit" class="btn btn-ghost">Export CSV</button>
                </form>
                <?php if ($regMissingCount > 0): ?>
                  <form method="post" action="index.php" style="display:inline" data-confirm="Backfill score/tier for <?= (int)$regMissingCount ?> registration(s) from Cohort Memberships (match by email)?">
                    <input type="hidden" name="action" value="backfill_reg_scores">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <button type="submit" class="btn btn-ghost" onclick="return confirm(this.form.dataset.confirm);">Sync score/tier</button>
                  </form>
                <?php endif; ?>
              </div>
              <div class="table-card">
                <div class="table-scroll">
                  <table>
                    <thead>
                      <tr>
                        <th>#</th>
                        <th>Registered</th>
                        <th>Name</th>
                        <th>School</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Role</th>
                        <th>Score</th>
                        <th>Tier</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody id="reg-tbody">
                      <?php foreach ($rows as $r): ?>
                        <?php
                        $name = $r['first_name'] . ' ' . $r['last_name'];
                        $hay = strtolower($name . ' ' . $r['school'] . ' ' . $r['email'] . ' ' . $r['phone'] . ' ' . $r['designation'] . ' ' . (string)($r['tier_label'] ?? '') . ' ' . (string)($r['score'] ?? ''));
                        ?>
                        <tr data-search="<?= h($hay) ?>" data-tier="<?= h(strtolower((string)($r['tier_label'] ?? ''))) ?>">
                          <td class="cell-muted">#<?= h((string)$r['id']) ?></td>
                          <td class="cell-muted"><?= h($r['created_at']) ?></td>
                          <td class="cell-strong"><?= h($name) ?></td>
                          <td><?= h($r['school']) ?></td>
                          <td class="cell-email"><a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a></td>
                          <td><a href="tel:<?= h(preg_replace('/\s+/', '', $r['phone'])) ?>" style="color:inherit;text-decoration:none"><?= h($r['phone']) ?></a></td>
                          <td><?= h($r['designation']) ?></td>
                          <td><?= $r['score'] === null ? '<span class="cell-muted">—</span>' : '<span class="score-pill">' . h((string)(int)$r['score']) . '/100</span>' ?></td>
                          <td><?= $r['tier_label'] === null ? '<span class="cell-muted">—</span>' : h((string)$r['tier_label']) ?></td>
                          <td class="cell-actions">
                            <form class="row-delete-form" method="post" action="index.php" data-confirm="<?= h('Delete this registration for ' . $name . '? This cannot be undone.') ?>" onsubmit="return confirm(this.dataset.confirm);">
                              <input type="hidden" name="action" value="delete_registration">
                              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <button type="submit" class="btn-delete" aria-label="Delete registration <?= h($name) ?>">Delete</button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?= render_pager('reg', $regPage, $regLimit, $regTotal) ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="tab-panel" role="tabpanel" id="panel-cohort" aria-labelledby="tab-cohort" hidden>
            <p class="tab-panel-desc">Cohort membership when someone confirms in the modal after the test.</p>
            <?php if ($cohortCount === 0): ?>
              <div class="table-card">
                <div class="empty">
                  <div class="empty-icon" aria-hidden="true"></div>
                  <p>No cohort sign-ups yet.</p>
                </div>
              </div>
            <?php else: ?>
              <div class="toolbar">
                <div class="search-wrap">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                  <input type="search" id="cohort-search" placeholder="Search name, school, email, tier…" aria-label="Filter cohort sign-ups">
                </div>
                <form method="get" action="index.php" style="display:inline">
                  <input type="hidden" name="tab" value="cohort">
                  <input type="hidden" name="cohort_page" value="1">
                  <label class="cell-muted" style="font-size:0.8rem; margin-right:0.35rem">Per page</label>
                  <select class="filter-select" name="cohort_limit" aria-label="Cohort per page" onchange="this.form.submit()">
                    <?php foreach ([10,25,50,100] as $lim): ?>
                      <option value="<?= (int)$lim ?>" <?= $cohortLimit === (int)$lim ? 'selected' : '' ?>><?= (int)$lim ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input type="hidden" name="reg_page" value="<?= (int)$regPage ?>">
                  <input type="hidden" name="reg_limit" value="<?= (int)$regLimit ?>">
                </form>
                <select class="filter-select" id="cohort-tier" aria-label="Filter cohort memberships by tier">
                  <option value="">All tiers</option>
                  <option value="category leader">Category Leader</option>
                  <option value="future-ready school">Future-Ready School</option>
                  <option value="developing school">Developing School</option>
                  <option value="at risk">At Risk</option>
                </select>
                <form method="post" action="index.php" style="display:inline">
                  <input type="hidden" name="action" value="export_cohort">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <button type="submit" class="btn btn-ghost">Export CSV</button>
                </form>
              </div>
              <div class="table-card">
                <div class="table-scroll">
                  <table>
                    <thead>
                      <tr>
                        <th>#</th>
                        <th>Joined</th>
                        <th>Name</th>
                        <th>School</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Role</th>
                        <th>Score</th>
                        <th>Tier</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody id="cohort-tbody">
                      <?php foreach ($cohortRows as $r): ?>
                        <?php
                        $name = $r['first_name'] . ' ' . $r['last_name'];
                        $hay = strtolower($name . ' ' . $r['school'] . ' ' . $r['email'] . ' ' . $r['phone'] . ' ' . $r['designation'] . ' ' . $r['tier_label'] . ' ' . (string)$r['score']);
                        ?>
                        <tr data-search="<?= h($hay) ?>" data-tier="<?= h(strtolower((string)$r['tier_label'])) ?>">
                          <td class="cell-muted">#<?= h((string)$r['id']) ?></td>
                          <td class="cell-muted"><?= h($r['created_at']) ?></td>
                          <td class="cell-strong"><?= h($name) ?></td>
                          <td><?= h($r['school']) ?></td>
                          <td class="cell-email"><a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a></td>
                          <td><a href="tel:<?= h(preg_replace('/\s+/', '', $r['phone'])) ?>" style="color:inherit;text-decoration:none"><?= h($r['phone']) ?></a></td>
                          <td><?= h($r['designation']) ?></td>
                          <td><span class="score-pill"><?= h((string)(int)$r['score']) ?>/100</span></td>
                          <td><?= h($r['tier_label']) ?></td>
                          <td class="cell-actions">
                            <form class="row-delete-form" method="post" action="index.php" data-confirm="<?= h('Delete this cohort membership for ' . $name . '? This cannot be undone.') ?>" onsubmit="return confirm(this.dataset.confirm);">
                              <input type="hidden" name="action" value="delete_cohort">
                              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <button type="submit" class="btn-delete" aria-label="Delete cohort row <?= h($name) ?>">Delete</button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?= render_pager('cohort', $cohortPage, $cohortLimit, $cohortTotal) ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php if ($loggedIn && $dbError === null): ?>
  <script>
    (function () {
      function fmtShortDate(iso) {
        // iso: YYYY-MM-DD
        try {
          var parts = iso.split('-');
          var d = new Date(Date.UTC(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10)));
          return d.toLocaleDateString(undefined, { month: 'short', day: '2-digit' });
        } catch (e) { return iso; }
      }

      function renderBars(containerId, labelsId, values, labels, colorClass) {
        var el = document.getElementById(containerId);
        var lab = document.getElementById(labelsId);
        if (!el || !lab) return;
        var max = 0;
        for (var i = 0; i < values.length; i++) max = Math.max(max, values[i] || 0);
        max = Math.max(1, max);

        el.innerHTML = values.map(function (v) {
          var h = Math.round(((v || 0) / max) * 100);
          return '<div class=\"bar ' + (colorClass || '') + '\" title=\"' + (v || 0) + '\"><span style=\"height:' + h + '%\"></span></div>';
        }).join('');

        lab.innerHTML = labels.map(function (d, i) {
          // show every 2nd label for readability
          return '<span>' + (i % 2 === 0 ? fmtShortDate(d) : '') + '</span>';
        }).join('');
      }

      function renderPie(pieId, legendId, rows, palette) {
        var pie = document.getElementById(pieId);
        var legend = document.getElementById(legendId);
        if (!pie || !legend) return;
        rows = rows || [];
        var total = rows.reduce(function (s, r) { return s + (parseInt(r.c, 10) || 0); }, 0);
        if (total <= 0) {
          pie.style.background = 'conic-gradient(from 90deg, rgba(255,255,255,0.08) 0 100%)';
          legend.innerHTML = '<div class=\"cell-muted\">No data yet.</div>';
          return;
        }

        var stops = [];
        var start = 0;
        legend.innerHTML = rows.map(function (r, idx) {
          var c = parseInt(r.c, 10) || 0;
          var pct = (c / total) * 100;
          var col = palette[idx % palette.length];
          var end = start + pct;
          stops.push(col + ' ' + start.toFixed(2) + '% ' + end.toFixed(2) + '%');
          start = end;
          return '<div class=\"legend-row\">'
            + '<div class=\"legend-left\"><span class=\"dot\" style=\"background:' + col + '\"></span>'
            + '<div class=\"legend-name\">' + String(r.tier || '') + '</div></div>'
            + '<div class=\"legend-val\">' + c + '</div>'
            + '</div>';
        }).join('');
        pie.style.background = 'conic-gradient(from 90deg, ' + stops.join(',') + ')';
      }

      var a = window.__ANALYTICS__ || null;
      if (a) {
        renderBars('bars-reg', 'labels-reg', a.regs || [], a.days || [], '');
        renderBars('bars-cohort', 'labels-cohort', a.cohorts || [], a.days || [], 'teal');
        var paletteReg = ['#E8A427', '#5BA8E5', '#00C6A7', '#E05050', 'rgba(255,255,255,0.18)'];
        var paletteCoh = ['#00C6A7', '#5BA8E5', '#E8A427', '#E05050'];
        renderPie('pie-reg', 'legend-reg', a.tier_reg || [], paletteReg);
        renderPie('pie-cohort', 'legend-cohort', a.tier_cohort || [], paletteCoh);
      }

      function bindFilter(inputId, tbodyId) {
        var input = document.getElementById(inputId);
        var tbody = document.getElementById(tbodyId);
        if (!input || !tbody) return;
        var tierSel = document.getElementById(inputId === 'reg-search' ? 'reg-tier' : (inputId === 'cohort-search' ? 'cohort-tier' : ''));

        function apply() {
          var q = input.value.trim().toLowerCase();
          var tier = tierSel ? (tierSel.value || '') : '';
          tbody.querySelectorAll('tr').forEach(function (tr) {
            var hay = (tr.getAttribute('data-search') || '');
            var rowTier = (tr.getAttribute('data-tier') || '').toLowerCase();
            var matchesText = (q === '' || hay.indexOf(q) !== -1);
            var matchesTier = true;
            if (tier !== '') {
              if (tier === '__unscored__') matchesTier = rowTier === '';
              else matchesTier = rowTier === tier;
            }
            tr.classList.toggle('hidden', !(matchesText && matchesTier));
          });
        }

        input.addEventListener('input', apply);
        if (tierSel) tierSel.addEventListener('change', apply);
      }
      bindFilter('reg-search', 'reg-tbody');
      bindFilter('cohort-search', 'cohort-tbody');

      var tablist = document.querySelector('.tab-list');
      if (!tablist) return;
      var tabs = [].slice.call(tablist.querySelectorAll('[role="tab"]'));

      function selectTab(tab) {
        tabs.forEach(function (t) {
          var on = t === tab;
          t.setAttribute('aria-selected', on ? 'true' : 'false');
          t.setAttribute('tabindex', on ? '0' : '-1');
          var p = document.getElementById(t.getAttribute('aria-controls'));
          if (p) p.hidden = !on;
        });
        tab.focus();
      }

      tabs.forEach(function (tab) {
        tab.addEventListener('click', function () { selectTab(tab); });
        tab.addEventListener('keydown', function (e) {
          var i = tabs.indexOf(tab);
          if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
            e.preventDefault();
            selectTab(tabs[(i + 1) % tabs.length]);
          } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
            e.preventDefault();
            selectTab(tabs[(i - 1 + tabs.length) % tabs.length]);
          } else if (e.key === 'Home') {
            e.preventDefault();
            selectTab(tabs[0]);
          } else if (e.key === 'End') {
            e.preventDefault();
            selectTab(tabs[tabs.length - 1]);
          }
        });
      });

      var params = new URLSearchParams(window.location.search);
      var tab = params.get('tab') || '';
      if (tab === 'reg' && tabs[1]) selectTab(tabs[1]);
      if (tab === 'cohort' && tabs[2]) selectTab(tabs[2]);
    })();
  </script>
  <?php endif; ?>
</body>
</html>
