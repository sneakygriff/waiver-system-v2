<?php
require __DIR__.'/../vendor/autoload.php';
$cfg = require __DIR__.'/../config/config.php';
use App\{Database, Auth, AdminController};

$db = new Database($cfg['db']);
$action = $_GET['action'] ?? 'home';

if ($action === 'login') {
  if ($_SERVER['REQUEST_METHOD']==='POST') {
    $ok = Auth::login($cfg, $db, $_POST['email']??'', $_POST['password']??'');
    if ($ok) { header('Location: /admin.php'); exit; }
    $err = 'Invalid credentials';
  }
  ?>
  <!doctype html><html><head><meta charset="utf-8"><title>Admin Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
  <body class="container py-5">
  <h1>Admin Login</h1>
  <?php if (!empty($err)) echo '<div class="alert alert-danger">'.$err.'</div>'; ?>
  <form method="post" class="mt-3" style="max-width:360px">
    <div class="mb-3"><label class="form-label">Email</label><input name="email" class="form-control" required></div>
    <div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required></div>
    <button class="btn btn-primary">Login</button>
  </form>
  </body></html>
  <?php
  exit;
}

Auth::ensureLogin($cfg, $db);
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf'];

$admin = new AdminController($cfg, $db);

# Link selected responses/instances to reservation
if ($action === 'link_selected' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(403); exit('Invalid CSRF token'); }
  $reservation = trim($_POST['reservation_id'] ?? '');
  $responseIds = $_POST['response_ids'] ?? [];
  $instanceIds = $_POST['ids'] ?? [];
  $instanceIds = array_map('intval', $instanceIds);

  if (!empty($responseIds)) {
    $responseIds = array_map('intval', $responseIds);
    $in = implode(',', array_fill(0, count($responseIds), '?'));
    $st = $db->pdo()->prepare("SELECT waiver_instance_id FROM waiver_responses WHERE id IN ($in)");
    $st->execute($responseIds);
    $fetched = array_map('intval', array_column($st->fetchAll(), 'waiver_instance_id'));
    $instanceIds = array_values(array_unique(array_merge($instanceIds, $fetched)));
  }

  require_once __DIR__.'/../src/WaiverController.php';
  $ctl = new \App\WaiverController($cfg, $db);
  $res = $ctl->linkWaiversToReservation($reservation, $instanceIds, null, false);
  header('Location: /admin.php?action=home&linked='.(int)($res['updated'] ?? 0).'#unlinked'); exit;
}

# Link whole group via modal
if ($action === 'link_group' && $_SERVER['REQUEST_METHOD']==='POST') {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(403); exit('Invalid CSRF token'); }
  $reservation = trim($_POST['reservation_id'] ?? '');
  $groupToken  = trim($_POST['group_token'] ?? '');
  $includePending = !empty($_POST['include_pending']);
  require_once __DIR__.'/../src/WaiverController.php';
  $ctl = new \App\WaiverController($cfg, $db);
  $res = $ctl->linkWaiversToReservation($reservation, [], $groupToken, $includePending);
  header('Location: /admin.php?action=home&linked_groups='.(int)($res['updated'] ?? 0).'#groups'); exit;
}

