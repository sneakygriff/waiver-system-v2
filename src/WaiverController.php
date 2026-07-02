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
  //    answers_hash, signer_full_name, form_version}
  // - form_version [D14/T5]: waiver_template_versions.version this instance
  //   was minted against (joined via STATUS_SELECT) -- mirrors the completion
  //   webhook's form_version field 1:1 so BookingV2's WaiverAcceptance ledger
  //   write is identical regardless of which path delivered the completion.
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
      // [waiver-program D14/T5] mirrors the completion webhook's form_version
      // (see notifyBookingV2Completion) -- reconciliation must be able to
      // replay the SAME ledger write a lost webhook would have made.
      'form_version'           => isset($row['form_version']) ? (int)$row['form_version'] : null,
    ];
  }

  // [waiver-program D14/T5] wtv.version is joined in too (aliased form_version)
  // so statusRow() can echo the SAME field the completion webhook carries --
  // the file-header doc requires the webhook and reconciliation paths to
  // "converge on identical semantics", so a webhook that's lost and later
  // picked up by reconciliation must not silently fall back to "assume
  // current version" when the fork actually knows better.
  private const STATUS_SELECT = 'SELECT wi.id, wi.link_token, wi.status, wi.completed_at, wi.participant_id, wi.customer_id, wi.booking_group_id, wtv.version as form_version, wr.hash_sha256, wr.answers_json, wr.signer_full_name
     FROM waiver_instances wi
     JOIN waiver_template_versions wtv ON wi.template_version_id = wtv.id
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

  // [FK-void / SPEC D-1 rotate] void_waiver: mark a waiver_instances row
  // status='void' by link_token so a rotated/superseded token can never be
  // signed, even if the old signing link is still floating around (email
  // client cache, browser back button, etc.). Called by BookingV2's
  // sendWaiverSigningLink re-send/rotate step (fork-client.ts
  // voidWaiverInstance) as a best-effort compensating action -- this action
  // itself, though, is a plain synchronous request/response like every other
  // action here; "best-effort" is a BookingV2-side caller concern, not
  // something this method needs to know about.
  //
  // Idempotent by design (mirrors eraseWaiver's idempotency posture):
  //   - unknown token            -> {error: 'token_unknown'} (404, matches get_status)
  //   - already void             -> {ok:true, already_void:true} (no-op, not an error)
  //   - completed                -> {error: 'already_completed'} (400 -- a
  //     signed, evidence-bearing instance is never voidable; that would let a
  //     caller erase the "was this signed" signal without going through the
  //     real GDPR erase_waiver path)
  //   - pending                  -> atomic claim to 'void', audited, {ok:true}
  //
  // [W6 / orphaned instances] "Orphaned" here means the instance this call
  // targets has been concurrently HARD-DELETED by eraseWaiver (fork-client.ts
  // voidWaiverInstance's own doc calls the pre-GDPR-erase case "a harmless
  // orphaned pending instance" -- but once GDPR erasure runs, that same row
  // can vanish out from under an in-flight void call entirely, not just
  // change status). Two race windows, both handled the same way -- treat a
  // vanished row as EXACTLY equivalent to "unknown token", never as
  // "already_completed":
  //   1. between the initial SELECT and the UPDATE claim below, OR
  //   2. between the UPDATE claim (0 rows affected) and the re-check SELECT.
  // Without this, a void racing an erasure would previously fall through the
  // re-check's `if ($now && ...)` (both false when the row is gone) into the
  // catch-all `return ['error'=>'already_completed']` -- a misleading 400 for
  // a token that was never completed at all, just erased.
  public function voidWaiver(array $payload): array {
    $linkToken = $payload['link_token'] ?? null;
    if ($linkToken === null || !is_scalar($linkToken) || (string)$linkToken === '') {
      return ['error'=>'link_token must be a non-empty string'];
    }
    $linkToken = (string)$linkToken;
    if (strlen($linkToken) > 128) return ['error'=>'link_token too long (max 128)'];

    $pdo = $this->db->pdo();
    $q = $pdo->prepare('SELECT id, status FROM waiver_instances WHERE link_token=? LIMIT 1');
    $q->execute([$linkToken]);
    $row = $q->fetch();
    if (!$row) return ['error'=>'token_unknown'];

    if ($row['status'] === 'void') {
      // Already voided (e.g. a retried rotate call) -- clean no-op, not an error.
      return ['ok'=>true, 'already_void'=>true];
    }
    if ($row['status'] === 'completed') {
      return ['error'=>'already_completed'];
    }

    // Atomic claim mirrors submitGuestForm's completed-status claim: only
    // flip a row that is still 'pending' at the moment of the UPDATE, so a
    // concurrent sign-in-flight can't be silently voided out from under it.
    $claim = $pdo->prepare('UPDATE waiver_instances SET status="void", updated_at=UTC_TIMESTAMP() WHERE id=? AND status="pending"');
    $claim->execute([(int)$row['id']]);
    if ($claim->rowCount() === 0) {
      // Lost the race: re-check what it became (completed, void, or GONE --
      // erased concurrently) and report accordingly.
      $recheck = $pdo->prepare('SELECT status FROM waiver_instances WHERE id=? LIMIT 1');
      $recheck->execute([(int)$row['id']]);
      $now = $recheck->fetch();
      if (!$now) {
        // [W6] The row no longer exists at all: a concurrent eraseWaiver call
        // deleted it out from under this void (orphaned-instance race). The
        // GDPR erasure already made the token permanently unusable (the row
        // is gone, so submitGuestForm/renderGuestForm will 404 it via
        // "Invalid link" regardless) -- report the same shape a caller would
        // get by re-querying this token now, i.e. 'token_unknown', never the
        // misleading 'already_completed'.
        return ['error'=>'token_unknown'];
      }
      if ($now['status'] === 'void') return ['ok'=>true, 'already_void'=>true];
      return ['error'=>'already_completed'];
    }

    $this->audit('instance', (int)$row['id'], 'voided', ['link_token'=>$linkToken]);
    return ['ok'=>true];
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
    if(!$row) return ['error'=>'Invalid link'];
    if($row['status']==='completed') return ['error'=>'This waiver has already been completed.'];
    // [FK-void] A voided instance (D-1 rotate, or an explicit void) must not
    // even render a signable form -- distinct message from "completed" so a
    // guest opening a stale/rotated link understands to use their newest link.
    if($row['status']==='void') return ['error'=>'This waiver link is no longer valid. Please use the most recent link you were sent.'];
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
    // [waiver-program D14/T5] wtv.version (the numeric, per-template-published
    // version this instance was minted against) is now selected too, so it can
    // be echoed back on the completion webhook -- see notifyBookingV2Completion.
    $q=$this->db->pdo()->prepare('SELECT wi.*, wtv.id as version_id, wtv.version as form_version, wtv.title, wtv.fields_json, wtv.content_html, wtv.print_css FROM waiver_instances wi JOIN waiver_template_versions wtv ON wi.template_version_id=wtv.id WHERE link_token=? LIMIT 1');
    $q->execute([$token]); $instance=$q->fetch(); if(!$instance) return ['error'=>'Invalid link'];
    if($instance['status']==='completed') return ['error'=>'Already completed'];
    // [FK-void] Reject a voided instance up front with its own message
    // (distinct from "Already completed") -- also belt-and-suspenders with
    // the atomic claim below, which already refuses to flip a non-'pending'
    // row to 'completed' regardless of this early check.
    if($instance['status']==='void') return ['error'=>'This waiver link is no longer valid.'];
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

      // [FK-T15] Evidence now lives durably in BookingV2's object store (Vercel
      // Blob), not on this container's FS. Upload the PDF + signature PNG
      // bytes to the BookingV2 evidence relay BEFORE persisting waiver_responses,
      // so pdf_path/signature_path can be left NULL (nothing durable to point
      // at locally going forward) and evidence_sha256/evidence_object_key are
      // ready to hand to notifyBookingV2Completion. Upload failures are
      // swallowed here (logged, non-blocking) -- see uploadEvidence() doc.
      $evidence = $this->uploadEvidence($instance, $artifact, $sigFile);

      // [FK-erase] pdf_path/signature_path columns are kept for backward
      // compatibility with any pre-T15 rows / the erase_waiver file-cleanup
      // path, but new rows no longer persist a local path: evidence is
      // transient on this container (generated here, uploaded, then removed
      // below) and the durable copy is BookingV2's object store, addressed by
      // evidence_object_key on the completion webhook.
      $stmt=$pdo->prepare('INSERT INTO waiver_responses (waiver_instance_id, answers_json, signature_png, signer_full_name, signed_at, signer_ip, signer_user_agent, hash_sha256, pdf_path, signature_path, created_at) VALUES (?,?,?,?,UTC_TIMESTAMP(),?,?,?, ?, ?, UTC_TIMESTAMP())');
      $stmt->execute([$instance['id'], json_encode($answers, JSON_UNESCAPED_UNICODE), $png, $post['full_name']??null, $_SERVER['REMOTE_ADDR']??null, $_SERVER['HTTP_USER_AGENT']??null, $hash, null, null]);
      $this->audit('response', $instance['id'], 'submitted', $payload);

      // [FK-T8] Fire the outbound completion webhook to BookingV2 ONLY here --
      // after the completed-status claim above succeeded AND the
      // waiver_responses row + audit are durably committed. This method
      // swallows ALL of its own errors (never throws into the catch below,
      // which would wrongly revert a real, completed submission back to
      // "pending"). A failed delivery is logged (webhook_failed audit row)
      // and left to the reconciliation sweep (spec G1c) -- never retried by
      // reverting the instance.
      $this->notifyBookingV2Completion($instance, $ageGate, $answers, $post['full_name']??null, $evidence['evidence_sha256'], $evidence['evidence_object_key'], $hash);
    } catch (\Throwable $e) {
      // Roll the claim back so the guest can retry; remove any orphaned files.
      $pdo->prepare('UPDATE waiver_instances SET status="pending", completed_at=NULL, updated_at=UTC_TIMESTAMP() WHERE id=? AND status="completed"')->execute([$instance['id']]);
      if(is_file($sigFile)) @unlink($sigFile);
      if($artifact && is_file($artifact)) @unlink($artifact);
      return ['error'=>'Could not save waiver, please try again.'];
    }

    // [FK-T15] Stop long-term local-FS persistence: evidence was generated
    // transiently to produce the bytes uploaded above -- remove both files
    // now that the durable copy (if the upload succeeded) lives in BookingV2
    // Blob. Best-effort: a stray leftover file here is not itself a
    // correctness problem (nothing references it), just housekeeping.
    if(is_file($sigFile)) @unlink($sigFile);
    if($artifact && is_file($artifact)) @unlink($artifact);

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
    return $this->postSignedEnvelopeWithResponse($url, $rawBody, $keyId, $secret)['ok'];
  }

  // [FK-T15] Same bounded-retry signed-envelope POST as postSignedEnvelope,
  // but also returns the final response body -- needed by uploadEvidence() to
  // read back the blob key BookingV2's relay assigns. Returns
  // ['ok'=>bool, 'body'=>?string, 'status'=>?int] and never throws (a
  // curl-level error is just another failed attempt, same as
  // postSignedEnvelope).
  private function postSignedEnvelopeWithResponse(string $url, string $rawBody, string $keyId, string $secret): array {
    $lastBody = null; $lastStatus = null;
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
      $resp = curl_exec($ch);
      $errno = curl_errno($ch);
      $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
      curl_close($ch);
      $lastBody = is_string($resp) ? $resp : null;
      $lastStatus = $status;

      if ($errno === 0 && $status >= 200 && $status < 300) {
        return ['ok'=>true, 'body'=>$lastBody, 'status'=>$status];
      }

      if ($attempt < self::WEBHOOK_MAX_ATTEMPTS) {
        usleep(self::WEBHOOK_BACKOFF_USEC[$attempt - 1]);
      }
    }
    return ['ok'=>false, 'body'=>$lastBody, 'status'=>$lastStatus];
  }

  // [FK-T15] Upload the signed PDF + signature PNG bytes to BookingV2's
  // evidence relay (POST /api/waiver/evidence, authenticated with the SAME
  // signed-envelope scheme used for the completion webhook -- callback
  // outbound_secret/outbound_key_id). BookingV2 re-verifies evidence_sha256
  // over the bytes it receives (409 on mismatch), puts the object to Vercel
  // Blob (EU), and returns a blob key. This is a durability upgrade only:
  // the caller (submitGuestForm) must treat any failure here as non-fatal --
  // never throw, never block/revert completion. On any failure this returns
  // evidence_sha256/evidence_object_key both null so the completion webhook
  // still fires (evidence_sha256 absent is a valid, expected shape per Gap2 --
  // reconciliation/backfill can pick this up later).
  //
  // evidence_sha256 is computed over the EXACT bytes placed in the request
  // body (the PDF bytes) -- per spec Gap2 this must be the hash of the
  // object-store bytes as uploaded, not the answers-payload hash.
  private function uploadEvidence(array $instance, ?string $artifactPath, ?string $sigFile): array {
    $none = ['evidence_sha256'=>null, 'evidence_object_key'=>null];
    try {
      $cb = $this->cfg['callback'] ?? null;
      if (!is_array($cb) || empty($cb['base_url']) || empty($cb['outbound_secret']) || empty($cb['outbound_key_id'])) {
        // Not configured -- nothing to upload, not an error (mirrors
        // notifyBookingV2Completion's own "not configured" no-op).
        return $none;
      }
      if ($artifactPath === null || !is_file($artifactPath)) return $none;
      $pdfBytes = file_get_contents($artifactPath);
      if ($pdfBytes === false || $pdfBytes === '') return $none;

      $evidenceSha256 = hash('sha256', $pdfBytes);

      $sigBytes = null;
      if ($sigFile !== null && is_file($sigFile)) {
        $read = file_get_contents($sigFile);
        if ($read !== false) $sigBytes = $read;
      }

      $body = [
        'waiver_instance_id' => (int)$instance['id'],
        'link_token' => (string)$instance['link_token'],
        'evidence_sha256' => $evidenceSha256,
        'pdf_base64' => base64_encode($pdfBytes),
        'signature_png_base64' => $sigBytes !== null ? base64_encode($sigBytes) : null,
      ];
      $rawBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

      $url = !empty($cb['evidence_url'])
        ? (string)$cb['evidence_url']
        : rtrim((string)$cb['base_url'], '/').'/api/waiver/evidence';

      $result = $this->postSignedEnvelopeWithResponse($url, $rawBody, (string)$cb['outbound_key_id'], (string)$cb['outbound_secret']);
      if (!$result['ok']) {
        $this->audit('instance', (int)$instance['id'], 'evidence_upload_failed', ['status'=>$result['status']]);
        // Evidence can be back-filled later (reconciliation/manual re-upload)
        // -- still report the locally-computed hash so the completion
        // webhook at least carries evidence_sha256 even without a blob key.
        return ['evidence_sha256'=>$evidenceSha256, 'evidence_object_key'=>null];
      }

      $decoded = json_decode((string)$result['body'], true);
      $blobKey = (is_array($decoded) && isset($decoded['blob_key']) && is_scalar($decoded['blob_key'])) ? (string)$decoded['blob_key'] : null;
      if ($blobKey === null) {
        // 2xx but no usable blob_key in the body -- treat as a failed upload
        // for wiring purposes (nothing to reference), but keep the hash.
        $this->audit('instance', (int)$instance['id'], 'evidence_upload_failed', ['reason'=>'missing_blob_key']);
        return ['evidence_sha256'=>$evidenceSha256, 'evidence_object_key'=>null];
      }

      return ['evidence_sha256'=>$evidenceSha256, 'evidence_object_key'=>$blobKey];
    } catch (\Throwable $e) {
      // Belt-and-suspenders: never let an unexpected error here escape and
      // hit submitGuestForm's catch, which would wrongly revert a real
      // completion back to 'pending'.
      try {
        $this->audit('instance', (int)$instance['id'], 'evidence_upload_failed', ['reason'=>'exception']);
      } catch (\Throwable $e2) { /* best-effort only */ }
      return $none;
    }
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
  private function notifyBookingV2Completion(array $instance, array $ageGate, array $answers, ?string $signerFullName, ?string $evidenceSha256, ?string $evidenceObjectKey, string $answersHash): void {
    try {
      $cb = $this->cfg['callback'] ?? null;
      if (!is_array($cb) || empty($cb['base_url']) || empty($cb['outbound_secret']) || empty($cb['outbound_key_id'])) {
        // Not configured (e.g. legacy/self-mint deployments with no
        // BookingV2 integration) -- nothing to notify, not an error.
        return;
      }

      // [Gap2 / FK-T15] evidence_sha256 = sha256 of the exact object-store
      // bytes (the generated PDF), computed by uploadEvidence() at upload
      // time -- NOT the answers-payload hash (that's $answersHash /
      // waiver_responses.hash_sha256, carried through unchanged as
      // answers_hash below). evidence_object_key is the Vercel Blob key
      // BookingV2's evidence relay returned; both are null when the upload
      // was skipped/failed (evidence can be back-filled later -- never block
      // completion on this).
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
        'evidence_object_key' => $evidenceObjectKey,
        'answers_hash' => $answersHash,
        'signer_full_name' => $signerFullName,
        // [waiver-program D14/T5] The numeric published-template version this
        // instance was actually signed against (waiver_template_versions.version,
        // selected as wtv.version/form_version in submitGuestForm's query above).
        // BookingV2's WaiverAcceptance ledger records THIS value (what the
        // customer actually signed), not whatever WaiverConfig.currentFormVersion
        // happens to be at ingestion time -- see complete-ingest.ts's doc
        // comment on CompletionFieldsSchema.form_version. Always present and
        // non-null for a real submission (every waiver_instances row is joined
        // to a published waiver_template_versions row at createInstance time).
        'form_version' => isset($instance['form_version']) ? (int)$instance['form_version'] : null,
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

  // [FK-erase / SPEC G5] GDPR erase_waiver: given a binding (customer_id
  // and/or booking_group_id and/or link_tokens), hard-delete every matching
  // waiver_instances row, its waiver_responses row (which hard-deletes the
  // signature_png LONGBLOB -- the DB is the ONLY durable store of that
  // blob), the storage/ PDF + signature files on disk (pdf_path +
  // signature_path), AND every audit_events row tied to those instances
  // (see the audit_events DELETE inline below -- the 'submitted' response
  // event in particular carries the full guest answers payload, which is
  // PII). Idempotent: matching nothing is success, count:0, never an error
  // -- a second erase call for an already-erased customer/group/token set
  // must not fail.
  //
  // Binding is a UNION of whichever fields are provided (matches Model A's
  // "given a binding ... DELETE" framing -- any of the three identifies rows
  // to erase, not an AND of all three). At least one binding field is
  // required, else 400 (an unbounded erase-everything call is never valid).
  //
  // [W2] Everything below the id-resolution step runs inside a single DB
  // transaction: file unlinks are best-effort (filesystem has no rollback),
  // but ALL the DELETEs (waiver_responses, waiver_instances, audit_events)
  // must land atomically -- a mid-failure (e.g. a deadlock on the
  // audit_events DELETE) must never leave a half-erased subject (e.g.
  // waiver_responses purged but waiver_instances or audit_events still
  // carrying PII). On any \Throwable the transaction is rolled back and the
  // error is surfaced to the caller (BookingV2's erasure worker retries the
  // whole call per its outbox backoff -- see waiver-erasure-worker.ts).
  //
  // [W2] The id-resolution SELECT is paginated rather than a single
  // LIMIT-500 query, so a subject bound to more than 500 waiver_instances
  // rows (a customer with a long booking history) still gets a COMPLETE
  // erasure in one call, not a silent partial one that a caller might mistake
  // for "done".
  private const ERASE_PAGE_SIZE = 500;

  public function eraseWaiver(array $payload): array {
    $customerId = $payload['customer_id'] ?? null;
    $bookingGroupId = $payload['booking_group_id'] ?? null;
    $linkTokensRaw = $payload['link_tokens'] ?? null;

    if ($customerId !== null && (!is_scalar($customerId) || (string)$customerId === '')) {
      return ['error'=>'customer_id must be a non-empty string'];
    }
    if ($bookingGroupId !== null && (!is_scalar($bookingGroupId) || (string)$bookingGroupId === '')) {
      return ['error'=>'booking_group_id must be a non-empty string'];
    }
    if ($linkTokensRaw !== null && !is_array($linkTokensRaw)) {
      return ['error'=>'link_tokens must be an array of strings'];
    }

    $customerId = $customerId !== null ? (string)$customerId : null;
    if ($customerId !== null && strlen($customerId) > 64) return ['error'=>'customer_id too long (max 64)'];
    $bookingGroupId = $bookingGroupId !== null ? (string)$bookingGroupId : null;
    if ($bookingGroupId !== null && strlen($bookingGroupId) > 64) return ['error'=>'booking_group_id too long (max 64)'];

    $linkTokens = [];
    if (is_array($linkTokensRaw)) {
      foreach ($linkTokensRaw as $t) {
        if (!is_scalar($t) || (string)$t === '') return ['error'=>'link_tokens entries must be non-empty strings'];
        $t = (string)$t;
        if (strlen($t) > 128) return ['error'=>'link_tokens entry too long (max 128)'];
        $linkTokens[] = $t;
      }
    }

    if ($customerId === null && $bookingGroupId === null && count($linkTokens) === 0) {
      return ['error'=>'Provide at least one of customer_id, booking_group_id, link_tokens'];
    }

    $pdo = $this->db->pdo();

    // Resolve the FULL union of matching waiver_instances ids up front, one
    // page at a time (never a single LIMIT-500 query -- see class doc above).
    // This is still a targeted per-subject lookup (bounded by how many rows
    // one customer/group/token-set can plausibly bind), just not capped at an
    // arbitrary page size.
    $clauses = [];
    $params = [];
    if ($customerId !== null) { $clauses[] = 'customer_id = ?'; $params[] = $customerId; }
    if ($bookingGroupId !== null) { $clauses[] = 'booking_group_id = ?'; $params[] = $bookingGroupId; }
    if (count($linkTokens) > 0) {
      $in = implode(',', array_fill(0, count($linkTokens), '?'));
      $clauses[] = 'link_token IN ('.$in.')';
      foreach ($linkTokens as $t) { $params[] = $t; }
    }
    $whereSql = implode(' OR ', $clauses);

    $instanceIds = [];
    $lastId = 0;
    while (true) {
      // Keyset pagination on id (> lastId) rather than OFFSET, so previously
      // fetched rows (which are NOT yet deleted -- deletion only happens
      // after the full id set is known) never shift the window and cause a
      // skipped/duplicated row.
      $sql = 'SELECT id FROM waiver_instances WHERE ('.$whereSql.') AND id > ? ORDER BY id ASC LIMIT '.self::ERASE_PAGE_SIZE;
      $sel = $pdo->prepare($sql);
      $sel->execute(array_merge($params, [$lastId]));
      $page = array_map('intval', array_column($sel->fetchAll(), 'id'));
      if (!$page) break;
      foreach ($page as $id) { $instanceIds[] = $id; }
      $lastId = end($page);
      if (count($page) < self::ERASE_PAGE_SIZE) break;
    }

    if (!$instanceIds) {
      // Idempotent no-op: nothing bound to this subject (already erased, or
      // never existed) -- still a clean 200, never a 404/500.
      $this->auditErasure(0, 0, 0, 0);
      return ['instances_deleted'=>0, 'responses_deleted'=>0, 'files_deleted'=>0, 'audit_events_deleted'=>0];
    }

    $pdo->beginTransaction();
    try {
      $filesDeleted = 0;
      $responsesDeleted = 0;
      $instancesDeleted = 0;
      $auditEventsDeleted = 0;

      // Process instance ids in pages inside the SAME transaction (a giant
      // single IN(...) list is bounded by ERASE_PAGE_SIZE per statement to
      // stay well under MySQL's max_allowed_packet / placeholder limits even
      // when a subject binds many thousands of rows).
      foreach (array_chunk($instanceIds, self::ERASE_PAGE_SIZE) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));

        // Fetch file paths BEFORE deleting the rows that reference them.
        $pathsQ = $pdo->prepare('SELECT pdf_path, signature_path FROM waiver_responses WHERE waiver_instance_id IN ('.$in.')');
        $pathsQ->execute($chunk);
        $paths = $pathsQ->fetchAll();

        foreach ($paths as $row) {
          foreach (['pdf_path', 'signature_path'] as $col) {
            $p = $row[$col] ?? null;
            if ($p !== null && $p !== '' && is_file($p)) {
              if (@unlink($p)) $filesDeleted++;
            }
          }
        }

        // Hard-delete waiver_responses FIRST (this is what purges the
        // signature_png LONGBLOB -- the row delete itself, not the file
        // unlink above, is what removes that PII from the DB).
        $delResp = $pdo->prepare('DELETE FROM waiver_responses WHERE waiver_instance_id IN ('.$in.')');
        $delResp->execute($chunk);
        $responsesDeleted += $delResp->rowCount();

        // [W2 / audit_events PII] Delete every audit_events row keyed to
        // these instances -- entity_type IN ('instance','response') with
        // entity_id IN (chunk). This is the same instance id for both types
        // (WaiverController::audit('response', $instance['id'], ...) reuses
        // the waiver_instances.id, never waiver_responses.id -- see
        // submitGuestForm's 'submitted' audit call), so one IN-clause on
        // entity_id covers the 'created'/'voided'/'age_gate_rejected'/
        // 'submitted'/'webhook_failed'/'evidence_upload_failed'/
        // 'linked_to_reservation' events alike. The 'submitted' row in
        // particular carries the full guest answers payload (name, DOB,
        // medical fields, signer_ip/ua) in meta_json -- retaining it after
        // erasing waiver_responses would leave that exact PII recoverable
        // from the audit trail, defeating the erasure.
        $delAudit = $pdo->prepare("DELETE FROM audit_events WHERE entity_type IN ('instance','response') AND entity_id IN ($in)");
        $delAudit->execute($chunk);
        $auditEventsDeleted += $delAudit->rowCount();

        $delInst = $pdo->prepare('DELETE FROM waiver_instances WHERE id IN ('.$in.')');
        $delInst->execute($chunk);
        $instancesDeleted += $delInst->rowCount();
      }

      $this->auditErasure($instancesDeleted, $responsesDeleted, $filesDeleted, $auditEventsDeleted);

      $pdo->commit();
    } catch (\Throwable $e) {
      // Roll back EVERY DELETE issued above -- a partial erasure (e.g.
      // waiver_responses gone but waiver_instances/audit_events still
      // present) is worse than no erasure at all: it would report success
      // to a caller that has no way to know some PII survived. Files already
      // unlinked in this failed attempt cannot be un-deleted (filesystem has
      // no transaction), but that is fail-SAFE for GDPR purposes (erasure ran
      // ahead, not behind) and the caller's retry will simply find those
      // paths already gone (is_file() false -> filesDeleted undercounts on
      // retry, never a correctness issue).
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }

    return [
      'instances_deleted' => $instancesDeleted,
      'responses_deleted' => $responsesDeleted,
      'files_deleted' => $filesDeleted,
      'audit_events_deleted' => $auditEventsDeleted,
    ];
  }

  // [FK-erase] Audit row for an erasure MUST contain no PII whatsoever --
  // not the customer_id/booking_group_id/link_tokens that were erased, not
  // guest names, nothing that identifies the subject. Counts only. entity_id
  // is a synthetic 0 (an erasure call spans N instances, not one entity).
  // This row is itself written to audit_events with entity_type='erasure'
  // (distinct from 'instance'/'response'), so eraseWaiver's own
  // entity_type IN ('instance','response') cleanup DELETE never removes the
  // erasure record it is about to write -- the erasure event is the durable
  // "this subject's waiver PII was erased on this date" record and must
  // survive the very erasure it documents.
  private function auditErasure(int $instancesDeleted, int $responsesDeleted, int $filesDeleted, int $auditEventsDeleted = 0): void {
    $this->audit('erasure', 0, 'erase_waiver', [
      'instances_deleted' => $instancesDeleted,
      'responses_deleted' => $responsesDeleted,
      'files_deleted' => $filesDeleted,
      'audit_events_deleted' => $auditEventsDeleted,
    ]);
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
