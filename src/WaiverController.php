<?php
namespace App;
class WaiverController {
  private Database $db; private array $cfg;
  public function __construct(array $cfg, Database $db){ $this->cfg=$cfg; $this->db=$db; date_default_timezone_set($cfg['app']['timezone']); }

  public function createInstance(array $payload): array {
    $reservation_id=$payload['reservation_id']??null;
    $template_id=$payload['template_id']??null;
    $guest_name=$payload['guest_name']??null;
    $guest_email=$payload['guest_email']??null;
    $group_token=$payload['group_token']??null;
    // [Model A / FK-T6] optional external link_token (BookingV2-minted). When
    // absent we self-mint (Model B fallback stays intact for legacy callers).
    $link_token=$payload['link_token']??null;
    $participant_id=$payload['participant_id']??null;
    $customer_id=$payload['customer_id']??null;
    $booking_group_id=$payload['booking_group_id']??null;
    if(!$template_id){ return ['error'=>'template_id is required']; }
    if(!is_scalar($template_id)) return ['error'=>'template_id must be a scalar'];
    foreach(['reservation_id'=>$reservation_id,'guest_name'=>$guest_name,'guest_email'=>$guest_email,'group_token'=>$group_token,'link_token'=>$link_token,'participant_id'=>$participant_id,'customer_id'=>$customer_id,'booking_group_id'=>$booking_group_id] as $k=>$val){ if($val!==null && !is_scalar($val)) return ['error'=>$k.' must be a string']; }
    if($reservation_id!==null && strlen((string)$reservation_id)>64) return ['error'=>'reservation_id too long (max 64)'];
    if($group_token!==null && strlen((string)$group_token)>16) return ['error'=>'group_token too long (max 16)'];
    if($guest_name!==null && strlen((string)$guest_name)>255) return ['error'=>'guest_name too long (max 255)'];
    if($guest_email!==null && strlen((string)$guest_email)>255) return ['error'=>'guest_email too long (max 255)'];
    if($participant_id!==null && strlen((string)$participant_id)>64) return ['error'=>'participant_id too long (max 64)'];
    if($customer_id!==null && strlen((string)$customer_id)>64) return ['error'=>'customer_id too long (max 64)'];
    if($booking_group_id!==null && strlen((string)$booking_group_id)>64) return ['error'=>'booking_group_id too long (max 64)'];
    if($link_token!==null){
      $link_token=(string)$link_token;
      if(!preg_match('/^[A-Za-z0-9_-]{16,128}$/',$link_token)) return ['error'=>'link_token has invalid charset or length (expected 16-128 chars of [A-Za-z0-9_-])'];
    }

    // [Gap1] published-version gate: resolve by is_published=1, never fall back
    // to the max-drafted version. No published version -> 400 no_published_version.
    $v=$this->db->pdo()->prepare('SELECT id, version, fields_json, title FROM waiver_template_versions WHERE template_id=? AND is_published=1 ORDER BY version DESC LIMIT 1');
    $v->execute([$template_id]); $version=$v->fetch(); if(!$version) return ['error'=>'no_published_version'];

    $pdo=$this->db->pdo();
    $token=$link_token ?? Utils::randomToken(32);

    try {
      $stmt=$pdo->prepare('INSERT INTO waiver_instances (template_version_id, reservation_id, participant_id, customer_id, booking_group_id, group_token, guest_name, guest_email, link_token, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,"pending",UTC_TIMESTAMP(),UTC_TIMESTAMP())');
      $stmt->execute([$version['id'],$reservation_id,$participant_id,$customer_id,$booking_group_id,$group_token,$guest_name,$guest_email,$token]);
    } catch (\PDOException $e) {
      // [FK-T6] Idempotent create: a duplicate link_token (SQLSTATE 23000) means
      // a retried request for a token we already registered — look up the
      // existing row and return the SAME success shape rather than a 500, so a
      // network-timeout retry from BookingV2 never double-creates or errors.
      if($e->getCode()==='23000' && $link_token!==null){
        $existing=$pdo->prepare('SELECT id, group_token FROM waiver_instances WHERE link_token=? LIMIT 1');
        $existing->execute([$link_token]); $row=$existing->fetch();
        if($row){
          $link=rtrim($this->cfg['app']['base_url'],'/').'/w.php?token='.$link_token;
          return ['waiver_id'=>(int)$row['id'],'link'=>$link,'link_token'=>$link_token,'group_token'=>$row['group_token'],'reused'=>true];
        }
      }
      throw $e;
    }

    $id=(int)$pdo->lastInsertId(); $link=rtrim($this->cfg['app']['base_url'],'/').'/w.php?token='.$token;
    $this->audit('instance',$id,'created',['reservation_id'=>$reservation_id,'template_version_id'=>$version['id'],'group_token'=>$group_token,'participant_id'=>$participant_id,'customer_id'=>$customer_id,'booking_group_id'=>$booking_group_id]);
    return ['waiver_id'=>$id,'link'=>$link,'link_token'=>$token,'group_token'=>$group_token,'reused'=>false];
  }