# Import DOCX
if ($action === 'import_docx') {
  $tid = (int)($_GET['template_id'] ?? 0);
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(403); exit('Invalid CSRF token'); }
    if (!isset($_FILES['docx']) || $_FILES['docx']['error'] !== UPLOAD_ERR_OK) { $err = 'Upload failed'; }
    else {
      $tmp = $_FILES['docx']['tmp_name']; $name = $_FILES['docx']['name']; $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      if ($ext !== 'docx') { $err = 'Please upload a .docx file'; }
      else {
        require_once __DIR__.'/../src/DocxImportService.php';
        $svc = new \App\DocxImportService();
        try {
          $res = $svc->convertDocxToShortcodeHtml($tmp);
          $contentHtml = $res['content_html'];
          $fieldsJson  = json_encode($res['fields_json'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
        } catch (\Throwable $e) { $err='Could not convert document: '.$e->getMessage(); }
      }
    }
  }
  ?>
  <!doctype html><html><head><meta charset="utf-8"><title>Import from Word</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
  <body class="container py-4">
    <h1>Import from Word (DOCX)</h1>
    <p class="text-muted">Use placeholders like <code>{{text:full_name!}}</code>, <code>{{radio:consent_photo:Yes|No}}</code>, <code>{{signature}}</code>.</p>
    <?php if (!empty($err)): ?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="mb-4">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
      <div class="mb-3"><input type="file" name="docx" accept=".docx" class="form-control" required></div>
      <button class="btn btn-primary">Upload & Convert</button>
      <a href="/admin.php" class="btn btn-link">Cancel</a>
    </form>

    <?php if (!empty($contentHtml)): ?>
      <h3>Preview & Publish</h3>
      <form method="post" action="/admin.php?action=publish&template_id=<?=$tid?>">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
        <div class="mb-3"><label class="form-label">Title</label><input class="form-control" name="title" value="Waiver" required></div>
        <div class="mb-3"><label class="form-label">Description (optional)</label><textarea class="form-control" name="description" rows="2"></textarea></div>
        <div class="mb-3"><label class="form-label">Fields JSON (auto-detected)</label><textarea class="form-control" name="fields_json" rows="10"><?=htmlspecialchars($fieldsJson ?? '[]')?></textarea></div>
        <div class="mb-3"><label class="form-label">Content HTML (converted)</label><textarea class="form-control" name="content_html" rows="12"><?=htmlspecialchars($contentHtml ?? '')?></textarea></div>
        <div class="mb-3"><label class="form-label">Print/PDF CSS (optional)</label><textarea class="form-control" name="print_css" rows="6">.inline-line{border-bottom:1px solid #000; min-width:220px; display:inline-block;} .checkbox{display:inline-block; border:1px solid #000; width:14px; height:14px; text-align:center; line-height:14px; font-size:12px; margin:0 6px;}</textarea></div>
        <button class="btn btn-success">Publish Version</button>
      </form>
    <?php endif; ?>
  </body></html>
  <?php
  exit;
}

if ($action === 'publish') {
  $tid = (int)($_GET['template_id'] ?? 0);
  if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(403); exit('Invalid CSRF token'); }
    $fields = $_POST['fields_json'] ?? '[]';
    $title = $_POST['title'] ?? 'Waiver';
    $desc  = $_POST['description'] ?? '';
    $contentHtml = $_POST['content_html'] ?? null;
    $printCss = $_POST['print_css'] ?? null;
    $admin->publishVersion($tid, $title, $desc, $fields, $_SESSION['admin_id'], $contentHtml, $printCss);
    header('Location: /admin.php'); exit;
  }
  ?>
  <!doctype html><html><head><meta charset="utf-8"><title>Publish Version</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
  <body class="container py-4">
  <h1>Publish Template Version</h1>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
    <div class="mb-3"><label class="form-label">Title</label><input name="title" class="form-control" value="Guest Liability Waiver" required></div>
    <div class="mb-3"><label class="form-label">Description (optional)</label><textarea name="description" class="form-control" rows="3"></textarea></div>
    <div class="mb-3"><label class="form-label">Fields JSON</label><textarea name="fields_json" class="form-control" rows="12">[
  {"key":"full_name","label":"Full name","type":"text","required":true,"maxLength":120},
  {"key":"age_confirm","label":"I am 18+","type":"radio","options":["Yes","No"],"required":true},
  {"key":"medical","label":"Relevant medical conditions","type":"textarea","required":false},
  {"key":"agree_rules","label":"I agree to the house rules","type":"radio","options":["Yes","No"],"required":true}
]</textarea></div>
    <div class="mb-3"><label class="form-label">Content HTML (optional)</label><textarea name="content_html" class="form-control" rows="10"></textarea></div>
    <div class="mb-3"><label class="form-label">Print/PDF CSS (optional)</label><textarea name="print_css" class="form-control" rows="6"></textarea></div>
    <button class="btn btn-primary">Publish</button>
  </form>
  </body></html>
  <?php
  exit;
}

