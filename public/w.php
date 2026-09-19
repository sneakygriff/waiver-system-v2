<?php
require __DIR__.'/../vendor/autoload.php';
$cfg = require __DIR__.'/../config/config.php';
use App\{Database, WaiverController, Utils};

// [W7] Fail-closed boot sentinel -- see Utils::assertNoPlaceholderSecrets doc.
// This guest-facing signing page is the most sensitive of the three (guests
// submit real signatures/PII here), so it must never come up misconfigured.
try {
  Utils::assertNoPlaceholderSecrets($cfg);
} catch (\Throwable $e) {
  http_response_code(500);
  exit('Server misconfigured: placeholder secret(s) still present. Refusing to start.');
}

$db = new Database($cfg['db']);
$ctl = new WaiverController($cfg, $db);

// [GVS-89] An expired PUBLIC (reception-QR) link: 410 Gone plus a small,
// phone-readable page in the instance's locale telling the guest to scan the
// reception QR again (which mints a fresh link). No form, no title. ONE
// renderer for both the GET (render gate) and the POST (submit gate, past
// the 60-min grace) so the two can never drift apart [gate 89-M4 P2-3].
function wv_render_expired_page(array $data): void {
  http_response_code(410);
  $lang = ($data['locale'] ?? 'ro') === 'en' ? 'en' : 'ro';
  ?><!doctype html><html lang="<?=$lang?>"><head><meta charset="utf-8"><title><?=htmlspecialchars((string)($data['error_title'] ?? ''))?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
  <body class="container py-4"><div class="alert alert-warning"><?=htmlspecialchars((string)$data['error'])?></div></body></html><?php
}

$token = $_GET['token'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $res = $ctl->submitGuestForm($token, $_POST);
  if (!empty($res['error'])) {
    $error = $res['error'];
    if (($res['error_code'] ?? null) === 'expired') {
      // Past expires_at + the submit grace: the same localized 410 page a GET
      // gets, not the generic 5xx banner below.
      wv_render_expired_page($res);
      exit;
    }
    if (!empty($res['http_status'])) {
      // [post-incident 2026-08-30 / Finding #10 + #B] An INTERNAL persistence
      // failure carries an explicit http_status (5xx) so the outage is VISIBLE
      // to uptime monitoring -- it previously returned HTTP 200, keeping the
      // failure invisible. Render its ref banner AND that 5xx DIRECTLY here,
      // then exit. We must NOT fall through to the renderGuestForm() path below:
      // if the catch's rollback UPDATE failed to revert the instance to
      // 'pending' (e.g. the DB is the thing that's broken), renderGuestForm()
      // would return 'Already completed' and w.php:below would call
      // http_response_code(404) -- DOWNGRADING the 500 and masking the outage
      // from alerts. User VALIDATION errors (no http_status) still fall through
      // to re-render the form so the guest can correct and retry, keeping 200.
      http_response_code((int)$res['http_status']);
      ?><!doctype html><html><head><meta charset="utf-8"><title>Waiver</title>
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
      <body class="container py-4"><div class="alert alert-danger"><?=htmlspecialchars($error)?></div></body></html><?php
      exit;
    }
  }
  else { $ok = true; $artifact = $res['artifact'] ?? null; }
}

if (!empty($ok)) {
  // Submission succeeded and the instance is now 'completed', so re-fetching via
  // renderGuestForm() would reject it ("already completed"). Render a standalone
  // thank-you page directly instead of clobbering the success path.
  ?><!doctype html><html><head><meta charset="utf-8"><title>Waiver complete</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
  <body class="container py-4"><div class="alert alert-success">Thank you! Your waiver is complete.</div></body></html><?php
  exit;
}

// [gate 89-M4 P2-2] A POST the controller REJECTED with a validation error
// ($error set, no http_status -- those exited above) re-renders the form
// under the SUBMIT gate's expiry clock (expires_at + 60-min grace), which is
// the one that just accepted this POST. Otherwise, inside the grace window,
// the no-grace render gate would 410 here: masking the validation error
// (incl. the adults-only copy) and killing a form the grace exists to keep
// usable. A plain GET always uses the no-grace render gate.
$data = !empty($error)
  ? $ctl->rerenderGuestFormAfterRejectedSubmit($token)
  : $ctl->renderGuestForm($token);
if (!empty($data['error'])) {
  if (($data['error_code'] ?? null) === 'expired') {
    wv_render_expired_page($data);
    exit;
  }
  http_response_code(404); echo htmlspecialchars($data['error']); exit;
}
$instance = $data['instance']; $fields = $data['fields'];
?>
<!doctype html><html><head><meta charset="utf-8"><title><?=htmlspecialchars($instance['title'])?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
<style>
#sig { border:1px solid #ccc; width:100%; height:180px; }
/* Choice rows for radio / consent fields rendered inline from content_html
   shortcodes (WaiverController::renderContentForWeb). All spans so they stay
   valid inside a <p>; flex gives each option a full-width, tappable row. */