  // [FK-T6 / T9 partial] Does template_id have any published version?
  public function hasPublishedVersion($template_id): array {
    if($template_id===null || !is_scalar($template_id)) return ['error'=>'template_id is required'];
    $q=$this->db->pdo()->prepare('SELECT 1 FROM waiver_template_versions WHERE template_id=? AND is_published=1 LIMIT 1');
    $q->execute([$template_id]); $row=$q->fetch();
    return ['has_published_version'=>(bool)$row];
  }

  // [FK-T9, extended BK-T14] Reconciliation support (spec G1c): BookingV2
  // polls this to catch any completion whose webhook was lost, then replays
  // the SAME ingestion logic used for the webhook (binding check + Gap4
  // age-gate + Gap3 consent flow-back). That means this row shape MUST carry
  // every field the webhook body carries (spec G1b), not just a status flag
  // -- otherwise the reconciliation caller cannot re-run the completion path
  // and would have to trust an unverified/unbound claim. One row shape reused
  // for both the single-token and batch-by-group paths:
  //   {waiver_instance_id, link_token, status, completed_at, participant_id,
  //    customer_id, booking_group_id, birth_date, computed_age, minor,
  //    parental_consent_name, waiver_consent_granted, evidence_sha256,
  //    answers_hash, signer_full_name}
  // - status: the fork's raw waiver_instances.status ("pending"|"completed"|"void").
  // - completed_at: ISO-8601 UTC string, or null if not completed.
  // - evidence_sha256: object-store bytes hash (Gap2). T15 (presigned-PUT
  //   object storage) has not shipped yet in this fork, so evidence still
  //   lands on the container FS and no object-bytes hash is computed anywhere
  //   -> always null until T15 lands. Deliberately NOT the answers hash.
  // - answers_hash: the fork's existing answers-payload legal-integrity hash,
  //   i.e. waiver_responses.hash_sha256 (computed in submitGuestForm over
  //   {template_version_id, instance_id, answers, signed_at, signer_ip, ua}).
  //   Null until the guest actually submits.
  // - birth_date/computed_age/minor/parental_consent_name/waiver_consent_granted:
  //   decoded from waiver_responses.answers_json, which submitGuestForm()
  //   stashes under the fixed '_computed_age'/'_minor'/'_parental_consent_name'
  //   keys (age-gate) and 'waiver_consent_granted' key (Gap3 consent, Wave-2,
  //   present only when the guest actually ticked it) -- mirrors exactly what
  //   notifyBookingV2Completion() puts on the webhook body so the SAME
  //   ingestion logic can be replayed regardless of which path (webhook or
  //   reconciliation) delivered the completion. birth_date is not persisted
  //   verbatim in answers_json (only the derived _computed_age is), so it is
  //   always null here -- fine, since BookingV2's ingestion only consumes
  //   computed_age/parental_consent_name for the age-gate re-check.
  private function statusRow(array $row): array {
    $answers = [];
    if (isset($row['answers_json']) && $row['answers_json'] !== null) {
      $decoded = json_decode((string)$row['answers_json'], true);
      if (is_array($decoded)) $answers = $decoded;
    }
    return [
      'waiver_instance_id'     => (int)$row['id'],
      'link_token'             => (string)$row['link_token'],
      'status'                 => (string)$row['status'],
      'completed_at'           => $row['completed_at'] !== null ? gmdate('c', strtotime($row['completed_at'].' UTC')) : null,
      'participant_id'         => $row['participant_id'] !== null ? (string)$row['participant_id'] : null,
      'customer_id'            => $row['customer_id'] !== null ? (string)$row['customer_id'] : null,
      'booking_group_id'       => $row['booking_group_id'] !== null ? (string)$row['booking_group_id'] : null,
      'birth_date'             => null, // not persisted verbatim in answers_json, see doc above.
      'computed_age'           => isset($answers['_computed_age']) ? (int)$answers['_computed_age'] : null,
      'minor'                  => isset($answers['_minor']) ? (bool)$answers['_minor'] : null,
      'parental_consent_name'  => $answers['_parental_consent_name'] ?? null,
      'waiver_consent_granted' => ($answers[self::CONSENT_ANSWER_KEY] ?? null) === true ? true : null,
      'evidence_sha256'        => null, // [Gap2] populated once T15 object-store upload lands.
      'answers_hash'           => isset($row['hash_sha256']) && $row['hash_sha256'] !== null ? (string)$row['hash_sha256'] : null,
      'signer_full_name'       => $row['signer_full_name'] ?? null,
    ];
  }

  private const STATUS_SELECT = 'SELECT wi.id, wi.link_token, wi.status, wi.completed_at, wi.participant_id, wi.customer_id, wi.booking_group_id, wr.hash_sha256, wr.answers_json, wr.signer_full_name
     FROM waiver_instances wi
     LEFT JOIN waiver_responses wr ON wr.waiver_instance_id = wi.id';