if ($action === 'home') {
  $templates = $admin->listTemplates();
  $waivers = $admin->listWaivers();
  $stats = $admin->stats();

  $days = isset($_GET['days']) && $_GET['days'] !== '' ? (int)$_GET['days'] : 30;
  $q = isset($_GET['res_q']) ? trim($_GET['res_q']) : null;
  $byRes = $admin->responsesByReservation($days, $q ?: null);

  $days_group = isset($_GET['days_group']) && $_GET['days_group'] !== '' ? (int)$_GET['days_group'] : 30;
  $gq = isset($_GET['group_q']) ? trim($_GET['group_q']) : '';
  $byGroup = $admin->responsesByGroupToken($days_group, $gq);

  $days_unlinked = isset($_GET['days_unlinked']) && $_GET['days_unlinked'] !== '' ? (int)$_GET['days_unlinked'] : 7;
  $group_q_unlinked = isset($_GET['group_q_unlinked']) ? trim($_GET['group_q_unlinked']) : '';
  $search_unlinked = isset($_GET['search_unlinked']) ? trim($_GET['search_unlinked']) : '';
  $unlinkedResponses = $admin->listUnlinkedResponses($days_unlinked, $search_unlinked, $group_q_unlinked, 500);
  ?>
  <!doctype html><html><head><meta charset="utf-8"><title>Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
  <body class="container py-4">
  <h1 class="mb-4">Waiver Admin</h1>

  <div class="mb-4">
    <form method="post" action="/admin.php?action=create_template" class="d-flex gap-2">
      <input class="form-control" name="name" placeholder="New template name" required>
      <button class="btn btn-success">Create</button>
    </form>
  </div>

  <h3>Templates</h3>
  <table class="table table-sm"><thead><tr><th>ID</th><th>Name</th><th>Latest Version</th><th>Actions</th></tr></thead><tbody>
  <?php foreach ($templates as $t): ?>
    <tr>
      <td><?=htmlspecialchars($t['id'])?></td>
      <td><?=htmlspecialchars($t['name'])?></td>
      <td><?=htmlspecialchars($t['latest_version'] ?? '-')?></td>
      <td>
        <a class="btn btn-sm btn-outline-primary me-2" href="/admin.php?action=publish&template_id=<?=$t['id']?>">Publish Version</a>
        <a class="btn btn-sm btn-outline-secondary" href="/admin.php?action=import_docx&template_id=<?=$t['id']?>">Import from Word</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>

  <div class="row g-3 my-4">
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Completed (lifetime)</div><div class="h3 mb-0"><?= (int)$stats['total_completed'] ?></div></div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Completed today (UTC)</div><div class="h3 mb-0"><?= (int)$stats['completed_today'] ?></div></div></div></div>
    <div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Completed last 7 days</div><div class="h3 mb-0"><?= (int)$stats['completed_7d'] ?></div></div></div></div>
  </div>

  <h3>Completions by Template</h3>
  <table class="table table-sm"><thead><tr><th>Template</th><th class="text-end">Completed</th></tr></thead><tbody>
  <?php foreach ($stats['by_template'] as $row): ?>
    <tr><td><?= htmlspecialchars($row['template']) ?></td><td class="text-end"><?= (int)$row['cnt'] ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>

  <h3 class="mt-5">Signed Waivers by Reservation (from responses)</h3>
  <form class="row g-2 mb-3" method="get" action="/admin.php"><input type="hidden" name="action" value="home">
    <div class="col-auto"><label class="form-label">Days</label><input class="form-control" type="number" name="days" min="0" value="<?= htmlspecialchars((string)$days) ?>"><div class="form-text">0 = all time</div></div>
    <div class="col-auto"><label class="form-label">Reservation search</label><input class="form-control" type="text" name="res_q" placeholder="e.g., RES-123" value="<?= htmlspecialchars((string)($q ?? '')) ?>"></div>
    <div class="col-auto align-self-end"><button class="btn btn-primary">Filter</button></div>
  </form>
  <table class="table table-sm"><thead><tr><th>Reservation ID</th><th class="text-end">Responses</th><th>Last signed at (UTC)</th></tr></thead><tbody>
  <?php foreach ($byRes as $row): ?><tr><td><?= htmlspecialchars($row['reservation_id']) ?></td><td class="text-end"><?= (int)$row['responses_count'] ?></td><td><?= htmlspecialchars($row['last_signed_at']) ?></td></tr><?php endforeach; ?>
  <?php if (empty($byRes)): ?><tr><td colspan="3" class="text-muted">No data.</td></tr><?php endif; ?>
  </tbody></table>

  <a id="groups"></a>
  <?php if (isset($_GET['linked_groups'])): ?><div class="alert alert-success mb-3">Linked <?= (int)$_GET['linked_groups'] ?> waiver(s) to the reservation.</div><?php endif; ?>
  <h3 class="mt-5">Signed Waivers by Group Token (from responses)</h3>
  <form class="row g-2 mb-3" method="get" action="/admin.php"><input type="hidden" name="action" value="home">
    <div class="col-auto"><label class="form-label">Days</label><input class="form-control" type="number" name="days_group" min="0" value="<?= htmlspecialchars((string)$days_group) ?>"><div class="form-text">0 = all time</div></div>
    <div class="col-auto"><label class="form-label">Group token search</label><input class="form-control" type="text" name="group_q" placeholder="ab12cd..." value="<?= htmlspecialchars($gq) ?>"></div>
    <div class="col-auto align-self-end"><button class="btn btn-primary">Filter</button></div>
  </form>
  <table class="table table-sm align-middle">
    <thead><tr><th>Group token</th><th class="text-end">Responses</th><th>Last signed at (UTC)</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($byGroup as $row): ?>
        <tr>
          <td><?= htmlspecialchars($row['group_token']) ?></td>
          <td class="text-end"><?= (int)$row['responses_count'] ?></td>
          <td><?= htmlspecialchars($row['last_signed_at']) ?></td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-primary"
              data-bs-toggle="modal" data-bs-target="#linkGroupModal"
              data-group="<?= htmlspecialchars($row['group_token']) ?>"
              data-count="<?= (int)$row['responses_count'] ?>">
              Link to reservation…
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($byGroup)): ?><tr><td colspan="4" class="text-muted">No data.</td></tr><?php endif; ?>
    </tbody>
  </table>

  <a id="unlinked"></a>
  <?php if (isset($_GET['linked'])): ?><div class="alert alert-success">Linked <?= (int)$_GET['linked'] ?> waiver(s) to the reservation.</div><?php endif; ?>
  <h3 class="mt-5">Unlinked Completed Responses</h3>
  <form class="row g-2 mb-3" method="get" action="/admin.php">
    <input type="hidden" name="action" value="home">
    <div class="col-auto"><label class="form-label">Days</label><input class="form-control" type="number" name="days_unlinked" min="0" value="<?= htmlspecialchars((string)$days_unlinked) ?>"><div class="form-text">0 = all time</div></div>
    <div class="col-auto"><label class="form-label">Group token</label><input class="form-control" type="text" name="group_q_unlinked" placeholder="ab12cd..." value="<?= htmlspecialchars($group_q_unlinked) ?>"></div>
    <div class="col-auto"><label class="form-label">Search</label><input class="form-control" type="text" name="search_unlinked" placeholder="name/email/template" value="<?= htmlspecialchars($search_unlinked) ?>"></div>
    <div class="col-auto align-self-end"><button class="btn btn-outline-primary">Filter</button></div>
  </form>

  <form method="post" action="/admin.php?action=link_selected" class="mb-3">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <div class="input-group mb-2" style="max-width:520px">
      <span class="input-group-text">Reservation ID</span>
      <input name="reservation_id" class="form-control" placeholder="e.g., RES-123" required>
      <button class="btn btn-primary">Link Selected</button>
    </div>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead><tr>
          <th><input type="checkbox" id="chk_all" onclick="document.querySelectorAll('.chk-row').forEach(c=>c.checked=this.checked)"></th>
          <th>Response ID</th><th>Signed at (UTC)</th><th>Signer / Guest</th><th>Guest email</th><th>Template</th><th>Group</th>
        </tr></thead>
        <tbody>
        <?php foreach ($unlinkedResponses as $r): ?>
          <tr>
            <td><input type="checkbox" class="chk-row" name="response_ids[]" value="<?= (int)$r['response_id'] ?>"></td>
            <td><?= (int)$r['response_id'] ?></td>
            <td><?= htmlspecialchars($r['signed_at']) ?></td>
            <td><?= htmlspecialchars($r['signer_full_name'] ?: $r['guest_name'] ?: '-') ?></td>
            <td><?= htmlspecialchars($r['guest_email'] ?: '-') ?></td>
            <td><?= htmlspecialchars($r['template_title']) ?></td>
            <td><?= htmlspecialchars($r['group_token'] ?: '-') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($unlinkedResponses)): ?><tr><td colspan="7" class="text-muted">No unlinked signed responses.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </form>

  <!-- Link Group -> Reservation Modal -->
  <div class="modal fade" id="linkGroupModal" tabindex="-1" aria-labelledby="linkGroupModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <form method="post" action="/admin.php?action=link_group" class="modal-content">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="group_token" id="modal_group_token">
        <div class="modal-header"><h5 class="modal-title" id="linkGroupModalLabel">Link group to reservation</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Group token</label><div class="form-control-plaintext"><code id="display_group_token"></code></div></div>
          <div class="mb-3"><label class="form-label">Reservation ID</label><input name="reservation_id" class="form-control" placeholder="e.g., RES-123" required></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="include_pending" name="include_pending"><label class="form-check-label" for="include_pending">Also link pending (not yet signed) waivers in this group</label></div>
          <div class="form-text mt-2">Responses in this group: <span id="display_group_count">0</span></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Link</button></div>
      </form>
    </div>
  </div>

  <script>
    const linkGroupModal = document.getElementById('linkGroupModal');
    if (linkGroupModal) {
      linkGroupModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const group = button.getAttribute('data-group') || '';
        const count = button.getAttribute('data-count') || '0';
        this.querySelector('#modal_group_token').value = group;
        this.querySelector('#display_group_token').textContent = group;
        this.querySelector('#display_group_count').textContent = count;
        this.querySelector('input[name="reservation_id"]').value = '';
        this.querySelector('#include_pending').checked = false;
      });
    }
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  </body></html>
  <?php
  exit;
}

if ($action === 'create_template' && $_SERVER['REQUEST_METHOD']==='POST') {
  $id = $admin->createTemplate($_POST['name'], $_SESSION['admin_id']);
  header('Location: /admin.php'); exit;
}