.wv-choices { display:block; margin-top:8px; }
.wv-choice {
  display:flex; align-items:center; gap:.65rem;
  min-height:44px; padding:.4rem .7rem; margin:6px 0;
  border:1px solid #dcdcdc; border-radius:6px; background:#fff;
  cursor:pointer;
}
.wv-choice:hover { border-color:#86b7fe; background:#f8fbff; }
.wv-choice:focus-within { border-color:#86b7fe; box-shadow:0 0 0 .2rem rgba(13,110,253,.15); }
.wv-control { width:1.25rem; height:1.25rem; flex:0 0 auto; margin:0; cursor:pointer; }
.wv-choice-label { margin:0; cursor:pointer; line-height:1.35; }
</style>
</head>
<body class="container py-4">
  <h1 class="mb-2"><?=htmlspecialchars($instance['title'])?></h1>
  <?php if (!empty($instance['description'])): ?>
    <div class="alert alert-info"><?=nl2br(htmlspecialchars($instance['description']))?></div>
  <?php endif; ?>

  <?php if (!empty($error)): ?><div class="alert alert-danger"><?=htmlspecialchars($error)?></div><?php endif; ?>
  <?php if (!empty($ok)): ?>
    <div class="alert alert-success">Thank you! Your waiver is complete.</div>
  <?php else: ?>
  <form method="post">
    <?php if (!empty($instance['content_html'])): ?>
      <div class="mb-3"><?php echo $ctl->renderContentForWeb($instance['content_html'], $fields); ?></div>
    <?php else: ?>
      <?php foreach ($fields as $f): ?>
        <?php if ($f['type']==='gdpr_consent'): ?>
          <!-- [FK-Tconsent] Optional consent checkbox: rendered on its own,
               WITHOUT the shared "* = required" label markup above (it is
               never required, normalizeFields() guarantees $f['required'] is
               always false for this type) and without a top label, since the
               consent text itself is the label. -->
          <div class="mb-3 form-check">
            <input class="form-check-input" type="checkbox" name="<?=htmlspecialchars($f['key'])?>" id="f_<?=htmlspecialchars($f['key'])?>" value="1">
            <label class="form-check-label" for="f_<?=htmlspecialchars($f['key'])?>"><?=htmlspecialchars($f['label'])?></label>
          </div>
        <?php else: ?>
        <div class="mb-3">
          <label class="form-label"><?=htmlspecialchars($f['label'])?><?=!empty($f['required'])?' *':''?></label>
          <?php if ($f['type']==='text'): ?>
            <input name="<?=htmlspecialchars($f['key'])?>" class="form-control" <?=!empty($f['required'])?'required':''?> maxlength="<?=htmlspecialchars($f['maxLength'] ?? 255)?>">
          <?php elseif ($f['type']==='date'): ?>
            <input type="date" name="<?=htmlspecialchars($f['key'])?>" class="form-control" <?=!empty($f['required'])?'required':''?>>
          <?php elseif ($f['type']==='parental_consent'): ?>
            <input name="<?=htmlspecialchars($f['key'])?>" class="form-control" <?=!empty($f['required'])?'required':''?> maxlength="<?=htmlspecialchars($f['maxLength'] ?? 255)?>" placeholder="Parent/guardian full name">
          <?php elseif ($f['type']==='textarea'): ?>
            <textarea name="<?=htmlspecialchars($f['key'])?>" class="form-control" <?=!empty($f['required'])?'required':''?>></textarea>
          <?php elseif ($f['type']==='radio'): ?>
            <?php foreach ($f['options'] as $opt): ?>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="<?=htmlspecialchars($f['key'])?>" value="<?=htmlspecialchars($opt)?>" <?=!empty($f['required'])?'required':''?>>
                <label class="form-check-label"><?=htmlspecialchars($opt)?></label>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      <?php endforeach; ?>
      <div class="mb-3">
        <label class="form-label">Signature *</label>
        <canvas id="sig"></canvas>
        <input type="hidden" name="signature_data" id="signature_data" required>
        <button type="button" id="clear" class="btn btn-sm btn-outline-secondary mt-2">Clear</button>
      </div>
    <?php endif; ?>

    <button class="btn btn-primary">Submit</button>
  </form>

  <script>
    const canvas = document.getElementById('sig');
    if (canvas) {
      function fitCanvas() {
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = 180 * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
      }
      window.addEventListener('resize', fitCanvas);
      fitCanvas();
      const pad = new SignaturePad(canvas);
      document.querySelector('form').addEventListener('submit', (e) => {
        if (pad.isEmpty()) { alert('Please sign.'); e.preventDefault(); return; }
        document.getElementById('signature_data').value = pad.toDataURL('image/png');
      });
      document.getElementById('clear').onclick = () => pad.clear();
    } else {
      // Signature field rendered inside content_html already
      const canvas2 = document.getElementById('sig');
      if (canvas2) {
        const pad2 = new SignaturePad(canvas2);
        document.querySelector('form').addEventListener('submit', (e) => {
          if (pad2.isEmpty()) { alert('Please sign.'); e.preventDefault(); return; }
          document.getElementById('signature_data').value = pad2.toDataURL('image/png');
        });
        document.getElementById('clear').onclick = () => pad2.clear();
      }
    }
  </script>
  <?php endif; ?>
</body></html>