  public function getStatus(array $payload): array {
    $linkToken = $payload['link_token'] ?? null;
    $bookingGroupId = $payload['booking_group_id'] ?? null;
    $reservationId = $payload['reservation_id'] ?? null;

    if ($linkToken !== null) {
      if (!is_scalar($linkToken) || (string)$linkToken === '') return ['error'=>'link_token must be a non-empty string'];
      $linkToken = (string)$linkToken;
      if (strlen($linkToken) > 128) return ['error'=>'link_token too long (max 128)'];
      $q = $this->db->pdo()->prepare(self::STATUS_SELECT.' WHERE wi.link_token=? LIMIT 1');
      $q->execute([$linkToken]);
      $row = $q->fetch();
      if (!$row) return ['error'=>'token_unknown'];
      return $this->statusRow($row);
    }

    if ($bookingGroupId !== null || $reservationId !== null) {
      if ($bookingGroupId !== null && (!is_scalar($bookingGroupId) || (string)$bookingGroupId === '')) return ['error'=>'booking_group_id must be a non-empty string'];
      if ($reservationId !== null && (!is_scalar($reservationId) || (string)$reservationId === '')) return ['error'=>'reservation_id must be a non-empty string'];
      $bookingGroupId = $bookingGroupId !== null ? (string)$bookingGroupId : null;
      $reservationId = $reservationId !== null ? (string)$reservationId : null;
      if ($bookingGroupId !== null && strlen($bookingGroupId) > 64) return ['error'=>'booking_group_id too long (max 64)'];
      if ($reservationId !== null && strlen($reservationId) > 64) return ['error'=>'reservation_id too long (max 64)'];

      // Bounded batch: this is a reconciliation sweep over one reservation's
      // participants, never an unbounded scan -> cap defensively.
      if ($bookingGroupId !== null) {
        $q = $this->db->pdo()->prepare(self::STATUS_SELECT.' WHERE wi.booking_group_id=? ORDER BY wi.id ASC LIMIT 500');
        $q->execute([$bookingGroupId]);
      } else {
        $q = $this->db->pdo()->prepare(self::STATUS_SELECT.' WHERE wi.reservation_id=? ORDER BY wi.id ASC LIMIT 500');
        $q->execute([$reservationId]);
      }
      $rows = $q->fetchAll();
      return ['results' => array_map([$this, 'statusRow'], $rows)];
    }

    return ['error'=>'Provide link_token, or booking_group_id / reservation_id for a batch lookup'];
  }

  // [FK-Tconsent] Field type for the optional GDPR/marketing consent
  // checkbox. Deliberately its own type (not reused from radio/text) so the
  // guest page, PDF renderer, and answer-capture logic can all special-case
  // it: it is ALWAYS optional (never required, even if a template author
  // mistakenly sets required=true — normalizeFields() strips that below),
  // and its checked state is surfaced under the fixed answers_json key
  // 'waiver_consent_granted' rather than the field's own key (see
  // buildAnswers()/submitGuestForm()).
  private const CONSENT_FIELD_TYPE = 'gdpr_consent';
  private const CONSENT_ANSWER_KEY = 'waiver_consent_granted';

  // Decode fields_json defensively: tolerate a non-array/non-list, or field
  // objects missing key/label/type/options, so a malformed template can never
  // fatal the guest page. Fills safe defaults.
  private function normalizeFields($fieldsJson): array {
    $raw=json_decode((string)$fieldsJson, true); if(!is_array($raw)) return [];
    $out=[];
    foreach($raw as $f){
      if(!is_array($f) || !isset($f['key']) || !is_scalar($f['key']) || (string)$f['key']==='') continue;
      $key=(string)$f['key'];
      $type=(isset($f['type'])&&is_scalar($f['type']))?(string)$f['type']:'text';
      $out[]=[
        'key'=>$key,
        'label'=>(isset($f['label'])&&is_scalar($f['label']))?(string)$f['label']:ucwords(str_replace('_',' ',$key)),
        'type'=>$type,
        // [FK-Tconsent] Hard-force optional: the consent checkbox must never
        // be required, regardless of what a template's fields_json declares.
        'required'=>($type===self::CONSENT_FIELD_TYPE) ? false : !empty($f['required']),
        'options'=>(isset($f['options'])&&is_array($f['options']))?array_values($f['options']):[],
        'maxLength'=>(isset($f['maxLength'])&&is_scalar($f['maxLength']))?(int)$f['maxLength']:255,
      ];
    }
    return $out;
  }

  public function renderGuestForm(string $token): array {
    $q=$this->db->pdo()->prepare('SELECT wi.*, wtv.title, wtv.description, wtv.fields_json, wtv.content_html, wtv.print_css FROM waiver_instances wi JOIN waiver_template_versions wtv ON wi.template_version_id=wtv.id WHERE link_token=? LIMIT 1');
    $q->execute([$token]); $row=$q->fetch();
    if(!$row) return ['error'=>'Invalid link']; if($row['status']==='completed') return ['error'=>'This waiver has already been completed.'];
    return ['instance'=>$row,'fields'=>$this->normalizeFields($row['fields_json'])];
  }

  // [FK-T10 / Gap4] SPEC §12.2 age thresholds, enforced at capture time.
  // Hard reject below this age (no claim, no webhook -- there is no
  // "signable by a 6-year-old" case).
  private const AGE_MIN_HARD_REJECT = 7;
  // Below this age the signer is a minor: require an affirmatively-set
  // parental/guardian consent field, else reject.
  private const AGE_PARENTAL_CONSENT_BELOW = 18;

