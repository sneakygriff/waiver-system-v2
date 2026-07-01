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

$token = $_GET['token'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $res = $ctl->submitGuestForm($token, $_POST);
  if (!empty($res['error'])) { $error = $res['error']; }
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

$data = $ctl->renderGuestForm($token);
if (!empty($data['error'])) { http_response_code(404); echo htmlspecialchars($data['error']); exit; }
$instance = $data['instance']; $fields = $data['fields'];
?>
<!doctype html><html><head><meta charset="utf-8"><title><?=htmlspecialchars($instance['title'])?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
<style>#sig { border:1px solid #ccc; width:100%; height:180px; }</style>
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
