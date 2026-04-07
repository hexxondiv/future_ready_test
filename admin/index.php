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
        if ($loggedIn && ($action === 'delete_registration' || $action === 'delete_cohort')) {
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
    }
}

$rows = [];
$cohortRows = [];
$dbError = null;
if ($loggedIn) {
    try {
        $pdo = futre_db();
        $stmt = $pdo->query(
            'SELECT id, first_name, last_name, school, email, phone, designation, created_at
             FROM registrations ORDER BY id DESC'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cstmt = $pdo->query(
            'SELECT id, first_name, last_name, school, email, phone, designation, score, tier_label, created_at
             FROM cohort_membership ORDER BY id DESC'
        );
        $cohortRows = $cstmt->fetchAll(PDO::FETCH_ASSOC);
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
          <a class="btn btn-ghost" href="../index.html">← Public site</a>
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
            <button type="button" class="tab" role="tab" id="tab-reg" aria-selected="true" aria-controls="panel-reg" tabindex="0">
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

          <div class="tab-panel" role="tabpanel" id="panel-reg" aria-labelledby="tab-reg">
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
                  <input type="search" id="reg-search" placeholder="Search name, school, email, phone…" aria-label="Filter registrations">
                </div>
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
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody id="reg-tbody">
                      <?php foreach ($rows as $r): ?>
                        <?php
                        $name = $r['first_name'] . ' ' . $r['last_name'];
                        $hay = strtolower($name . ' ' . $r['school'] . ' ' . $r['email'] . ' ' . $r['phone'] . ' ' . $r['designation']);
                        ?>
                        <tr data-search="<?= h($hay) ?>">
                          <td class="cell-muted">#<?= h((string)$r['id']) ?></td>
                          <td class="cell-muted"><?= h($r['created_at']) ?></td>
                          <td class="cell-strong"><?= h($name) ?></td>
                          <td><?= h($r['school']) ?></td>
                          <td class="cell-email"><a href="mailto:<?= h($r['email']) ?>"><?= h($r['email']) ?></a></td>
                          <td><a href="tel:<?= h(preg_replace('/\s+/', '', $r['phone'])) ?>" style="color:inherit;text-decoration:none"><?= h($r['phone']) ?></a></td>
                          <td><?= h($r['designation']) ?></td>
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
                        <tr data-search="<?= h($hay) ?>">
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
      function bindFilter(inputId, tbodyId) {
        var input = document.getElementById(inputId);
        var tbody = document.getElementById(tbodyId);
        if (!input || !tbody) return;
        input.addEventListener('input', function () {
          var q = input.value.trim().toLowerCase();
          tbody.querySelectorAll('tr').forEach(function (tr) {
            var hay = (tr.getAttribute('data-search') || '');
            tr.classList.toggle('hidden', q !== '' && hay.indexOf(q) === -1);
          });
        });
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
      if (params.get('tab') === 'cohort' && tabs[1]) {
        selectTab(tabs[1]);
      }
    })();
  </script>
  <?php endif; ?>
</body>
</html>