  // [FK-T10] Compute age-in-years as of $asOf (UTC "today") from a Y-m-d DOB
  // string, using calendar-aware whole-years math (not a naive day-count
  // divide, which mishandles leap years / partial final year).
  private function computeAgeYears(\DateTimeImmutable $dob, \DateTimeImmutable $asOf): int {
    return $dob->diff($asOf)->y;
  }

  // [FK-T10 / Gap4] Age-gate the submission BEFORE the atomic completion
  // claim and BEFORE any completion webhook. Looks for the template's DOB
  // field (the first field of type=date in fields_json) and, if present,
  // parses+validates it:
  //   - unparsable/future DOB                       -> reject (invalid_birth_date)
  //   - age < AGE_MIN_HARD_REJECT                    -> reject (age_below_minimum), no exceptions
  //   - age < AGE_PARENTAL_CONSENT_BELOW and no       -> reject (minor_parental_consent_missing)
  //     affirmatively-set parental-consent field
  //   - otherwise                                    -> pass
  // No type=date field in the template at all -> age-gating does not apply
  // to this template (nothing to gate on); pass through.
  // Returns ['ok'=>true, 'birth_date'=>?string, 'computed_age'=>?int, 'minor'=>?bool,
  //          'parental_consent_name'=>?string] on pass, or ['ok'=>false,'error'=>string] on reject.
  private function evaluateAgeGate(array $fields, array $post): array {
    $dobField = null;
    foreach ($fields as $f) { if ($f['type'] === 'date') { $dobField = $f; break; } }
    if ($dobField === null) return ['ok'=>true, 'birth_date'=>null, 'computed_age'=>null, 'minor'=>null, 'parental_consent_name'=>null];

    $raw = $post[$dobField['key']] ?? null;
    if (!is_scalar($raw) || (string)$raw === '') return ['ok'=>false, 'error'=>'Missing field: '.$dobField['key']];
    $raw = (string)$raw;

    $dob = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new \DateTimeZone('UTC'));
    // createFromFormat with '!' resets time-of-day but still silently accepts
    // some loose input; getLastErrors() catches those (e.g. "2024-02-30").
    $formatErrors = \DateTimeImmutable::getLastErrors();
    if ($dob === false || ($formatErrors !== false && ($formatErrors['error_count'] > 0 || $formatErrors['warning_count'] > 0))) {
      return ['ok'=>false, 'error'=>'Invalid date of birth'];
    }

    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    if ($dob > $now) return ['ok'=>false, 'error'=>'Date of birth cannot be in the future'];

    $age = $this->computeAgeYears($dob, $now);
    if ($age < self::AGE_MIN_HARD_REJECT) return ['ok'=>false, 'error'=>'age_below_minimum'];

    $minor = $age < self::AGE_PARENTAL_CONSENT_BELOW;
    $parentalConsentName = null;
    if ($minor) {
      // Parental consent is carried in a field of type=parental_consent (any
      // key), falling back to the conventional 'parental_consent_name' key so
      // templates that don't declare the type still work. "Affirmatively
      // set" = a non-empty scalar value (the parent/guardian's name).
      $consentField = null;
      foreach ($fields as $f) { if ($f['type'] === 'parental_consent') { $consentField = $f; break; } }
      $consentKey = $consentField['key'] ?? 'parental_consent_name';
      $consentVal = $post[$consentKey] ?? null;
      if (!is_scalar($consentVal) || trim((string)$consentVal) === '') {
        return ['ok'=>false, 'error'=>'minor_parental_consent_missing'];
      }
      $parentalConsentName = trim((string)$consentVal);
    }

