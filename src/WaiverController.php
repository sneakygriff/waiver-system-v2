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
        // [GVS-89] SELECT * (not a named is_public column) so this retry path
        // never depends on migrations/006_public_instances.sql having landed.
        $existing=$pdo->prepare('SELECT * FROM waiver_instances WHERE link_token=? LIMIT 1');
        $existing->execute([$link_token]); $row=$existing->fetch();
        if($row){
          // [GVS-89] One token = one kind of instance. A token that already
          // belongs to a PUBLIC (reception-QR) instance is never "reused" as a
          // reservation-bound one -- that would hand BookingV2 a public
          // instance's link as a participant's (and route its completion to the
          // public-signup path). Mirror of createPublicInstance's own guard.
          if(!empty($row['is_public'])) return ['error'=>'link_token_conflict'];
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
  // - evidence_sha256/evidence_object_key/evidence_blob_key/evidence_blob_url
  //   [T5 / migrations/005_evidence_fields.sql]: read straight off the
  //   waiver_responses row (persisted by submitGuestForm once uploadEvidence()
  //   confirms -- see there). All four are null for a row completed before
  //   005 shipped, or whose relay upload never confirmed -- BookingV2's
  //   toCompletionFields maps `?? undefined` for each, which is the correct,
  //   already-supported shape (waiver-reconcile.ts is unchanged by T5).
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
      // [T5] real value now (was hardcoded null pre-005) -- see doc above.
      'evidence_sha256'        => isset($row['evidence_sha256']) && $row['evidence_sha256'] !== null ? (string)$row['evidence_sha256'] : null,
      'evidence_object_key'    => isset($row['evidence_object_key']) && $row['evidence_object_key'] !== null ? (string)$row['evidence_object_key'] : null,
      'evidence_blob_key'      => isset($row['evidence_blob_key']) && $row['evidence_blob_key'] !== null ? (string)$row['evidence_blob_key'] : null,
      'evidence_blob_url'      => isset($row['evidence_blob_url']) && $row['evidence_blob_url'] !== null ? (string)$row['evidence_blob_url'] : null,
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
  // [T5] wr.evidence_sha256/evidence_object_key/evidence_blob_key/
  // evidence_blob_url added (migrations/005_evidence_fields.sql) so
  // statusRow() can return them instead of a hardcoded null -- see there.
  private const STATUS_SELECT = 'SELECT wi.id, wi.link_token, wi.status, wi.completed_at, wi.participant_id, wi.customer_id, wi.booking_group_id, wtv.version as form_version, wr.hash_sha256, wr.answers_json, wr.signer_full_name, wr.evidence_sha256, wr.evidence_object_key, wr.evidence_blob_key, wr.evidence_blob_url
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

  // ---------------------------------------------------------------------------
  // [GVS-89 / 89-M4.2] Reception-QR PUBLIC instances.
  //
  // BookingV2's public page (/waiver/receptie) mints a walk-in signup with its
  // own random token, then calls create_public_instance so the guest can sign on
  // this fork's w.php. A public instance is an ordinary waiver_instances row
  // with is_public=1, expires_at set, and NO reservation binding
  // (reservation_id / participant_id / customer_id / booking_group_id all
  // NULL -- they are nullable since 001_init.sql) and NO guest_email (this fork
  // never learns the signer's email; BookingV2 keeps it on its signup row). The
  // signup token is stored in the EXISTING link_token column, so w.php?token=,
  // uploadEvidence() and get_status keep working unchanged
  // (decomposition §4 DEFAULT #13); on the wire `signup_token` == link_token.
  //
  // Token-kind invariant: one link_token is EITHER public OR reservation-bound,
  // never both -- createPublicInstance never "reuses" a reservation-bound row
  // and createInstance never "reuses" a public one (link_token_conflict).
  //
  // Expiry (renderGuestForm): once expires_at has passed, w.php refuses to
  // RENDER a public instance's form (410 + a localized "scan the QR again"
  // page). public_status keeps answering after expiry -- BookingV2 reconciles a
  // public signup until its own retention deadline R = expires_at + 7 days.
  // ---------------------------------------------------------------------------

  // Same wire charset as create_waiver's optional link_token (SPEC G1a), but
  // anchored with \A...\z: PCRE's `$` also matches before a trailing "\n", which
  // would let "<token>\n" through into link_token and the signing URL.
  private const LINK_TOKEN_PATTERN = '/\A[A-Za-z0-9_-]{16,128}\z/';
  private const PUBLIC_LOCALES = ['ro', 'en'];
  // Sanity ceiling on expires_at (BookingV2 mints expiresAt = now + 2h). Bounds
  // how long a leaked public link could stay renderable if a caller bug ever
  // sent a far-future expiry; equal to BookingV2's 7-day retention window.
  private const PUBLIC_MAX_TTL_SECONDS = 7 * 24 * 3600;
  // [GVS-89 / orchestrator decision, 2026-09-19] Grace window for a SUBMIT
  // (as opposed to a render) of a public instance past its expires_at: a
  // person who opened the form before expiry must still be able to send it.
  // The render gate (isExpiredPublicInstance) has no such grace -- a fresh
  // GET after expires_at always 410s, which is exactly what re-mints a form
  // via a new QR scan; this grace exists only for a form already open in a
  // guest's hand at the moment expires_at passed.
  private const PUBLIC_SUBMIT_GRACE_SECONDS = 60 * 60;
  // Guest-facing copy for the expired-link page (w.php), by instance locale.
  private const PUBLIC_EXPIRED_COPY = [
    'ro' => ['title' => 'Link expirat', 'message' => 'Acest link a expirat — te rugăm să scanezi din nou codul QR.'],
    'en' => ['title' => 'Link expired', 'message' => 'This link has expired — please scan the QR again.'],
  ];

  private static function invalidRequest(string $detail): array {
    return ['error'=>'invalid_request', 'detail'=>$detail];
  }

  private function guestLink(string $linkToken): string {
    return rtrim($this->cfg['app']['base_url'],'/').'/w.php?token='.$linkToken;
  }

  // Strict ISO-8601 instant WITH a timezone designator ('Z' or +hh:mm/-hh:mm),
  // e.g. JS Date#toISOString() "2026-09-19T14:00:00.000Z". Returns the Unix
  // timestamp (fractional seconds truncated) or null. A string with no zone
  // designator is ambiguous (this app's default timezone is Europe/Bucharest,
  // not UTC) and is rejected rather than guessed; so are impossible calendar
  // values (2026-09-31, 24:30:00) that PHP's parser would silently roll over.
  // `public` (like evidenceUrlFor) only so tests/WaiverControllerPublicTest.php
  // can pin it directly without reflection.
  public static function parseIsoInstant($raw): ?int {
    if (!is_string($raw)) return null;
    if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,9})?(Z|([+-])(\d{2}):(\d{2}))\z/', $raw, $m)) return null;
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[4] > 23 || (int)$m[5] > 59 || (int)$m[6] > 59) return null;
    $offset = '+00:00';
    if ($m[7] !== 'Z') {
      if ((int)$m[9] > 14 || (int)$m[10] > 59) return null;
      $offset = $m[8].$m[9].':'.$m[10];
    }
    try {
      $dt = new \DateTimeImmutable($m[1].'-'.$m[2].'-'.$m[3].'T'.$m[4].':'.$m[5].':'.$m[6].$offset);
    } catch (\Exception $e) {
      return null;
    }
    return $dt->getTimestamp();
  }

  // create_public_instance {template_id, link_token, locale, expires_at}
  //   -> {ok:true, waiver_id, link, link_token, reused}
  // Errors: {error:'invalid_request', detail} (400) for any malformed field;
  // {error:'no_published_version'} (400, same published-version gate as
  // create_waiver); {error:'template_missing_dob', detail} (400) when the
  // published version has no date-of-birth field to age-gate on, or
  // {error:'template_ambiguous_dob', detail} (400) when it has several date
  // fields and no single one keyed as the DOB (adults-only fails closed --
  // see below); {error:'link_token_conflict'} (409) when the
  // token already belongs to a NON-public instance.
  // IDEMPOTENT per link_token: a retry with a token that already names a public
  // instance returns that SAME instance with reused:true and changes nothing
  // (the stored template version / locale / expires_at win), so BookingV2's
  // bounded transient retry can never double-create. The UNIQUE(link_token)
  // index makes this race-safe: of two concurrent creates exactly one INSERT
  // wins and the other takes the duplicate-key (SQLSTATE 23000) reuse path.
  public function createPublicInstance(array $payload): array {
    $templateId = $payload['template_id'] ?? null;
    $linkToken  = $payload['link_token'] ?? null;
    $locale     = $payload['locale'] ?? null;
    $expiresRaw = $payload['expires_at'] ?? null;

    if (is_bool($templateId) || !is_scalar($templateId) || !preg_match('/\A[1-9][0-9]{0,18}\z/', (string)$templateId)) {
      return self::invalidRequest('template_id must be a positive integer');
    }
    if (!is_string($linkToken) || !preg_match(self::LINK_TOKEN_PATTERN, $linkToken)) {
      return self::invalidRequest('link_token must be 16-128 characters of [A-Za-z0-9_-]');
    }
    if (!is_string($locale) || !in_array($locale, self::PUBLIC_LOCALES, true)) {
      return self::invalidRequest('locale must be "ro" or "en"');
    }
    $expiresTs = self::parseIsoInstant($expiresRaw);
    if ($expiresTs === null) {
      return self::invalidRequest('expires_at must be an ISO-8601 timestamp with a timezone designator, e.g. 2026-09-19T14:00:00.000Z');
    }

    $pdo = $this->db->pdo();
    // [GVS-89 / gate 89-M4 P2-6] ONE clock for public expiry: the DB's.
    // The render gate and the submit gate both compare expires_at against
    // UTC_TIMESTAMP(), so the future/ceiling checks here must too -- a
    // PHP-process clock skewed against MySQL (Railway runs them as separate
    // services) could otherwise accept an expires_at the gates already treat
    // as past, minting a link that 410s on first open.
    $now = $this->dbNowUtcTs();
    if ($now === null) throw new \RuntimeException('could not read the database clock (UTC_TIMESTAMP)');
    if ($expiresTs <= $now) return self::invalidRequest('expires_at must be in the future');
    if ($expiresTs > $now + self::PUBLIC_MAX_TTL_SECONDS) return self::invalidRequest('expires_at must be at most 7 days in the future');
    // Stored as a UTC DATETIME, independent of the app's default timezone.
    $expiresAt = gmdate('Y-m-d H:i:s', $expiresTs);

    // [Gap1] Same published-version gate as createInstance: never fall back to
    // a draft version.
    $v = $pdo->prepare('SELECT id, fields_json FROM waiver_template_versions WHERE template_id=? AND is_published=1 ORDER BY version DESC LIMIT 1');
    $v->execute([(string)$templateId]);
    $version = $v->fetch();
    if (!$version) return ['error'=>'no_published_version'];

    // [gate 89-M4 r3 P2-5 fix / Codex r2] Idempotency check BEFORE the DOB
    // gate below. A retry of a create call that already succeeded (a lost
    // response, a network timeout -- exactly what link_token idempotency
    // exists for) must return reused:true for its own existing instance even
    // if the template has SINCE been republished into an unresolvable DOB
    // shape (missing/ambiguous). Checking DOB first would answer
    // template_missing_dob/template_ambiguous_dob for a token that already
    // names a perfectly good, already-minted instance -- turning a harmless
    // retry into a hard failure over a LATER template edit unrelated to it.
    // Mirrors the existing duplicate-key (23000) reuse path below (which the
    // INSERT's UNIQUE(link_token) index makes race-safe for two concurrent
    // creates of the SAME token) but runs as a plain SELECT first, since the
    // INSERT has not been attempted yet.
    $existingPublic = $pdo->prepare('SELECT id, is_public FROM waiver_instances WHERE link_token=? LIMIT 1');
    $existingPublic->execute([$linkToken]);
    if ($existingRow = $existingPublic->fetch()) {
      if ((int)$existingRow['is_public'] !== 1) return ['error'=>'link_token_conflict'];
      return ['ok'=>true, 'waiver_id'=>(int)$existingRow['id'], 'link'=>$this->guestLink($linkToken), 'link_token'=>$linkToken, 'reused'=>true];
    }

    // [GVS-89 / gate 89-M4 P1] Adults-only must FAIL CLOSED. The reception-QR
    // flow is adults-only (AC4) and the only thing that can enforce it on the
    // fork is evaluateAgeGate(), which needs the template's date-of-birth field.
    // A published version WITHOUT one would make the gate a silent no-op
    // (computed_age null -> any age completes, and the completion is then
    // un-ingestible by BookingV2, whose public schema requires an integer
    // computed_age). [gate 89-M4 r2 P1] Nor may the gate GUESS the field: with
    // several date fields and none explicitly keyed as the DOB it could
    // age-gate on e.g. a visit date. resolvePublicDobField() decides; refuse
    // to mint the instance at all (template_missing_dob /
    // template_ambiguous_dob, both 400 and terminal) when it cannot.
    $dob = $this->resolvePublicDobField($this->normalizeFields($version['fields_json']));
    if ($dob['error'] !== null) {
      return ['error'=>$dob['error'], 'detail'=>$dob['detail']];
    }

    try {
      $stmt = $pdo->prepare('INSERT INTO waiver_instances (template_version_id, reservation_id, participant_id, customer_id, booking_group_id, group_token, guest_name, guest_email, link_token, status, is_public, expires_at, locale, created_at, updated_at) VALUES (?,NULL,NULL,NULL,NULL,NULL,NULL,NULL,?,"pending",1,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
      $stmt->execute([$version['id'], $linkToken, $expiresAt, $locale]);
    } catch (\PDOException $e) {
      if ($e->getCode() === '23000') {
        $existing = $pdo->prepare('SELECT id, is_public FROM waiver_instances WHERE link_token=? LIMIT 1');
        $existing->execute([$linkToken]);
        $row = $existing->fetch();
        if ($row) {
          if ((int)$row['is_public'] !== 1) return ['error'=>'link_token_conflict'];
          return ['ok'=>true, 'waiver_id'=>(int)$row['id'], 'link'=>$this->guestLink($linkToken), 'link_token'=>$linkToken, 'reused'=>true];
        }
      }
      throw $e;
    }

    $id = (int)$pdo->lastInsertId();
    // No PII and no token in the audit row: the token is a bearer credential
    // for the signing page, and this fork never holds the signer's email.
    $this->audit('instance', $id, 'created', ['public'=>true, 'template_version_id'=>(int)$version['id'], 'locale'=>$locale, 'expires_at'=>$expiresAt]);
    return ['ok'=>true, 'waiver_id'=>$id, 'link'=>$this->guestLink($linkToken), 'link_token'=>$linkToken, 'reused'=>false];
  }

  // public_status {link_token} -> the get_status single-token row shape
  // (statusRow) with the three binding ids forced to null, plus
  // is_public:true and expires_at (ISO-8601 UTC). Answers ONLY for public
  // instances: a reservation-bound (or unknown) token is {error:'token_unknown'}
  // (404), byte-identical to a token that does not exist at all, so this action
  // is no oracle for the other token kind. Keeps answering after expires_at
  // (BookingV2 reconciles until expires_at + 7 days).
  public function publicStatus(array $payload): array {
    $linkToken = $payload['link_token'] ?? null;
    if (!is_string($linkToken) || $linkToken === '' || strlen($linkToken) > 128) {
      return self::invalidRequest('link_token must be a non-empty string (max 128)');
    }
    $pdo = $this->db->pdo();
    $gate = $pdo->prepare('SELECT id, expires_at FROM waiver_instances WHERE link_token=? AND is_public=1 LIMIT 1');
    $gate->execute([$linkToken]);
    $public = $gate->fetch();
    if (!$public) return ['error'=>'token_unknown'];

    $q = $pdo->prepare(self::STATUS_SELECT.' WHERE wi.id=? LIMIT 1');
    $q->execute([(int)$public['id']]);
    $row = $q->fetch();
    // Erased (erase_waiver) between the two reads: same answer as unknown.
    if (!$row) return ['error'=>'token_unknown'];

    $out = $this->statusRow($row);
    // Contract: binding ids are null on a public row. They are NULL in the
    // table by construction; forcing them here keeps the wire guarantee even
    // if a row were ever hand-edited, since BookingV2's public ingest rejects
    // a body that carries a participant binding.
    $out['participant_id'] = null;
    $out['customer_id'] = null;
    $out['booking_group_id'] = null;
    $out['is_public'] = true;
    $out['expires_at'] = $public['expires_at'] !== null ? gmdate('c', strtotime($public['expires_at'].' UTC')) : null;
    return $out;
  }

  // [GVS-89 / 89-M4.4 / §4 #14 / AC7] resend_evidence {link_token} ->
  // {ok:true, pushed:bool}. Evidence recovery for a completion whose
  // ORIGINAL upload to BookingV2's evidence relay (uploadEvidence(), called
  // from submitGuestForm at signing time) never confirmed. That failure path
  // is exactly the one that RETAINS the signed PDF/signature PNG locally
  // (waiver_responses.pdf_path/signature_path stay non-NULL -- see
  // submitGuestForm's own [FK-T15 / FK-evidence-keep] doc comment) instead
  // of deleting them, so this is the one case with real bytes left to
  // re-push. Re-runs the SAME private uploadEvidence() helper submitGuestForm
  // uses; on a confirmed push it updates the four evidence columns, clears
  // pdf_path/signature_path, and deletes the local files -- mirroring
  // submitGuestForm's own post-confirm finalization exactly, so a LATER
  // resend_evidence call (or a fresh reconcile pass) sees "already durably
  // stored" and no-ops cleanly rather than re-reading stale files.
  //
  // Not restricted to is_public=1: BookingV2's reconcile cron only ever
  // calls this for a used public signup (89-M1.6), but the action itself is
  // generically "re-push a completed instance's evidence", exactly as
  // decomposition §7.4 89-M4.4 specifies -- a reservation-bound instance
  // whose original upload failed benefits from it too, and restricting it
  // would just be an arbitrary asymmetry with no contract behind it.
  //
  // pushed:false is NEVER an error -- it covers every "nothing NEW to push"
  // case: pending/void instance (nothing was ever signed), no waiver_responses
  // row at all (defends a hand-edited/corrupt 'completed' row), evidence
  // already durably stored (the first upload already confirmed -- nothing
  // retained locally), the retained file missing/unreadable on disk, or the
  // relay still down/refusing (uploadEvidence() itself returns a null object
  // key -- the row and any remaining files are left exactly as they were for
  // a later attempt). Unknown token is the one 404-shaped case, matching
  // every other link_token lookup in this file. A malformed token is
  // {error:'invalid_request', detail} (400) -- the same envelope as the two
  // sibling GVS-89 actions (create_public_instance, public_status).
  //
  // [GVS-89 / gate 89-M4 P1] SERIALIZED AGAINST GDPR ERASURE. This action
  // reads retained evidence, pushes it OUT of this system (BookingV2's blob
  // store) and only then records the push. Unserialized, an eraseWaiver()
  // committing between the upload and the UPDATE would leave the signed PDF
  // uploaded with no fork-side pointer and write an orphan 'evidence_resent'
  // audit event for an instance that no longer exists. Both methods therefore
  // take the SAME per-instance MySQL named lock (EVIDENCE_LOCK_NAME_SQL) around
  // their whole read -> act -> write sequence (and so does submitGuestForm()
  // around its first upload + record, gate 89-M4 r2):
  //   - resend holds it from the post-lock re-read through the upload, the
  //     UPDATE and the audit write, so an erase can never interleave: it
  //     either ran entirely BEFORE (the re-read finds no row -> token_unknown,
  //     nothing uploaded) or runs entirely AFTER (and then erases the rows,
  //     the audit trail and any retained files this resend left).
  //   - resend never WAITS for the lock (RESEND_EVIDENCE_LOCK_WAIT_SECONDS=0):
  //     if an erase (or another resend of the same instance) holds it, this
  //     call is a clean pushed:false and the reconcile caller retries later.
  // A named lock (not SELECT ... FOR UPDATE) is deliberate: it is held across
  // HTTP I/O without keeping a DB transaction -- and its row locks -- open for
  // the upload's retry budget, which this file never does (see
  // submitGuestForm's SCOPE note). MySQL releases it if the process dies.
  // Belt-and-suspenders for a deletion that bypasses the lock (manual SQL):
  // the final UPDATE is conditional and its rowCount is checked, so a vanished
  // row never gets an audit event (see below).
  public function resendEvidence(array $payload): array {
    $linkToken = $payload['link_token'] ?? null;
    if (!is_string($linkToken) || $linkToken === '' || strlen($linkToken) > 128) {
      return self::invalidRequest('link_token must be a non-empty string (max 128)');
    }

    $pdo = $this->db->pdo();
    $find = $pdo->prepare('SELECT id FROM waiver_instances WHERE link_token=? LIMIT 1');
    $find->execute([$linkToken]);
    $found = $find->fetch();
    if (!$found) return ['error'=>'token_unknown'];
    $instanceId = (int)$found['id'];

    if (!$this->acquireEvidenceLock($instanceId, $this->evidenceLockWaitSeconds('resend_wait_seconds', self::RESEND_EVIDENCE_LOCK_WAIT_SECONDS))) {
      // An erase (or a concurrent resend of this same instance) is in
      // flight. Nothing pushed by THIS call; the caller retries later and
      // will then see either the finished push or token_unknown.
      return ['ok'=>true, 'pushed'=>false];
    }
    try {
      // Re-read UNDER the lock: this is the authoritative state -- an erase
      // that won the race before we got here has deleted the row.
      $q = $pdo->prepare('SELECT wi.id, wi.link_token, wi.status, wr.id AS response_id, wr.pdf_path, wr.signature_path, wr.evidence_object_key
        FROM waiver_instances wi LEFT JOIN waiver_responses wr ON wr.waiver_instance_id = wi.id
        WHERE wi.id=? LIMIT 1');
      $q->execute([$instanceId]);
      $row = $q->fetch();
      if (!$row) return ['error'=>'token_unknown'];

      // Pending/void (never signed) or a 'completed' row with no response row
      // at all (should not happen, but a hand-edited/corrupt row must not
      // fatal this action) -> nothing to push.
      if ($row['status'] !== 'completed' || $row['response_id'] === null) {
        return ['ok'=>true, 'pushed'=>false];
      }
      // The FIRST upload already confirmed -- nothing retained locally to
      // re-push (submitGuestForm nulls pdf_path/signature_path exactly when
      // evidence_object_key gets set).
      if (!empty($row['evidence_object_key'])) {
        return ['ok'=>true, 'pushed'=>false];
      }

      $pdfPath = $row['pdf_path'] ?? null;
      if ($pdfPath === null || !is_file($pdfPath)) {
        return ['ok'=>true, 'pushed'=>false];
      }
      $sigPath = $row['signature_path'] ?? null;
      if ($sigPath !== null && !is_file($sigPath)) $sigPath = null;

      $evidence = $this->uploadEvidence(['id'=>$instanceId, 'link_token'=>(string)$row['link_token']], $pdfPath, $sigPath);
      if ($evidence['evidence_object_key'] === null) {
        // Still down/refusing -- leave the row and the retained files exactly
        // as they were for a later attempt.
        return ['ok'=>true, 'pushed'=>false];
      }

      // Conditional on the row still being the one we read (present, not yet
      // durably stored); the push record and its audit event commit together.
      $pdo->beginTransaction();
      try {
        $upd = $pdo->prepare('UPDATE waiver_responses SET evidence_sha256=?, evidence_object_key=?, evidence_blob_key=?, evidence_blob_url=?, pdf_path=NULL, signature_path=NULL WHERE id=? AND waiver_instance_id=? AND evidence_object_key IS NULL');
        $upd->execute([$evidence['evidence_sha256'], $evidence['evidence_object_key'], $evidence['evidence_blob_key'], $evidence['evidence_blob_url'], (int)$row['response_id'], $instanceId]);
        $recorded = $upd->rowCount() === 1;
        if ($recorded) {
          $this->audit('instance', $instanceId, 'evidence_resent', ['response_id'=>(int)$row['response_id']]);
        }
        $pdo->commit();
      } catch (\Throwable $txEx) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $txEx;
      }

      if (!$recorded) {
        // The response row vanished (or changed) between the re-read and the
        // UPDATE -- only possible for a deletion that bypassed the evidence
        // lock. Never write an audit event for it (it would outlive the
        // erasure as an orphan). If the row is GONE, the retained files are
        // now unreachable orphans of an erased subject: remove them. If it
        // still exists, it still points at them: leave them alone.
        $still = $pdo->prepare('SELECT 1 FROM waiver_responses WHERE id=? LIMIT 1');
        $still->execute([(int)$row['response_id']]);
        if ($still->fetch()) return ['ok'=>true, 'pushed'=>false];
        if (is_file($pdfPath)) @unlink($pdfPath);
        if ($sigPath !== null && is_file($sigPath)) @unlink($sigPath);
        return ['error'=>'token_unknown'];
      }

      // Durably stored now -- remove the retained local copies, exactly like
      // submitGuestForm's own post-confirm cleanup.
      if (is_file($pdfPath)) @unlink($pdfPath);
      if ($sigPath !== null && is_file($sigPath)) @unlink($sigPath);
      return ['ok'=>true, 'pushed'=>true];
    } finally {
      $this->releaseEvidenceLock($instanceId);
    }
  }

  // [GVS-89 / gate 89-M4 P1] Per-instance evidence lock shared by
  // resendEvidence(), submitGuestForm() and eraseWaiver() -- see
  // resendEvidence()'s and submitGuestForm()'s docs. MySQL
  // named locks are SERVER-wide (not per schema), so the name is scoped by a
  // hash of DATABASE(): two schemas on one server (e.g. waiver_db and
  // waiver_test in compose) never contend on the same instance id. The name
  // stays well under MySQL's 64-character limit. Built server-side from the
  // instance id (the one bound parameter) so acquire and release can never
  // disagree on it.
  private const EVIDENCE_LOCK_NAME_SQL = "CONCAT('wvr_evidence:', LEFT(SHA1(DATABASE()), 16), ':', ?)";
  private const RESEND_EVIDENCE_LOCK_WAIT_SECONDS = 0;
  // Erase waits a little for an in-flight resend to finish (one resend holds
  // the lock for at most one uploadEvidence() retry budget, ~16 s when the
  // relay is down). Kept BELOW BookingV2's 8 s fork request timeout so the
  // caller gets a real answer: on timeout erase answers {error:'evidence_busy'}
  // (503) and deletes nothing; BookingV2's erasure worker retries the whole
  // call on its outbox backoff.
  private const ERASE_EVIDENCE_LOCK_WAIT_SECONDS = 5;
  // [gate 89-M4 r2] submitGuestForm's wait: long enough for the usual holder
  // (an erase transaction, or a resend with nothing to push) to finish, short
  // enough not to stall a guest's POST behind an erase that is waiting on
  // other instances' locks. See submitGuestForm().
  private const SUBMIT_EVIDENCE_LOCK_WAIT_SECONDS = 2;

  private function evidenceLockWaitSeconds(string $key, int $default): int {
    $v = $this->cfg['evidence_lock'][$key] ?? null;
    return is_int($v) && $v >= 0 ? $v : $default;
  }

  private function acquireEvidenceLock(int $instanceId, int $waitSeconds): bool {
    $q = $this->db->pdo()->prepare('SELECT GET_LOCK('.self::EVIDENCE_LOCK_NAME_SQL.', ?)');
    $q->execute([(string)$instanceId, $waitSeconds]);
    return (int)$q->fetchColumn() === 1;
  }

  private function releaseEvidenceLock(int $instanceId): void {
    try {
      $q = $this->db->pdo()->prepare('SELECT RELEASE_LOCK('.self::EVIDENCE_LOCK_NAME_SQL.')');
      $q->execute([(string)$instanceId]);
    } catch (\Throwable $e) {
      // Best-effort: a dead connection has already released every named lock
      // it held (MySQL drops them with the session).
    }
  }

  // [GVS-89 / gate 89-M4 P2-6] The DB clock (UTC_TIMESTAMP()) as a Unix
  // timestamp -- the same clock the render/submit expiry gates read.
  private function dbNowUtcTs(): ?int {
    $raw = $this->db->pdo()->query('SELECT UTC_TIMESTAMP() AS now_utc')->fetchColumn();
    if (!is_string($raw)) return null;
    $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $raw, new \DateTimeZone('UTC'));
    return $dt === false ? null : $dt->getTimestamp();
  }

  // Shared UTC parse for the two public-expiry gates below (render and
  // submit). Returns [expiresDt, nowDt], or null if $row has no expires_at,
  // no db_now_utc, or either value is unparseable -- both callers treat null
  // as fail-closed (expired), so neither has to repeat this parsing.
  // [gate 89-M4 P2-6] "now" is ONLY ever the DB clock (db_now_utc, selected
  // as UTC_TIMESTAMP() by the caller's own query); there is deliberately no
  // PHP-clock fallback, so a caller that forgets to select it fails closed
  // instead of silently mixing clocks.
  private function parsePublicExpiryClock(array $row): ?array {
    $expires = $row['expires_at'] ?? null;
    $nowRaw = $row['db_now_utc'] ?? null;
    if ($expires === null || $expires === '' || $nowRaw === null || $nowRaw === '') return null;
    $utc = new \DateTimeZone('UTC');
    $expiresDt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string)$expires, $utc);
    $nowDt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string)$nowRaw, $utc);
    if ($expiresDt === false || $nowDt === false) return null;
    return [$expiresDt, $nowDt];
  }

  // Render gate for a PUBLIC instance: expired iff expires_at < now (UTC, the
  // DB clock -- $row['db_now_utc'] from renderGuestForm's own SELECT). Fails
  // CLOSED: a public row with no expires_at, or an unparseable value, counts as
  // expired. Non-public rows (and a schema that predates 006, where the column
  // is simply absent from wi.*) are never expiry-gated.
  private function isExpiredPublicInstance(array $row): bool {
    if (empty($row['is_public'])) return false;
    $clock = $this->parsePublicExpiryClock($row);
    if ($clock === null) return true;
    [$expiresDt, $nowDt] = $clock;
    return $expiresDt < $nowDt;
  }

  // [GVS-89 / orchestrator decision, 2026-09-19] Submit-time counterpart to
  // isExpiredPublicInstance(): a public instance's SUBMIT (as opposed to
  // render) is refused only once now is past expires_at PLUS
  // PUBLIC_SUBMIT_GRACE_SECONDS -- a person who already opened the form
  // before expires_at can still send it within the grace window, even
  // though a fresh GET at that same moment would already 410. Deliberately
  // its own method rather than isExpiredPublicInstance() with a $grace
  // parameter: render (w.php GET) and submit (w.php POST) must compare
  // against genuinely different instants, and a shared parameterized method
  // would let a future call site accidentally pass the wrong one -- e.g.
  // rendering an already-past-expiry-but-within-grace form (never
  // intended: the render gate exists precisely to force a fresh QR scan)
  // or refusing a submit the render gate itself already allowed. Same
  // fail-closed rule as the render gate: unparseable/missing expires_at
  // counts as expired.
  private function isSubmitExpiredPublicInstance(array $row): bool {
    if (empty($row['is_public'])) return false;
    $clock = $this->parsePublicExpiryClock($row);
    if ($clock === null) return true;
    [$expiresDt, $nowDt] = $clock;
    return $expiresDt->modify('+'.self::PUBLIC_SUBMIT_GRACE_SECONDS.' seconds') < $nowDt;
  }

  // Localized copy for the expired-link page; any locale other than 'en'
  // (including NULL) falls back to Romanian, BookingV2's default mint locale.
  public static function publicExpiredCopy(?string $locale): array {
    $lang = $locale === 'en' ? 'en' : 'ro';
    return self::PUBLIC_EXPIRED_COPY[$lang] + ['locale' => $lang];
  }

  // [GVS-89 / 89-M4.3] Guest-facing copy for submitGuestForm()'s adults-only
  // public refusal ('minor_requires_staff') -- same fallback rule as
  // publicExpiredCopy (anything other than 'en', including NULL, is
  // Romanian). Unlike the expired page, this renders inside w.php's ordinary
  // "re-show the form with an error banner" path (a public row's locale, not
  // a separate standalone page), so only a message is needed here.
  private const PUBLIC_MINOR_STAFF_COPY = [
    'ro' => ['message' => 'Acest formular este disponibil doar pentru persoane cu vârsta de 18 ani sau peste. Te rugăm să te adresezi unui membru al echipei.'],
    'en' => ['message' => 'This form is only available to signers 18 or older. Please ask a staff member for help.'],
  ];

  // `public` (like publicExpiredCopy) so tests can pin it directly.
  public static function publicMinorStaffCopy(?string $locale): array {
    $lang = $locale === 'en' ? 'en' : 'ro';
    return self::PUBLIC_MINOR_STAFF_COPY[$lang] + ['locale' => $lang];
  }

  // [GVS-89 / gate 89-M4 P1] Guest-facing copy for the fail-closed
  // 'age_unverifiable' refusal: a PUBLIC instance whose template version has
  // no (unambiguous) date-of-birth field to age-gate on (create_public_instance refuses to
  // mint such an instance, so this is defense in depth for a template edited
  // after minting). The signer cannot fix that, so route them to staff --
  // without claiming they are under 18.
  private const PUBLIC_AGE_UNVERIFIABLE_COPY = [
    'ro' => ['message' => 'Nu putem verifica vârsta pe acest formular. Te rugăm să te adresezi unui membru al echipei.'],
    'en' => ['message' => "We can't verify your age on this form. Please ask a staff member for help."],
  ];

  public static function publicAgeUnverifiableCopy(?string $locale): array {
    $lang = $locale === 'en' ? 'en' : 'ro';
    return self::PUBLIC_AGE_UNVERIFIABLE_COPY[$lang] + ['locale' => $lang];
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

  // w.php GET: render the signing form. A PUBLIC instance past its
  // expires_at is refused (410) with NO grace -- a fresh open after expiry
  // must go back through the reception QR, which mints a new link.
  public function renderGuestForm(string $token): array {
    return $this->loadGuestForm($token, false);
  }

  // [GVS-89 / gate 89-M4 P2-2] w.php POST that submitGuestForm() REJECTED
  // with a validation error (missing field, bad DOB, minor_requires_staff,
  // ...): re-render the SAME form with that error. For a public instance the
  // expiry check here is the SUBMIT gate (expires_at + 60-min grace) that
  // just accepted this POST, not the no-grace render gate: otherwise a guest
  // whose form was opened before expiry and who is inside the grace window
  // the submit gate grants would get a 410 "scan the QR again" instead of
  // their validation error (masking it, incl. the adults-only copy) and lose
  // the open form. It widens rendering only to POSTs that the submit gate
  // itself accepts -- past the grace the submit gate 410s first and w.php
  // never reaches this. Separately named (not a bool on renderGuestForm) so
  // a GET call site can never pick the grace clock by accident.
  public function rerenderGuestFormAfterRejectedSubmit(string $token): array {
    return $this->loadGuestForm($token, true);
  }

  private function loadGuestForm(string $token, bool $afterRejectedSubmit): array {
    // [GVS-89] UTC_TIMESTAMP() rides along so the public-expiry gate compares
    // against the DB clock (the same clock that stamps created_at/updated_at).
    // The new 006 columns are read through wi.* -- never named here -- so this
    // query cannot break on a schema that predates 006.
    $q=$this->db->pdo()->prepare('SELECT wi.*, wtv.title, wtv.description, wtv.fields_json, wtv.content_html, wtv.print_css, UTC_TIMESTAMP() AS db_now_utc FROM waiver_instances wi JOIN waiver_template_versions wtv ON wi.template_version_id=wtv.id WHERE link_token=? LIMIT 1');
    $q->execute([$token]); $row=$q->fetch();
    if(!$row) return ['error'=>'Invalid link'];
    if($row['status']==='completed') return ['error'=>'This waiver has already been completed.'];
    // [FK-void] A voided instance (D-1 rotate, or an explicit void) must not
    // even render a signable form -- distinct message from "completed" so a
    // guest opening a stale/rotated link understands to use their newest link.
    if($row['status']==='void') return ['error'=>'This waiver link is no longer valid. Please use the most recent link you were sent.'];
    // [GVS-89] A PUBLIC (reception-QR) instance past its expires_at never
    // renders a signable form: w.php answers 410 with a localized "scan the QR
    // again" page instead. Nothing about the instance (title, fields) is
    // returned on this path.
    $expired = $afterRejectedSubmit ? $this->isSubmitExpiredPublicInstance($row) : $this->isExpiredPublicInstance($row);
    if($expired){
      return self::publicExpiredResult(isset($row['locale']) ? (string)$row['locale'] : null);
    }
    unset($row['db_now_utc']);
    return ['instance'=>$row,'fields'=>$this->normalizeFields($row['fields_json'])];
  }

  // The ONE shape both expiry gates (render and submit) answer with, so w.php
  // renders the same localized 410 page for a GET and a POST.
  private static function publicExpiredResult(?string $locale): array {
    $copy = self::publicExpiredCopy($locale);
    return ['error'=>$copy['message'],'error_code'=>'expired','error_title'=>$copy['title'],'locale'=>$copy['locale'],'http_status'=>410];
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
  // claim and BEFORE any completion webhook. Given the template's DOB field
  // ($dobField, chosen by the caller -- see below), parses+validates it:
  //   - unparsable/future DOB                       -> reject (invalid_birth_date)
  //   - age < AGE_MIN_HARD_REJECT                    -> reject (age_below_minimum), no exceptions
  //   - age < AGE_PARENTAL_CONSENT_BELOW and no       -> reject (minor_parental_consent_missing)
  //     affirmatively-set parental-consent field
  //   - otherwise                                    -> pass
  // No DOB field ($dobField null) -> age-gating does not apply to this
  // template (nothing to gate on); pass through. WHICH field is the DOB is
  // the caller's decision (see firstDateField / resolvePublicDobField).
  // Returns ['ok'=>true, 'birth_date'=>?string, 'computed_age'=>?int, 'minor'=>?bool,
  //          'parental_consent_name'=>?string] on pass, or ['ok'=>false,'error'=>string] on reject.
  //
  // RESERVATION-BOUND rule (UNCHANGED since FK-T10): the template's FIRST
  // type=date field is its date of birth. Known limitation, deliberately NOT
  // changed here (gate 89-M4 r2 scoped the fix to public instances): a
  // reservation-bound template with another date field BEFORE its DOB field
  // age-gates on that other field.
  private function firstDateField(array $fields): ?array {
    foreach ($fields as $f) { if ($f['type'] === 'date') return $f; }
    return null;
  }

  // [GVS-89 / gate 89-M4 r2 P1] Field keys that EXPLICITLY mark a type=date
  // field as the date of birth. The template schema (fields_json objects:
  // key/label/type/required/options/maxLength -- see normalizeFields() and
  // admin.php's publish form) has no semantic-role attribute, so the field
  // KEY is the operator-controlled marker -- the same convention parental
  // consent already relies on (the conventional 'parental_consent_name' key).
  // Compared case-insensitively with '-' folded to '_' (DocxImportService
  // admits '-' in keys). 'date_of_birth' is the seeded production template's
  // key; 'birth_date' is the completion wire's own name for the value;
  // 'data_nasterii' is the Romanian spelling.
  private const DOB_FIELD_KEYS = ['date_of_birth', 'dob', 'birth_date', 'birthdate', 'data_nasterii'];

  // [GVS-89 / gate 89-M4 r2 P1] The date-of-birth field of a PUBLIC
  // (adults-only) instance's template, resolved STRICTLY -- never "the first
  // date field", which would age-gate on e.g. a visit date listed before the
  // real DOB field:
  //   - exactly ONE type=date field keyed as a DOB (DOB_FIELD_KEYS) -> it;
  //   - no such field, and exactly ONE type=date field in total -> it (there
  //     is no other date it could be confused with);
  //   - no type=date field at all -> error 'template_missing_dob';
  //   - otherwise (several date fields and none keyed as the DOB, or several
  //     keyed as the DOB) -> error 'template_ambiguous_dob'.
  // The single definition shared by createPublicInstance() (which refuses to
  // mint on an error) and submitGuestForm() (which fails closed as
  // 'age_unverifiable' on one), so the two can never disagree.
  // @return array{field:?array, error:?string, detail:?string}
  private function resolvePublicDobField(array $fields): array {
    $dates = array_values(array_filter($fields, static fn(array $f): bool => $f['type'] === 'date'));
    $marked = array_values(array_filter($dates, static fn(array $f): bool => in_array(str_replace('-', '_', strtolower($f['key'])), self::DOB_FIELD_KEYS, true)));
    if (count($marked) === 1) return ['field'=>$marked[0], 'error'=>null, 'detail'=>null];
    if (count($marked) === 0 && count($dates) === 1) return ['field'=>$dates[0], 'error'=>null, 'detail'=>null];
    if (count($dates) === 0) {
      return ['field'=>null, 'error'=>'template_missing_dob', 'detail'=>'the published template version has no date-of-birth (type=date) field; public instances are adults-only and must be age-gated'];
    }
    return ['field'=>null, 'error'=>'template_ambiguous_dob', 'detail'=>'the published template version has several type=date fields and not exactly one of them is keyed as the date of birth ('.implode(', ', self::DOB_FIELD_KEYS).'); public instances are adults-only and must age-gate on an unambiguous date-of-birth field'];
  }

  private function evaluateAgeGate(array $fields, array $post, ?array $dobField): array {
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
    // [GVS-89 / orchestrator decision] db_now_utc rides along (same as
    // renderGuestForm's own SELECT) so the submit-time expiry+grace gate
    // below compares against the DB clock, not this process's clock.
    $q=$this->db->pdo()->prepare('SELECT wi.*, wtv.id as version_id, wtv.version as form_version, wtv.title, wtv.fields_json, wtv.content_html, wtv.print_css, UTC_TIMESTAMP() AS db_now_utc FROM waiver_instances wi JOIN waiver_template_versions wtv ON wi.template_version_id=wtv.id WHERE link_token=? LIMIT 1');
    $q->execute([$token]); $instance=$q->fetch(); if(!$instance) return ['error'=>'Invalid link'];
    if($instance['status']==='completed') return ['error'=>'Already completed'];
    // [FK-void] Reject a voided instance up front with its own message
    // (distinct from "Already completed") -- also belt-and-suspenders with
    // the atomic claim below, which already refuses to flip a non-'pending'
    // row to 'completed' regardless of this early check.
    if($instance['status']==='void') return ['error'=>'This waiver link is no longer valid.'];
    // [GVS-89 / orchestrator decision, 2026-09-19] The render gate
    // (renderGuestForm/isExpiredPublicInstance) only ever stopped a GET from
    // showing the form -- a crafted POST straight to this endpoint with an
    // expired public token still completed the waiver (flagged as an open
    // gap by the 89-M4.1/M4.2 report). Refuse a submit whose PUBLIC instance
    // is older than expires_at + a 60-minute grace, with the SAME 410
    // semantics/message as the render gate, BEFORE any age-gate/claim/file
    // I/O -- a completed/void instance above still gets ITS OWN message
    // (checked first), never masked by this. Within the grace window a
    // person who opened the form before expiry can still submit -- see
    // isSubmitExpiredPublicInstance()'s doc comment for why this is a
    // SEPARATE method from the render gate rather than a parameterized one.
    // [gate 89-M4 P2-3] Same result shape as the render gate (incl.
    // error_title + locale), so w.php shows the identical localized 410 page
    // for a late POST as for a late GET.
    if ($this->isSubmitExpiredPublicInstance($instance)) {
      return self::publicExpiredResult(isset($instance['locale']) ? (string)$instance['locale'] : null);
    }
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
    // [gate 89-M4 r2 P1] WHICH field is the date of birth: on a PUBLIC
    // instance it is resolved strictly (resolvePublicDobField -- an
    // explicitly keyed DOB field, or the template's ONLY date field; a missing
    // or ambiguous one yields null, which fails closed as 'age_unverifiable'
    // below). A reservation-bound instance keeps its unchanged rule (the
    // first type=date field -- see firstDateField()).
    $isPublicInstance = !empty($instance['is_public']);
    $dobField = $isPublicInstance ? $this->resolvePublicDobField($fields)['field'] : $this->firstDateField($fields);
    $ageGate = $this->evaluateAgeGate($fields, $post, $dobField);
    // [GVS-89 / 89-M4.3 / AC4] Adults-only on a PUBLIC (reception-QR)
    // instance (operator decision Q12, reconfirmed 2026-09-19): the
    // reception-QR flow has no guardian workflow -- there is no staff member
    // physically adding a minor participant here, just a walk-in scanning a
    // standing QR alone -- so a signer under 18 is NEVER signable on a public
    // instance, not even the case evaluateAgeGate() would otherwise ACCEPT
    // (age 7-17 with a filled parental-consent field). Uniformly relabel
    // every "signer is under 18" outcome -- the <7 hard reject, the
    // missing-parental-consent reject, and the accept-with-consent case -- as
    // 'minor_requires_staff' and stop here, BEFORE any claim/notify (no
    // waiver_responses row, no webhook). Genuine input-format errors (missing
    // DOB, unparsable date, future date) are left untouched so a guest
    // fixing a typo still gets useful feedback.
    //
    // [gate 89-M4 P1] FAIL CLOSED when the age cannot be computed at all:
    // evaluateAgeGate() passes with computed_age=null when there is no DOB
    // field to gate on (none, or -- r2 -- no unambiguous one). On a public
    // instance that would silently disable adults-only (and yield a
    // completion BookingV2's public ingest rejects, since it requires an
    // integer computed_age), so refuse it as 'age_unverifiable' -- same
    // no-claim/no-notify position as the minor refusal. create_public_instance
    // already refuses such templates; this covers a template edited after the
    // instance was minted.
    if ($isPublicInstance) {
      if (!$ageGate['ok'] && in_array($ageGate['error'], ['age_below_minimum', 'minor_parental_consent_missing'], true)) {
        $ageGate = ['ok'=>false, 'error'=>'minor_requires_staff'];
      } elseif ($ageGate['ok'] && $ageGate['computed_age'] === null) {
        $ageGate = ['ok'=>false, 'error'=>'age_unverifiable'];
      } elseif ($ageGate['ok'] && $ageGate['computed_age'] < 18) {
        $ageGate = ['ok'=>false, 'error'=>'minor_requires_staff'];
      }
    }
    if (!$ageGate['ok']) {
      $this->audit('instance', (int)$instance['id'], 'age_gate_rejected', ['reason'=>$ageGate['error']]);
      if ($ageGate['error'] === 'minor_requires_staff') {
        // Localized, guest-facing copy (not the bare error code): w.php's
        // existing fallback re-renders the form with this text in the
        // generic error banner -- the SAME path every other validation
        // rejection here already takes -- so no template change is needed
        // there. See publicMinorStaffCopy()'s doc comment.
        $copy = self::publicMinorStaffCopy(isset($instance['locale']) ? (string)$instance['locale'] : null);
        return ['error'=>$copy['message'], 'error_code'=>'minor_requires_staff'];
      }
      if ($ageGate['error'] === 'age_unverifiable') {
        $copy = self::publicAgeUnverifiableCopy(isset($instance['locale']) ? (string)$instance['locale'] : null);
        return ['error'=>$copy['message'], 'error_code'=>'age_unverifiable'];
      }
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

    // [GVS-89 / gate 89-M4 r2 P1] SERIALIZED AGAINST GDPR ERASURE, exactly
    // like resendEvidence(): from here on this request pushes the signed
    // evidence OUT of this system (uploadEvidence -> BookingV2's blob store)
    // and then records it (the waiver_responses INSERT and its pointers), and
    // there is no foreign key from waiver_responses to waiver_instances. An
    // eraseWaiver() interleaving with that sequence would leave a blob at
    // BookingV2 with no pointer anywhere and/or a waiver_responses row (full
    // PII) for an instance that no longer exists -- unreachable by any later
    // erasure. So the rest of the submission runs under the SAME per-instance
    // evidence lock, held until it returns (incl. the completion webhook, so
    // once an erase answers ok nothing about the instance is still in flight
    // to BookingV2).
    //
    // Taken AFTER the claim, so a double-submit still loses fast on the claim
    // and never waits here. A SHORT wait (SUBMIT_EVIDENCE_LOCK_WAIT_SECONDS),
    // not resend's zero: the usual holder is an erase transaction or a resend
    // that finds nothing to push (both milliseconds), and waiting them out
    // lets the re-read below see the authoritative state -- erased: stop,
    // nothing uploaded or written; still ours: proceed fully serialized. It is
    // bounded because a guest's POST must not hang behind an erase that is
    // itself waiting on OTHER instances' locks. If the lock still cannot be
    // taken the waiver is COMPLETED anyway (the signature is never lost) but
    // WITHOUT the upload: the evidence is retained locally with its pointers
    // (the same state as a relay outage) for resend_evidence / reconcile to
    // push later under the lock.
    $instanceId=(int)$instance['id'];
    try {
      $evidenceLocked=$this->acquireEvidenceLock($instanceId, $this->evidenceLockWaitSeconds('submit_wait_seconds', self::SUBMIT_EVIDENCE_LOCK_WAIT_SECONDS));
    } catch (\Throwable $lockEx) {
      // The claim above is already committed: a throw escaping here would
      // strand the instance 'completed' with no response. Treat it as "not
      // acquired"; if the DB is really gone, the persist step below fails
      // into its catch, which reverts the claim and logs.
      $evidenceLocked=false;
    }
    try {
      return $this->persistClaimedSubmission($instance, $post, $answers, $ageGate, $png, $evidenceLocked);
    } finally {
      if ($evidenceLocked) $this->releaseEvidenceLock($instanceId);
    }
  }

  // [gate 89-M4 r3, TEST SEAM] No-op in production. Called once by
  // persistClaimedSubmission() right after its guarded commit, before the
  // completion webhook / audit that follow on the lock-busy fallback. Exists
  // SOLELY so tests/WaiverControllerPublicTest.php can deterministically run
  // a bypass-the-lock deletion (via an anonymous subclass override) at that
  // exact point, instead of racing real wall-clock timing against a second
  // process. WaiverController is intentionally not `final` for this reason;
  // no production code may override it.
  protected function afterSubmitCommitForTesting(int $instanceId): void {}

  // submitGuestForm()'s post-claim half: render + upload the evidence, persist
  // the response, notify BookingV2. Runs with the instance's evidence lock
  // held when $evidenceLocked (see the caller); without it the upload is
  // skipped and the evidence is left to resend_evidence.
  private function persistClaimedSubmission(array $instance, array $post, array $answers, array $ageGate, string $png, bool $evidenceLocked): array {
    $pdo=$this->db->pdo();
    $sigDir=$this->cfg['storage']['signatures_path']; if(!is_dir($sigDir)) @mkdir($sigDir,0775,true);
    $sigFile=$sigDir.'/'.Utils::randomToken(16).'.png'; $artifact=null;
    try {
      // [gate 89-M4 r2 P1] Re-read after the lock attempt: an erase that ran
      // between the claim and here has deleted the row. Nothing has been
      // written or uploaded yet -- stop, exactly as for an unknown token.
      $alive=$pdo->prepare("SELECT 1 FROM waiver_instances WHERE id=? AND status='completed'");
      $alive->execute([(int)$instance['id']]);
      if(!$alive->fetchColumn()) return ['error'=>'Invalid link'];

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
      // [gate 89-M4 r2 P1] ONLY under the evidence lock: without it an erase
      // could be deleting this instance right now, and a blob pushed now
      // would outlive it. Skipped otherwise -- the evidence is then retained
      // locally below (pointers persisted) for resend_evidence to push later.
      $evidence = $evidenceLocked
        ? $this->uploadEvidence($instance, $artifact, $sigFile)
        : ['evidence_sha256'=>null, 'evidence_object_key'=>null, 'evidence_blob_key'=>null, 'evidence_blob_url'=>null];
      // [FK-evidence-keep] One full retry of the relay before giving up: a
      // transient failure (BookingV2 cold start, brief network blip) that
      // outlives postSignedEnvelopeWithResponse's inline 3-attempt budget
      // often clears within a second. Retrying HERE -- before the
      // waiver_responses insert and the completion webhook below -- means a
      // successful retry still hands its blob key to
      // notifyBookingV2Completion, instead of the webhook firing without
      // one. When the relay is unconfigured or the artifact is unreadable
      // the retry is a near-instant no-op (uploadEvidence short-circuits),
      // so this adds latency only in the genuinely-degraded case.
      if ($evidenceLocked && $evidence['evidence_object_key'] === null) {
        $evidence = $this->uploadEvidence($instance, $artifact, $sigFile);
      }

      // [FK-erase / FK-evidence-keep] pdf_path/signature_path: NULL on the
      // happy path (relay confirmed durable storage -- the local files are
      // transient and removed below; the durable copy is BookingV2's object
      // store, addressed by evidence_object_key on the completion webhook).
      // But when the relay did NOT confirm (evidence_object_key null), the
      // files retained below are the ONLY copy of the signed evidence, and
      // these columns are the ONLY pointer GDPR erasure follows: eraseWaiver
      // unlinks exactly the paths read from pdf_path/signature_path, and the
      // evidence_retained_locally audit row written below is itself purged by
      // the same erasure's audit_events DELETE. Leaving the columns NULL in
      // the retained case would let a subject's signed PDF (name, DOB,
      // medical answers, signature) survive erasure orphaned on disk while
      // the erase reports success. A stale path after manual backfill/removal
      // is harmless: erase's unlink is is_file()-guarded.
      $retained = $evidence['evidence_object_key'] === null;
      // [gate 89-M4 r3 P2-2 fix] Precompute the retained-evidence audit
      // metadata now (it needs only $artifact/$sigFile/$evidenceLocked,
      // already known) so the INSERT below can happen INSIDE the guarded
      // transaction -- see the comment at that INSERT for why.
      $retainedMeta = null;
      if ($retained) {
        $retainedMeta = ['pdf_path'=>$artifact, 'signature_path'=>$sigFile];
        if (!$evidenceLocked) $retainedMeta['deferred'] = 'evidence_lock_busy';
      }
      // [T5 / migrations/005_evidence_fields.sql] Persist the evidence
      // identifiers uploadEvidence() returned -- previously computed/received
      // and then dropped (the "KEY DISCOVERY": the fork never persisted
      // these). Written unconditionally (all four null when the relay never
      // confirmed, matching $none) so a single INSERT covers both outcomes;
      // get_status()/statusRow() read them straight back via STATUS_SELECT.
      // [orphan-response-fix 2026-08-30] Persist the completion ATOMICALLY: the
      // waiver_responses INSERT and its required 'submitted' audit row commit
      // together or not at all. waiver_responses.waiver_instance_id is UNIQUE
      // (migrations/001_init.sql), so before this a row that committed here while
      // a FOLLOWING statement threw (the audit insert -- or anything after the
      // committed INSERT) survived the catch's status-revert as an ORPHAN. And
      // because the instance was reset to 'pending', every retry then re-claimed
      // it and died FOREVER on the duplicate-key INSERT: a permanent per-instance
      // "could not save" outage, distinct from the migration-drift outage of the
      // same day. Wrapping the pair in one transaction means a post-INSERT throw
      // rolls the row back, so the catch below reverts to a genuinely clean
      // 'pending' the guest can retry into. audit() writes through
      // Database::pdo() -- the SAME singleton connection as $pdo -- so its insert
      // genuinely joins this transaction (not a second, autocommitting one).
      //
      // SCOPE: ONLY these two fast DML statements are transactional. The early
      // atomic status-claim above stays committed AHEAD of this on purpose -- it
      // is the concurrency guard that must fail-fast BEFORE the expensive PDF
      // render and evidence upload, so a losing double-submit never does that
      // work; the outer catch's compensating UPDATE is what reverts it. And the
      // slow network side-effects (uploadEvidence above, notifyBookingV2Completion
      // below) stay OUTSIDE the transaction, so no DB transaction is ever held
      // open across HTTP I/O and the completion webhook still fires strictly
      // AFTER a durable commit.
      $pdo->beginTransaction();
      $vanished = false;
      try {
        $stmt=$pdo->prepare('INSERT INTO waiver_responses (waiver_instance_id, answers_json, signature_png, signer_full_name, signed_at, signer_ip, signer_user_agent, hash_sha256, pdf_path, signature_path, evidence_sha256, evidence_object_key, evidence_blob_key, evidence_blob_url, created_at) VALUES (?,?,?,?,UTC_TIMESTAMP(),?,?,?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        $stmt->execute([$instance['id'], json_encode($answers, JSON_UNESCAPED_UNICODE), $png, $post['full_name']??null, $_SERVER['REMOTE_ADDR']??null, $_SERVER['HTTP_USER_AGENT']??null, $hash, $retained ? $artifact : null, $retained ? $sigFile : null, $evidence['evidence_sha256'], $evidence['evidence_object_key'], $evidence['evidence_blob_key'], $evidence['evidence_blob_url']]);
        $this->audit('response', $instance['id'], 'submitted', $payload);
        // [gate 89-M4 r3 P2-2 fix / Codex r3 P1-1] Write the retained-evidence
        // bookkeeping audit row HERE, inside the SAME guarded transaction as
        // the response row, instead of afterwards (unlocked, past the commit
        // point) as before. Rationale: this row is only ever written on the
        // lock-busy fallback (no upload attempted), which is exactly the one
        // case where nothing serializes this request against a concurrent
        // erase. Writing it post-commit meant it could still be INSERTed
        // after an erase had already deleted this instance's audit trail --
        // a fresh, un-erasable orphan row (paths only, no PII, but an
        // erasure-completeness gap). Putting it here ties its fate to the
        // SAME $still check and rollback as the response row below: if the
        // instance vanished, this row is rolled back with everything else and
        // never exists to begin with.
        if ($retainedMeta !== null) {
          $this->audit('instance', (int)$instance['id'], 'evidence_retained_locally', $retainedMeta);
        }
        // [gate 89-M4 r2 P1] Commit ONLY if the instance is still ours, and
        // pin it (row lock) until the commit. There is no FK from
        // waiver_responses to waiver_instances, so without this a deletion
        // that did not take the evidence lock (the lock-busy path above, or
        // manual SQL) would leave this PII-bearing row and its audit event
        // orphaned, unreachable by any erasure. Checked AFTER the inserts, in
        // eraseWaiver's own lock order (responses -> audit -> instance), so a
        // concurrent erase serializes with this transaction instead of
        // deadlocking against it.
        $still=$pdo->prepare("SELECT 1 FROM waiver_instances WHERE id=? AND status='completed' FOR UPDATE");
        $still->execute([$instance['id']]);
        if ($still->fetchColumn()) {
          $pdo->commit();
        } else {
          $pdo->rollBack();
          $vanished = true;
        }
      } catch (\Throwable $txEx) {
        // Roll the INSERT back (mirrors eraseWaiver's transaction pattern) so no
        // orphan row survives, then re-throw into the outer catch, which reverts
        // the instance status to 'pending' and cleans up the local files.
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $txEx;
      }
      if ($vanished) {
        // Erased mid-submit: nothing of this submission may survive it --
        // no row (rolled back above), no local files, no webhook, no audit
        // (it would be an orphan). Same answer as for an unknown token.
        error_log('[WAIVER-SUBMIT-ERASED] waiver_instance_id='.(int)$instance['id'].' vanished before its response committed (erased mid-submit); nothing persisted');
        // [gate 89-M4 r3 Codex P1-2 fix] If the upload ALREADY succeeded
        // (evidence_object_key non-null) before this rollback, BookingV2's
        // evidence relay is now holding a real blob with NO fork-side pointer
        // to it (the row that would have carried evidence_object_key was just
        // rolled back). This fork has no credentials or endpoint to delete a
        // BookingV2 blob -- it cannot compensate the upload -- so the two
        // things it CAN do are: never claim the upload succeeded in any
        // return value or persisted row (it does not, above), and leave an
        // opaque, non-PII trace of the orphan so it is not silently
        // unaccounted for. The blob itself is NOT permanently unreachable:
        // BookingV2's erasure sweep deletes the WHOLE public/<signupId>/ (or
        // equivalent) object-store PREFIX for this instance independent of
        // any pointer this fork ever recorded (89-M3.1,
        // waiver-erasure-worker.ts's listPublicSignupEvidence), so it is
        // reclaimed once BookingV2 next erases/sweeps this subject -- this
        // log line is only the fork-side breadcrumb until then.
        if ($evidence['evidence_object_key'] !== null) {
          error_log('[WAIVER-EVIDENCE-ORPHANED] waiver_instance_id='.(int)$instance['id']
            .' evidence_object_key='.$evidence['evidence_object_key']
            .' uploaded to BookingV2 before the instance vanished mid-submit; no fork-side'
            .' pointer was persisted (rolled back) -- relies on BookingV2\'s erasure/sweep'
            .' of this subject\'s object-store prefix (89-M3.1) to reclaim it');
        }
        if(is_file($sigFile)) @unlink($sigFile);
        if($artifact && is_file($artifact)) @unlink($artifact);
        return ['error'=>'Invalid link'];
      }

      // [gate 89-M4 r3, TEST SEAM] No-op in production; exists ONLY so a test
      // can deterministically land a concurrent mutation (e.g. an erase that
      // bypasses the evidence lock) in the otherwise-timing-dependent gap
      // between the guarded commit above and the webhook/audit below, without
      // a flaky real-clock race. Never overridden outside tests.
      $this->afterSubmitCommitForTesting((int)$instance['id']);

      // [FK-T8] Fire the outbound completion webhook to BookingV2 ONLY here --
      // after the completed-status claim above succeeded AND the
      // waiver_responses row + audit are durably committed. This method
      // swallows ALL of its own errors (never throws into the catch below,
      // which would wrongly revert a real, completed submission back to
      // "pending"). A failed delivery is logged (webhook_failed audit row)
      // and left to the reconciliation sweep (spec G1c) -- never retried by
      // reverting the instance.
      //
      // [gate 89-M4 r3 P2-2 fix / Codex r3 P1-1] When this submission held the
      // evidence lock all the way through the commit above ($evidenceLocked),
      // no erase of THIS instance could have started in the meantime (erase
      // needs the same lock BEFORE it opens its own transaction) -- send
      // unconditionally, exactly as before. On the LOCK-BUSY fallback
      // ($evidenceLocked === false) nothing has serialized this request
      // against a concurrent erase since the claim, so re-check existence
      // IMMEDIATELY before sending: an erase that bypassed the busy lock (or
      // ran once the original holder released it) between the commit above
      // and here must not be followed by a completion webhook carrying full
      // PII (name, DOB, answers) for an instance whose erasure has already
      // been reported done -- and reconcile/get_status (spec G1c) already
      // recovers a genuine completion the erase merely raced past.
      if ($evidenceLocked) {
        $this->notifyBookingV2Completion($instance, $ageGate, $answers, $post['full_name']??null, $evidence['evidence_sha256'], $evidence['evidence_object_key'], $hash);
      } else {
        $stillForWebhook = $pdo->prepare("SELECT 1 FROM waiver_instances WHERE id=? AND status='completed'");
        $stillForWebhook->execute([$instance['id']]);
        if ($stillForWebhook->fetchColumn()) {
          $this->notifyBookingV2Completion($instance, $ageGate, $answers, $post['full_name']??null, $evidence['evidence_sha256'], $evidence['evidence_object_key'], $hash);
        } else {
          error_log('[WAIVER-WEBHOOK-SKIPPED-ERASED] waiver_instance_id='.(int)$instance['id'].' erased between the guarded commit and the deferred completion webhook; not notifying BookingV2 (reconcile/get_status covers a genuine completion)');
        }
      }
    } catch (\Throwable $e) {
      // [post-incident 2026-08-30] Make this failure VISIBLE and traceable
      // without ever leaking guest PII. The 16h outage was a swallowed DB error
      // (a save against a schema missing migration 005's columns) that this
      // broad catch turned into a generic HTTP-200 banner with NOTHING logged.
      //
      // NO-THROW ORDER (Finding #9): the guest MUST always get the ref banner
      // and the log line MUST always be written, even if the rollback UPDATE
      // below throws (e.g. the DB itself is down). So: (1) mint the ref with a
      // guarded fallback, (2) error_log FIRST, (3) rollback + file cleanup in
      // an independent best-effort try/finally, (4) ALWAYS return the
      // ref-bearing response.
      //
      // PII whitelist (Grok #1, supersedes the earlier "raw message for
      // PDOException"): log ONLY non-PII structured fields -- ref,
      // waiver_instance id, exception CLASS, SQLSTATE, numeric driver code,
      // file:line. NEVER $e->getMessage() and NEVER the PDO driver message
      // (errorInfo[2]): a PDO message embeds the offending VALUE, e.g.
      // "Duplicate entry 'jane@example.com' for key ..." or bytes of an
      // over-long full_name in a truncation error = guest PII. No answers_json,
      // no signature bytes, no name/DOB/email, no stack trace.
      try {
        $ref = bin2hex(random_bytes(4));
      } catch (\Throwable $reEx) {
        // random_bytes() can throw if the CSPRNG is unavailable; a non-crypto
        // ref still gives staff a correlation handle (uniqueness best-effort).
        $ref = substr(str_pad(dechex(mt_rand() ^ (int)(microtime(true) * 1000)), 8, '0', STR_PAD_LEFT), 0, 8);
      }
      $sqlstate = '';
      $driverCode = '';
      if ($e instanceof \PDOException) {
        $info = is_array($e->errorInfo ?? null) ? $e->errorInfo : [];
        // errorInfo[0] = SQLSTATE (e.g. '42S22'); errorInfo[1] = numeric driver
        // code (e.g. 1054). NEITHER carries row values. errorInfo[2] (the driver
        // message) is deliberately NOT read -- it embeds the offending value.
        $sqlstate = (string)($info[0] ?? $e->getCode());
        $driverCode = (string)($info[1] ?? '');
      }
      error_log('[WAIVER-SAVE-ERROR] ref='.$ref
        .' waiver_instance_id='.(int)$instance['id']
        .' exception='.get_class($e)
        .' sqlstate='.$sqlstate
        .' driver_code='.$driverCode
        .' at='.$e->getFile().':'.$e->getLine());
      // Roll the claim back so the guest can retry; remove any orphaned files.
      // Best-effort and INDEPENDENT: a throw here (DB down) must NOT prevent the
      // ref response below -- the log line above is already written.
      try {
        $pdo->prepare('UPDATE waiver_instances SET status="pending", completed_at=NULL, updated_at=UTC_TIMESTAMP() WHERE id=? AND status="completed"')->execute([$instance['id']]);
      } catch (\Throwable $rbEx) {
        // Swallow: the DB may be the very thing that's broken; the failure is
        // already logged above, and the file cleanup below still runs.
      } finally {
        // [Grok New #4] Guard $sigFile the same way as $artifact: is_file(null)
        // is a PHP 8.1 deprecation. $sigFile is assigned before the try so it is
        // a string here today, but the null-safe guard makes the cleanup robust
        // to any future reorder and matches the $artifact pattern below.
        if($sigFile && is_file($sigFile)) @unlink($sigFile);
        if($artifact && is_file($artifact)) @unlink($artifact);
      }
      // ONE generic message for ALL failure causes (never branch on exception
      // type, never leak internals/PII), carrying the correlation ref logged
      // above. The ref is inline in the string so w.php renders it verbatim
      // (public/w.php htmlspecialchars($error)); it is ALSO exposed as its own
      // payload key for programmatic/test use. 'http_status'=>500 (Finding #10)
      // marks this as an INTERNAL persistence failure so public/w.php sets a
      // 5xx (visible to uptime monitoring); user VALIDATION errors -- all
      // returned BEFORE the try above -- omit http_status and keep the 200.
      return [
        'error'=>'We couldn\'t save your waiver right now (ref: '.$ref.'). Please try again. If it keeps happening, show this code to a staff member.',
        'ref'=>$ref,
        'http_status'=>500,
      ];
    }

    // [FK-T15 / FK-evidence-keep] Stop long-term local-FS persistence: evidence
    // was generated transiently to produce the bytes uploaded above -- remove
    // both files ONLY once the relay has CONFIRMED durable storage (2xx AND a
    // usable blob key, i.e. evidence_object_key non-null). When the relay
    // failed, returned no blob key, or was never configured
    // (CALLBACK_BASE_URL unset), the local PDF is the ONLY copy of a signed
    // legal document -- deleting it here would destroy the evidence with
    // nothing durable to point at. Keep both files for reconciliation/manual
    // re-upload and log a loud marker so operators can find them. In this
    // retained case the row inserted above carries the file paths in
    // pdf_path/signature_path, so GDPR erasure (eraseWaiver's column-driven
    // unlink) still reaches the files. (The signature PNG bytes also live in
    // waiver_responses.signature_png, but the rendered PDF exists nowhere
    // else.) Successful-path unlinks stay
    // best-effort: a stray leftover file is not itself a correctness problem
    // (nothing references it), just housekeeping.
    if ($evidence['evidence_object_key'] !== null) {
      if(is_file($sigFile)) @unlink($sigFile);
      if($artifact && is_file($artifact)) @unlink($artifact);
    } else {
      error_log('[WAIVER-EVIDENCE-RETAINED] evidence relay did not confirm durable storage for waiver_instance_id='.(int)$instance['id']
        .' -- keeping local files: pdf='.($artifact !== null ? $artifact : '(none)')
        .' signature='.$sigFile
        .' (back-fill via reconciliation/manual re-upload, then remove)');
      // [gate 89-M4 r3 P2-2 fix] The 'evidence_retained_locally' audit row
      // itself was ALREADY written above, inside the guarded transaction
      // alongside the response row (see the comment there) -- not here.
      // Writing it again here would double it, and doing it here at all was
      // the P2-2 gap: unlocked, well after the commit, it could still land
      // after a concurrent erase had already purged this instance's audit
      // trail, leaving an orphan. This branch now only logs (non-PII,
      // container-log-only bookkeeping for the retained files).
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
  //
  // [T5 / migrations/005_evidence_fields.sql] Return shape widened from
  // {evidence_sha256, evidence_object_key} to also carry evidence_blob_key
  // (same value as evidence_object_key -- see the migration's doc comment
  // for why both exist) and evidence_blob_url (the relay's top-level
  // `blob_url`, previously computed by BookingV2 but never read back here).
  // submitGuestForm persists all four on the waiver_responses row once this
  // returns a non-null evidence_object_key.
  private function uploadEvidence(array $instance, ?string $artifactPath, ?string $sigFile): array {
    $none = ['evidence_sha256'=>null, 'evidence_object_key'=>null, 'evidence_blob_key'=>null, 'evidence_blob_url'=>null];
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

      $url = self::evidenceUrlFor($cb);

      $result = $this->postSignedEnvelopeWithResponse($url, $rawBody, (string)$cb['outbound_key_id'], (string)$cb['outbound_secret']);
      if (!$result['ok']) {
        $this->audit('instance', (int)$instance['id'], 'evidence_upload_failed', ['status'=>$result['status']]);
        // Evidence can be back-filled later (reconciliation/manual re-upload)
        // -- still report the locally-computed hash so the completion
        // webhook at least carries evidence_sha256 even without a blob key.
        return ['evidence_sha256'=>$evidenceSha256, 'evidence_object_key'=>null, 'evidence_blob_key'=>null, 'evidence_blob_url'=>null];
      }

      $decoded = json_decode((string)$result['body'], true);
      $blobKey = (is_array($decoded) && isset($decoded['blob_key']) && is_scalar($decoded['blob_key'])) ? (string)$decoded['blob_key'] : null;
      if ($blobKey === null) {
        // 2xx but no usable blob_key in the body -- treat as a failed upload
        // for wiring purposes (nothing to reference), but keep the hash.
        $this->audit('instance', (int)$instance['id'], 'evidence_upload_failed', ['reason'=>'missing_blob_key']);
        return ['evidence_sha256'=>$evidenceSha256, 'evidence_object_key'=>null, 'evidence_blob_key'=>null, 'evidence_blob_url'=>null];
      }
      // [T5] the relay's top-level blob_url, alongside blob_key -- see the
      // route's doc comment ("Response carries BOTH shapes"). A scalar check
      // mirrors blob_key's own defensive parse; a missing/non-scalar
      // blob_url is left null rather than failing the whole upload (the key
      // alone is still a fully usable, durable reference).
      $blobUrl = (is_array($decoded) && isset($decoded['blob_url']) && is_scalar($decoded['blob_url'])) ? (string)$decoded['blob_url'] : null;

      return ['evidence_sha256'=>$evidenceSha256, 'evidence_object_key'=>$blobKey, 'evidence_blob_key'=>$blobKey, 'evidence_blob_url'=>$blobUrl];
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

  // [T5-evidence-verify] Derive the evidence-relay POST URL from the
  // callback config: an explicit callback.evidence_url wins when set, else
  // derive "<callback.base_url>/api/waiver/evidence" -- the SAME origin the
  // completion webhook posts to (notifyBookingV2Completion, below). Pulled
  // out of uploadEvidence() into its own named, directly-testable unit so
  // this can be phpunit-PINNED (tests/WaiverControllerEvidenceTest.php): the
  // T5 plan's "evidence-POST origin verify" line found the evidence POST
  // ALREADY targets callback.base_url (CALLBACK_BASE_URL) -- this is a
  // verification + regression pin, not a behavior change. `public` (not
  // `private`) specifically so the pin can call it directly without
  // reflection.
  public static function evidenceUrlFor(array $callbackConfig): string {
    return !empty($callbackConfig['evidence_url'])
      ? (string)$callbackConfig['evidence_url']
      : rtrim((string)($callbackConfig['base_url'] ?? ''), '/').'/api/waiver/evidence';
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
      // [GVS-89 §7.0] A PUBLIC (reception-QR) completion notifies a DISTINCT
      // BookingV2 route under a distinct event name, with signup_token
      // (≡ link_token on the wire) ADDED -- see the URL/body-completion
      // below. The four binding-id fields already come out null for a
      // public instance (its row never carries them -- createPublicInstance
      // inserts them NULL and they are never set afterwards), so nothing
      // needs to be REMOVED to satisfy BookingV2's
      // PublicCompletionFieldsSchema; only signup_token needs adding.
      $isPublic = !empty($instance['is_public']);

      $body = [
        'event' => $isPublic ? 'waiver.public_completed' : 'waiver.completed',
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
      // [GVS-89 §7.0] signup_token is the ONE field ADDED for a public
      // completion (equal to link_token on the wire -- BookingV2 resolves
      // the waiver_public_signups row by it). Added last so the base body
      // above stays byte-identical to the reservation-bound shape apart from
      // 'event' and this one key.
      if ($isPublic) {
        $body['signup_token'] = $linkToken;
        // [gate 89-M4 Grok P2 / Codex P2] The contract says a public
        // completion carries NO binding. The row never gets one by
        // construction (createPublicInstance inserts NULLs; link_waivers now
        // skips public instances), and -- like publicStatus() -- the wire
        // guarantee is enforced here too, so a hand-edited row can never make
        // BookingV2's public ingest (which rejects any binding) refuse this
        // completion.
        foreach (['reservation_id', 'booking_group_id', 'participant_id', 'customer_id'] as $bindingKey) {
          $body[$bindingKey] = null;
        }
      }
      // [Gap3] waiver_consent_granted is a Wave-2 field: the fork's current
      // form has no consent checkbox on most templates, so this key is
      // included ONLY when the guest actually ticked one (present === true).
      // Ingestion (BookingV2) treats an absent key as "no consent action" --
      // never send an explicit false, which would read as a revoke.
      if (($answers[self::CONSENT_ANSWER_KEY] ?? null) === true) {
        $body['waiver_consent_granted'] = true;
      }

      $rawBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      // [GVS-89 §7.0] A public completion posts to a DISTINCT route
      // (BookingV2's PublicCompletionFieldsSchema / applyPublicWaiverCompletion)
      // so the two ingestion paths can never be confused server-side even if
      // 'event' were somehow lost.
      $url = rtrim((string)$cb['base_url'], '/').($isPublic ? '/api/waiver/public-complete' : '/api/waiver/complete');

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
    // [GVS-89 / gate 89-M4 Grok+Codex P2] PUBLIC (reception-QR) instances are
    // NEVER eligible either: a public instance carries no reservation binding
    // by contract (its completion goes to BookingV2's public ingest, which
    // rejects any binding, and BookingV2 attaches it by email instead).
    // Silently skipped, exactly like a void instance. Filtered in PHP via
    // SELECT * (is_public read only if present) so this path never depends on
    // migrations/006_public_instances.sql having landed.
    if($groupToken){
      $sql='SELECT * FROM waiver_instances WHERE group_token=?'.$statusClause;
      $sel=$this->db->pdo()->prepare($sql); $sel->execute([$groupToken]); $rows=$sel->fetchAll();
    } else {
      $ids=array_values(array_filter(array_map('intval',$waiverIds))); if(!$ids) return ['error'=>'No valid waiver_ids'];
      $in=implode(',',array_fill(0,count($ids),'?')); $sql='SELECT * FROM waiver_instances WHERE id IN ('.$in.')'.$statusClause;
      $sel=$this->db->pdo()->prepare($sql); $sel->execute($ids); $rows=$sel->fetchAll();
    }
    $ids=array_column(array_values(array_filter($rows, static fn(array $r): bool => empty($r['is_public']))),'id');
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

    // [GVS-89 / gate 89-M4 P1] Serialize against resend_evidence (and, r2,
    // against submitGuestForm's first upload + record): take EVERY
    // matched instance's evidence lock (ascending id order, all BEFORE the
    // transaction opens, so a waiting erase holds no row locks a resend could
    // need -- no deadlock) and keep them until the erasure has committed.
    // Guarantees: (1) no resend/submit upload is in flight while we erase -- if one
    // is, we wait for it to finish recording (or give up, below), and then
    // erase what it left; (2) once this returns success, no resend or submit for these
    // instances can START (each re-reads under the same lock and finds no row).
    // That is what lets BookingV2 run "fork erase_waiver FIRST, then delete
    // the evidence blobs" with nothing able to re-create a blob afterwards.
    // If a resend holds a lock past ERASE_EVIDENCE_LOCK_WAIT_SECONDS, NOTHING
    // is deleted and {error:'evidence_busy'} (503) is returned: a transient
    // refusal the erasure worker retries, never a partial erasure.
    $locked = [];
    try {
      $lockWait = $this->evidenceLockWaitSeconds('erase_wait_seconds', self::ERASE_EVIDENCE_LOCK_WAIT_SECONDS);
      foreach ($instanceIds as $id) {
        if (!$this->acquireEvidenceLock($id, $lockWait)) {
          return ['error'=>'evidence_busy'];
        }
        $locked[] = $id;
      }
      return $this->eraseLockedInstances($pdo, $instanceIds);
    } finally {
      foreach ($locked as $id) $this->releaseEvidenceLock($id);
    }
  }

  // [gate 89-M4 r3, TEST SEAM] No-op in production. See the call site inside
  // eraseLockedInstances() for what it is for; WaiverController is
  // intentionally not `final` for this and afterSubmitCommitForTesting()
  // above. $paths is the FOR UPDATE read's own fetchAll() result (a list of
  // ['pdf_path'=>..., 'signature_path'=>...] rows) for this chunk. $pdo is
  // erase's OWN connection/open transaction -- passed through so a test can
  // deterministically inject a same-transaction state change between this
  // read and the DELETE below (proving the rowCount-mismatch guard fires)
  // without needing a genuinely separate, precisely-timed session.
  protected function afterErasePathsReadForTesting(array $instanceIds, array $paths, \PDO $pdo): void {}

  // eraseWaiver()'s delete phase, run only while every instance's evidence
  // lock is held (see there).
  private function eraseLockedInstances(\PDO $pdo, array $instanceIds): array {
    // [gate 89-M4 r3 P2-1 fix] Pin the isolation level before opening this
    // transaction. The "an unlocked writer either commits first (and its
    // files are seen by the FOR UPDATE read below) or waits for this erasure
    // to finish" guarantee this method's own comments rest on depends on
    // REPEATABLE READ's gap locking: under READ COMMITTED, a
    // `SELECT ... FOR UPDATE` over a waiver_instance_id with NO current
    // response row takes no gap lock, so a concurrent unlocked submit's
    // INSERT could land, commit, and then be removed by the DELETE below (a
    // current read) with its retained files never seen by the paths-read
    // above -- stranding them on disk while this erasure still reports
    // success. MySQL defaults to REPEATABLE READ, but nothing before this
    // pinned it, so a host configured with transaction_isolation=READ-
    // COMMITTED would silently lose the guarantee.
    //
    // `SET TRANSACTION ISOLATION LEVEL` with neither GLOBAL nor SESSION is
    // documented as a "next transaction only" pin, but verified empirically
    // against this fork's MySQL 8.0.46 (both via PDO and the mysql CLI,
    // several ways) NOT to take effect: `@@transaction_isolation` read
    // inside the very next START TRANSACTION/COMMIT still shows the prior
    // session default, every time. `SET SESSION TRANSACTION ISOLATION
    // LEVEL`, verified to work reliably the same way, is used instead. It
    // pins this CONNECTION for the rest of its life, not just this one
    // transaction -- harmless here: `Database::pdo()` is one short-lived,
    // non-persistent PDO connection per HTTP request (never pooled/reused
    // across requests), and this method's caller (eraseWaiver) is the last
    // and only transactional action such a request ever runs.
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
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
        // [gate 89-M4 r2] A LOCKING read (FOR UPDATE), not a snapshot: a
        // response row a writer that does not hold the evidence lock (a
        // submit on its lock-busy path) commits after this read would
        // otherwise still be removed by the DELETE below -- which reads
        // current data -- while its retained files, never seen here, stay
        // on disk unreferenced. Locking makes such a writer either commit
        // first (and be seen here) or wait for this erasure to finish.
        $pathsQ = $pdo->prepare('SELECT pdf_path, signature_path FROM waiver_responses WHERE waiver_instance_id IN ('.$in.') FOR UPDATE');
        $pathsQ->execute($chunk);
        $paths = $pathsQ->fetchAll();

        // [gate 89-M4 r3, TEST SEAM] No-op in production. Lets a test land a
        // concurrent, genuinely-separate-session write inside the exact
        // window this method's isolation-level pin (above) and rowCount
        // assertion (below) both exist to guard, deterministically instead
        // of racing real wall-clock timing. Never overridden outside tests.
        $this->afterErasePathsReadForTesting($chunk, $paths, $pdo);

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
        $chunkResponsesDeleted = $delResp->rowCount();
        $responsesDeleted += $chunkResponsesDeleted;
        // [gate 89-M4 r3 P2-1 fix] Assert the DELETE removed EXACTLY the rows
        // whose paths were just read (and, above, unlinked) -- not more, not
        // fewer. Within one transaction and one connection, a row this
        // erasure's own FOR UPDATE read already locked or gap-locked cannot
        // gain or lose siblings before this DELETE runs, so a mismatch means
        // the isolation-level guarantee this method's safety analysis rests
        // on did not hold here (e.g. a pooler/proxy silently overrode the
        // SET TRANSACTION ISOLATION LEVEL above, or a future refactor
        // reorders these two statements) -- exactly the scenario that can
        // strand a signed PDF on disk while this call still reports success.
        // Fail LOUD and abort the whole erasure (rolled back below) for the
        // caller to retry, rather than silently report success over a
        // possible stranded-file gap.
        if ($chunkResponsesDeleted !== count($paths)) {
          error_log('[WAIVER-ERASE-PATHS-MISMATCH] instance_ids='.implode(',', $chunk)
            .' paths_read='.count($paths).' responses_deleted='.$chunkResponsesDeleted
            .' -- aborting this erase for retry (see eraseLockedInstances doc comment)');
          throw new \RuntimeException('erase paths/rows mismatch: read '.count($paths)
            .' response path row(s) for waiver_instance_id IN ('.implode(',', $chunk).') but deleted '
            .$chunkResponsesDeleted.' -- see the preceding [WAIVER-ERASE-PATHS-MISMATCH] log line');
        }

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
      // Readability pass (2026-07-13): render choices as STACKED, full-width
      // rows with a real touch target (guests sign this on a phone at the
      // venue) instead of cramped inline `me-3` labels. Every element is a
      // <span> styled via CSS (.wv-* in w.php), never a <div>: a [field]
      // shortcode normally sits INSIDE a <p> in content_html, and a block
      // element there is invalid nesting -- the browser auto-closes the
      // paragraph and the layout breaks. Each input gets an id so the whole
      // label is tappable via `for`.
      if($type==='radio'){ $opts=isset($a['options'])?explode('|',$a['options']):['Yes','No']; $out='<span class="wv-choices">'; foreach($opts as $i=>$o){ $id='wv_'.preg_replace('/[^A-Za-z0-9_]/','',$key).'_'.$i; $out.='<span class="wv-choice"><input class="wv-control" type="radio" id="'.htmlspecialchars($id).'" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars($o).'" '.$req.'><label class="wv-choice-label" for="'.htmlspecialchars($id).'">'.htmlspecialchars($o).'</label></span>'; } return $out.'</span>'; }
      if($type==='textarea'){ return '<textarea name="'.htmlspecialchars($key).'" class="form-control d-inline-block" style="width:100%; min-height:80px; border:1px solid #ccc;"></textarea>'; }
      if($type==='date'){ return '<input type="date" name="'.htmlspecialchars($key).'" class="form-control d-inline-block" style="width:auto; min-width:180px; padding:2px 6px;" '.$req.'>'; }
      if($type==='parental_consent'){ return '<input name="'.htmlspecialchars($key).'" placeholder="Parent/guardian full name" class="form-control d-inline-block" style="width:auto; min-width:220px; padding:2px 6px; border:none; border-bottom:1px solid #000;" '.$req.'>'; }
      // [FK-Tconsent] Optional GDPR/marketing consent checkbox. ALWAYS
      // unrequired regardless of the placeholder's own `required` attribute
      // (never honor $req here) -- this checkbox must never block submission.
      // Same .wv-* span treatment as radio above (see that comment): the old
      // markup emitted a <div> from inside a <p>, which browsers auto-close --
      // that invalid nesting is why this checkbox rendered detached from its
      // question. The id stays `field_{key}` (unchanged contract).
      if($type===self::CONSENT_FIELD_TYPE){ return '<span class="wv-choices"><span class="wv-choice"><input class="wv-control" type="checkbox" name="'.htmlspecialchars($key).'" id="field_'.htmlspecialchars($key).'" value="1"><label class="wv-choice-label" for="field_'.htmlspecialchars($key).'">'.htmlspecialchars($a['label']??'I consent to be contacted for offers and promotions').'</label></span></span>'; }
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