    return [
      'ok'=>true,
      'birth_date'=>$dob->format('Y-m-d'),
      'computed_age'=>$age,
      'minor'=>$minor,
      'parental_consent_name'=>$parentalConsentName,
    ];
  }

  public function submitGuestForm(string $token, array $post): array {
    $q=$this->db->pdo()->prepare('SELECT wi.*, wtv.id as version_id, wtv.title, wtv.fields_json, wtv.content_html, wtv.print_css FROM waiver_instances wi JOIN waiver_template_versions wtv ON wi.template_version_id=wtv.id WHERE link_token=? LIMIT 1');
    $q->execute([$token]); $instance=$q->fetch(); if(!$instance) return ['error'=>'Invalid link']; if($instance['status']==='completed') return ['error'=>'Already completed'];
    $fields=$this->normalizeFields($instance['fields_json']); $answers=[];
    foreach($fields as $f){
      $key=$f['key']; $val=$post[$key]??null;
      // [FK-Tconsent] The consent checkbox is ALWAYS optional (normalizeFields
      // already forces required=false for it, so the check below is a no-op
      // for this type, but it's excluded from the generic $answers[$key]=$val
      // write below): it never lands under its own field key. An HTML
      // checkbox omits its name from POST entirely when unticked, so "checked"
      // is exactly "the key is present with a non-empty value" -- there is no
      // false/unchecked value to observe, which is the desired behavior
      // (absence = no consent action, never a recorded false).
      if($f['type']===self::CONSENT_FIELD_TYPE){
        if($val!==null && $val!=='') $answers[self::CONSENT_ANSWER_KEY]=true;
        continue;
      }
      if(!empty($f['required']) && ($val===null || $val==='')) return ['error'=>'Missing field: '.$key];
      $answers[$key]=$val;
    }
    if(isset($post['full_name']) && strlen((string)$post['full_name'])>255) return ['error'=>'Full name is too long (max 255 characters).'];

    // [FK-T10 / Gap4] Age-gate BEFORE the atomic completed-status claim and
    // BEFORE any completion webhook: a failure here must flip nothing.
    $ageGate = $this->evaluateAgeGate($fields, $post);
    if (!$ageGate['ok']) {
      $this->audit('instance', (int)$instance['id'], 'age_gate_rejected', ['reason'=>$ageGate['error']]);
      return ['error'=>$ageGate['error']];
    }
    if ($ageGate['computed_age'] !== null) {
      $answers['_computed_age']=$ageGate['computed_age'];
      $answers['_minor']=$ageGate['minor'];
      if ($ageGate['parental_consent_name'] !== null) $answers['_parental_consent_name']=$ageGate['parental_consent_name'];
    }

    $sigData=$post['signature_data']??''; if(!preg_match('#^data:image/png;base64,#',$sigData)) return ['error'=>'Missing signature'];
    $png=base64_decode(substr($sigData,22));
    if($png===false || strncmp($png,"\x89PNG\r\n\x1a\n",8)!==0) return ['error'=>'Invalid signature image'];
    if(strlen($png) > 2*1024*1024) return ['error'=>'Signature image too large'];

    // Atomically claim this instance so two concurrent submits of the same token
    // cannot both proceed (prevents the duplicate-key race and orphaned files).
    $pdo=$this->db->pdo();
    $claim=$pdo->prepare('UPDATE waiver_instances SET status="completed", completed_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=? AND status="pending"');
    $claim->execute([$instance['id']]);
    if($claim->rowCount()===0) return ['error'=>'Already completed'];

    $sigDir=$this->cfg['storage']['signatures_path']; if(!is_dir($sigDir)) @mkdir($sigDir,0775,true);
    $sigFile=$sigDir.'/'.Utils::randomToken(16).'.png'; $artifact=null;
    try {
      file_put_contents($sigFile,$png);
      $signedAt=gmdate('c'); $payload=[ 'template_version_id'=>(int)$instance['version_id'], 'instance_id'=>(int)$instance['id'], 'answers'=>$answers, 'signed_at'=>$signedAt, 'signer_ip'=>$_SERVER['REMOTE_ADDR']??null, 'ua'=>$_SERVER['HTTP_USER_AGENT']??null ];
      $hash=hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

      $html = '<h1>'.htmlspecialchars($instance['title']).'</h1>';
      $html .= '<p>Signed at: '.htmlspecialchars($signedAt).'</p>';
      $html .= '<h3>Answers</h3><ul>';
      foreach ($answers as $k=>$v){ $html.='<li><strong>'.htmlspecialchars($k).':</strong> '.htmlspecialchars((string)$v).'</li>'; }
      $html .= '</ul>';
      $html .= '<h3>Signature</h3><img src="data:image/png;base64,'.base64_encode($png).'" style="max-width:300px;border:1px solid #ccc;" />';

      $answers['_signature_png_base64']=base64_encode($png);
      $filledHtml = !empty($instance['content_html']) ? $this->renderContentForPdf($instance['content_html'], $answers, $instance['print_css'] ?? null) : $html;

      $pdf=new PdfService($this->cfg['storage']['artifacts_path']); $basename=date('Ymd').'_'.$instance['id'].'_'.substr($hash,0,8);
      $artifact=$pdf->generate($filledHtml, $basename);

      $stmt=$pdo->prepare('INSERT INTO waiver_responses (waiver_instance_id, answers_json, signature_png, signer_full_name, signed_at, signer_ip, signer_user_agent, hash_sha256, pdf_path, created_at) VALUES (?,?,?,?,UTC_TIMESTAMP(),?,?,?, ?, UTC_TIMESTAMP())');
      $stmt->execute([$instance['id'], json_encode($answers, JSON_UNESCAPED_UNICODE), $png, $post['full_name']??null, $_SERVER['REMOTE_ADDR']??null, $_SERVER['HTTP_USER_AGENT']??null, $hash, $artifact]);
      $this->audit('response', $instance['id'], 'submitted', $payload);

      // [FK-T8] Fire the outbound completion webhook to BookingV2 ONLY here --
      // after the completed-status claim above succeeded AND the
      // waiver_responses row + audit are durably committed. This method
      // swallows ALL of its own errors (never throws into the catch below,
      // which would wrongly revert a real, completed submission back to
      // "pending"). A failed delivery is logged (webhook_failed audit row)
      // and left to the reconciliation sweep (spec G1c) -- never retried by
      // reverting the instance.
      $this->notifyBookingV2Completion($instance, $ageGate, $answers, $post['full_name']??null, $artifact, $hash);
    } catch (\Throwable $e) {
      // Roll the claim back so the guest can retry; remove any orphaned files.
      $pdo->prepare('UPDATE waiver_instances SET status="pending", completed_at=NULL, updated_at=UTC_TIMESTAMP() WHERE id=? AND status="completed"')->execute([$instance['id']]);
      if(is_file($sigFile)) @unlink($sigFile);
      if($artifact && is_file($artifact)) @unlink($artifact);
      return ['error'=>'Could not save waiver, please try again.'];
    }

    return ['ok'=>true,'artifact'=>$artifact];
  }

  // [FK-T8] Number of curl attempts for the outbound completion webhook and
  // the fixed per-attempt timeout, per spec G1b "first cut" (inline bounded
  // retry -- the durable webhook_deliveries/bin/webhook_worker.php version is
  // a later hardening task, not launch-blocking, since the reconciliation
  // sweep in G1c covers any delivery this exhausts).
  private const WEBHOOK_MAX_ATTEMPTS = 3;
  private const WEBHOOK_TIMEOUT_SECONDS = 5;
  // Backoff between attempts, in microseconds: 250ms after attempt 1, 750ms
  // after attempt 2. Only 2 sleeps are needed for 3 attempts.
  private const WEBHOOK_BACKOFF_USEC = [250000, 750000];

  // [FK-T8] POST a signed envelope to BookingV2's completion webhook and
  // return true iff BookingV2 responded 2xx within the attempt budget. Uses
  // raw curl (the fork has no HTTP client -- composer.json is dompdf +
  // phpword only). Never throws: a curl-level error (DNS, connect, timeout)
  // is treated the same as a bad HTTP status -- just another failed attempt.
  private function postSignedEnvelope(string $url, string $rawBody, string $keyId, string $secret): bool {
    for ($attempt = 1; $attempt <= self::WEBHOOK_MAX_ATTEMPTS; $attempt++) {
      $timestamp = (string)time();
      $nonce = Utils::randomToken(12); // hex, well within the ^[A-Za-z0-9_-]{1,32}$ nonce charset
      $canonical = $keyId."\n".$timestamp."\n".$nonce."\n".$rawBody;
      $signature = Utils::hmacSign($canonical, $secret);

      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $rawBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => self::WEBHOOK_TIMEOUT_SECONDS,
        CURLOPT_CONNECTTIMEOUT => self::WEBHOOK_TIMEOUT_SECONDS,
        CURLOPT_HTTPHEADER => [
          'Content-Type: application/json',
          'X-Waiver-Timestamp: '.$timestamp,
          'X-Waiver-Nonce: '.$nonce,
          'X-Waiver-Key-Id: '.$keyId,
          'X-Waiver-Signature: '.$signature,
        ],
      ]);
      curl_exec($ch);
      $errno = curl_errno($ch);
      $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
      curl_close($ch);

      if ($errno === 0 && $status >= 200 && $status < 300) return true;

      if ($attempt < self::WEBHOOK_MAX_ATTEMPTS) {
        usleep(self::WEBHOOK_BACKOFF_USEC[$attempt - 1]);
      }
    }
    return false;
  }

  // [FK-T8] Fire the outbound completion webhook (spec G1b) for a
  // SUCCESSFUL, non-age-gated submitGuestForm completion. Called from inside
  // submitGuestForm's try block, strictly AFTER the waiver_responses row and
  // the 'submitted' audit event are written, so this only ever runs once a
  // real completion has landed. Every failure mode here (missing config,
  // curl exhaustion, any \Throwable) is caught and logged as a
  // 'webhook_failed' audit row -- this method must NEVER let an exception
  // escape and hit submitGuestForm's catch, which would wrongly revert the
  // just-completed instance back to 'pending'.
  private function notifyBookingV2Completion(array $instance, array $ageGate, array $answers, ?string $signerFullName, ?string $artifactPath, string $answersHash): void {
    try {
      $cb = $this->cfg['callback'] ?? null;
      if (!is_array($cb) || empty($cb['base_url']) || empty($cb['outbound_secret']) || empty($cb['outbound_key_id'])) {
        // Not configured (e.g. legacy/self-mint deployments with no
        // BookingV2 integration) -- nothing to notify, not an error.
        return;
      }

      // [Gap2] evidence_sha256 = sha256 of the exact generated-PDF bytes,
      // computed at upload/generation time -- NOT the answers-payload hash
      // (that's $answersHash / waiver_responses.hash_sha256, carried through
      // unchanged as answers_hash below). Object-store move (T15, presigned
      // PUT) is a later task; for now the artifact is a local file and we
      // hash exactly those bytes as they exist on disk right now.
      $evidenceSha256 = null;
      if ($artifactPath !== null && is_file($artifactPath)) {
        $bytes = file_get_contents($artifactPath);
        if ($bytes !== false) $evidenceSha256 = hash('sha256', $bytes);
      }

      $waiverInstanceId = (int)$instance['id'];
      $linkToken = (string)$instance['link_token'];

      $body = [
        'event' => 'waiver.completed',
        'idempotency_key' => 'wvr-'.$waiverInstanceId.'-'.$linkToken,
        'waiver_instance_id' => $waiverInstanceId,
        'link_token' => $linkToken,
        'reservation_id' => $instance['reservation_id'] !== null ? (string)$instance['reservation_id'] : null,
        'booking_group_id' => $instance['booking_group_id'] !== null ? (string)$instance['booking_group_id'] : null,
        'participant_id' => $instance['participant_id'] !== null ? (string)$instance['participant_id'] : null,
        'customer_id' => $instance['customer_id'] !== null ? (string)$instance['customer_id'] : null,
        'completed_at' => gmdate('c'),
        'birth_date' => $ageGate['birth_date'] ?? null,
        'computed_age' => $ageGate['computed_age'] ?? null,
        'minor' => $ageGate['minor'] ?? null,
        'parental_consent_name' => $ageGate['parental_consent_name'] ?? null,
        'evidence_sha256' => $evidenceSha256,
        'answers_hash' => $answersHash,
        'signer_full_name' => $signerFullName,
      ];
      // [Gap3] waiver_consent_granted is a Wave-2 field: the fork's current
      // form has no consent checkbox on most templates, so this key is
      // included ONLY when the guest actually ticked one (present === true).
      // Ingestion (BookingV2) treats an absent key as "no consent action" --
      // never send an explicit false, which would read as a revoke.
      if (($answers[self::CONSENT_ANSWER_KEY] ?? null) === true) {
        $body['waiver_consent_granted'] = true;
      }

      $rawBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $url = rtrim((string)$cb['base_url'], '/').'/api/waiver/complete';

      $ok = $this->postSignedEnvelope($url, $rawBody, (string)$cb['outbound_key_id'], (string)$cb['outbound_secret']);
      if (!$ok) {
        $this->audit('instance', $waiverInstanceId, 'webhook_failed', ['idempotency_key' => $body['idempotency_key']]);
      }
    } catch (\Throwable $e) {
      // Belt-and-suspenders: even an unexpected error here (e.g. a bad
      // 'callback' config shape) must never escape -- log best-effort and
      // move on. The completed instance stands regardless.
      try {
        $this->audit('instance', (int)$instance['id'], 'webhook_failed', ['reason' => 'exception']);
      } catch (\Throwable $e2) { /* best-effort only */ }
    }
  }

  public function linkWaiversToReservation(string $reservationId, array $waiverIds=[], ?string $groupToken=null, bool $includePending=false): array {
    if(!$reservationId) return ['error'=>'reservation_id is required'];
    if(strlen($reservationId)>64) return ['error'=>'reservation_id too long (max 64)'];
    if(empty($waiverIds) && !$groupToken) return ['error'=>'Provide waiver_ids or group_token'];
    // 'void' instances are NEVER eligible; pending only when include_pending=true.
    $statusClause=' AND status IN ("completed"'.($includePending?',"pending"':'').')';
    if($groupToken){
      $sql='SELECT id FROM waiver_instances WHERE group_token=?'.$statusClause;
      $sel=$this->db->pdo()->prepare($sql); $sel->execute([$groupToken]); $ids=array_column($sel->fetchAll(),'id');
    } else {
      $ids=array_values(array_filter(array_map('intval',$waiverIds))); if(!$ids) return ['error'=>'No valid waiver_ids'];
      $in=implode(',',array_fill(0,count($ids),'?')); $sql='SELECT id FROM waiver_instances WHERE id IN ('.$in.')'.$statusClause;
      $sel=$this->db->pdo()->prepare($sql); $sel->execute($ids); $ids=array_column($sel->fetchAll(),'id');
    }
    if(!$ids) return ['updated'=>0,'ids'=>[]];
    $in=implode(',',array_fill(0,count($ids),'?')); $upd=$this->db->pdo()->prepare('UPDATE waiver_instances SET reservation_id=?, updated_at=UTC_TIMESTAMP() WHERE id IN ('.$in.')'); $upd->execute(array_merge([$reservationId],$ids));
    foreach($ids as $id){ $this->audit('instance',(int)$id,'linked_to_reservation',['reservation_id'=>$reservationId]); }
    return ['updated'=>count($ids),'ids'=>$ids];
  }

  public function audit(string $type, int $id, string $event, array $meta=[]): void {
    $stmt=$this->db->pdo()->prepare('INSERT INTO audit_events (entity_type, entity_id, event, meta_json, created_at) VALUES (?,?,?, ?, UTC_TIMESTAMP())');
    $stmt->execute([$type,$id,$event,json_encode($meta, JSON_UNESCAPED_UNICODE)]);
  }

  // -------- Rendering helpers for formatted templates --------
  private function parseAttrs(string $s): array {
    $out=[]; $re='/([a-zA-Z0-9_]+)\s*=\s*"([^"]*)"|([a-zA-Z0-9_]+)\s*=\s*\'([^\']*)\'|([a-zA-Z0-9_]+)\s*=\s*([^\s"]+)/';
    if (preg_match_all($re,$s,$m,PREG_SET_ORDER)) {
      foreach ($m as $mm){ if(!empty($mm[1])) $out[$mm[1]]=$mm[2]; elseif(!empty($mm[3])) $out[$mm[3]]=$mm[4]; elseif(!empty($mm[5])) $out[$mm[5]]=$mm[6]; }
    }
    if (preg_match('/\brequired\b/',$s)) $out['required']=true; return $out;
  }
  public function renderContentForWeb(string $html, array $fields): string {
    $html=preg_replace_callback('#\[field\s+([^\]]+)\]#', function($m){
      $a=$this->parseAttrs($m[1]); $key=$a['key']??''; $type=$a['type']??'text'; $req=!empty($a['required'])?'required':'';
      if($type==='radio'){ $opts=isset($a['options'])?explode('|',$a['options']):['Yes','No']; $out='<span class="d-inline-block">'; foreach($opts as $o){ $out.='<label class="me-3"><input class="form-check-input me-1" type="radio" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars($o).'" '.$req.'>'.htmlspecialchars($o).'</label>'; } return $out.'</span>'; }
      if($type==='textarea'){ return '<textarea name="'.htmlspecialchars($key).'" class="form-control d-inline-block" style="width:100%; min-height:80px; border:1px solid #ccc;"></textarea>'; }
      if($type==='date'){ return '<input type="date" name="'.htmlspecialchars($key).'" class="form-control d-inline-block" style="width:auto; min-width:180px; padding:2px 6px;" '.$req.'>'; }
      if($type==='parental_consent'){ return '<input name="'.htmlspecialchars($key).'" placeholder="Parent/guardian full name" class="form-control d-inline-block" style="width:auto; min-width:220px; padding:2px 6px; border:none; border-bottom:1px solid #000;" '.$req.'>'; }
      // [FK-Tconsent] Optional GDPR/marketing consent checkbox. ALWAYS
      // unrequired regardless of the placeholder's own `required` attribute
      // (never honor $req here) -- this checkbox must never block submission.
      if($type===self::CONSENT_FIELD_TYPE){ return '<div class="form-check d-inline-block"><input class="form-check-input" type="checkbox" name="'.htmlspecialchars($key).'" id="field_'.htmlspecialchars($key).'" value="1"><label class="form-check-label" for="field_'.htmlspecialchars($key).'">'.htmlspecialchars($a['label']??'I consent to be contacted for offers and promotions').'</label></div>'; }
      return '<input name="'.htmlspecialchars($key).'" class="form-control d-inline-block" style="width:auto; min-width:220px; padding:2px 6px; border:none; border-bottom:1px solid #000;" '.$req.'>';
    }, $html);
    $html=preg_replace('#\[signature(?:\s+[^\]]+)?\]#','<div class="mb-2"><label class="form-label">Signature *</label><canvas id="sig" style="border:1px solid #ccc; width:100%; max-width:480px; height:180px"></canvas><input type="hidden" name="signature_data" id="signature_data" required><button type="button" id="clear" class="btn btn-sm btn-outline-secondary mt-2">Clear</button></div>',$html);
    return $html;
  }
  public function renderContentForPdf(string $html, array $answers, ?string $printCss): string {
    $html=preg_replace_callback('#\[field\s+([^\]]+)\]#', function($m) use ($answers){
      $a=$this->parseAttrs($m[1]); $key=$a['key']??''; $type=$a['type']??'text'; $val=isset($answers[$key])?(string)$answers[$key]:'';
      if($type==='radio'){ $opts=isset($a['options'])?explode('|',$a['options']):['Yes','No']; $out=''; foreach($opts as $o){ $checked=(strcasecmp(trim($val),trim($o))===0); $box=$checked?'&#10003;':'&nbsp;'; $out.='<span class="checkbox">['.$box.']</span> '.htmlspecialchars($o).'&nbsp;&nbsp; '; } return $out; }
      if($type==='textarea'){ $disp=$val!=''?nl2br(htmlspecialchars($val)):'&nbsp;'; return '<div class="inline-line" style="display:block; min-height:60px">'.$disp.'</div>'; }
      // [FK-Tconsent] The consent checkbox's checked state is NOT stored under
      // its own field key -- submitGuestForm() remaps it to the fixed
      // 'waiver_consent_granted' answers_json key (present+true, or absent).
      // Render from that key, not $val (which would always be empty/'1').
      if($type===self::CONSENT_FIELD_TYPE){ $checked=!empty($answers[self::CONSENT_ANSWER_KEY]); $box=$checked?'&#10003;':'&nbsp;'; return '<span class="checkbox">['.$box.']</span> '.htmlspecialchars($a['label']??'I consent to be contacted for offers and promotions'); }
      $disp=$val!==''?htmlspecialchars($val):'&nbsp;'; return '<span class="inline-line">'.$disp.'</span>';
    }, $html);
    $html=preg_replace_callback('#\[signature(?:\s+[^\]]+)?\]#', function() use ($answers){ if(!empty($answers['_signature_png_base64'])) return '<img style="max-width:300px;border:1px solid #ccc" src="data:image/png;base64,'.htmlspecialchars($answers['_signature_png_base64']).'">'; return '<span class="inline-line">&nbsp;</span>'; }, $html);
    $html=preg_replace_callback('#\[if\s+key="([^"]+)"\s+equals="([^"]+)"\](.*?)\[/if\]#s', function($m) use ($answers){ $key=$m[1]; $eq=$m[2]; $inner=$m[3]; return (isset($answers[$key]) && (string)$answers[$key]===$eq)?$inner:''; }, $html);
    $css='<style>.inline-line{border-bottom:1px solid #000; min-width:220px; display:inline-block;} .checkbox{display:inline-block; border:1px solid #000; width:14px; height:14px; text-align:center; line-height:14px; font-size:12px; margin:0 6px;} p{margin:6px 0}</style>'; if($printCss) $css.='<style>'.$printCss.'</style>'; return $css.$html;
  }
}
