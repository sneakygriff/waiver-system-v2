<?php
declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * src/TemplateSeed.php — export ONE operator-authored waiver template out of a
 * source database, and seed it into a target database PRESERVING ITS ID.
 *
 * WHY THIS EXISTS [GVS-58 follow-up]
 * ----------------------------------
 * The STAGING fork database was re-provisioned empty on 2026-08-18: 0 templates,
 * 0 template versions, 0 admin users. BookingV2's `waiver_config.waiverTemplateId`
 * is the string "2" on BOTH staging and production and has never been edited
 * since the PR-B-core migration wrote it (createdAt == updatedAt to the ms), so
 * staging asks the fork for template 2 and the fork has nothing to answer with.
 *
 * THE ID IS THE WHOLE POINT. `waiver_templates.id` is BIGINT AUTO_INCREMENT
 * (migrations/001_init.sql). A naive re-import into an empty database lands at
 * id 1 while BookingV2 staging still points at "2" — leaving staging exactly as
 * broken, with a perfectly good template sitting right there. Every INSERT here
 * therefore carries an EXPLICIT id. (InnoDB advances the table's AUTO_INCREMENT
 * counter past an explicitly-inserted higher id on its own, so a later
 * admin-UI "Create" still gets a fresh, non-colliding id.)
 *
 * THE PUBLISH TRAP. WaiverController::hasPublishedVersion() answers
 * `{has_published_version:false}` with HTTP 200 for a template that does not
 * exist AND for one whose versions are all drafts — the query is
 * `SELECT 1 FROM waiver_template_versions WHERE template_id=? AND is_published=1`
 * (src/WaiverController.php). createWaiver() resolves the version with the same
 * `is_published=1` filter and returns `no_published_version` otherwise. So a
 * seeded template with only draft versions fixes NOTHING while looking fixed in
 * the admin UI. seed() therefore REFUSES a payload carrying no published version
 * (validate()), and PROVES the gate flipped by re-running that exact query after
 * the insert (see the 'verified_has_published_version' result key).
 * `published_at` is NOT read by either query — only `is_published` is — but we
 * carry it anyway so seeded rows are indistinguishable in shape from the ones
 * AdminController::publishVersion() writes, which always sets both.
 *
 * WHAT MOVES, AND WHAT NEVER DOES
 * -------------------------------
 * ONLY `waiver_templates` + `waiver_template_versions`: (id, name, is_active,
 * created_by, timestamps) and (version, title, description, fields_json,
 * requires_signature, created_by, created_at, content_html, print_css,
 * is_published, published_at). That is the operator's document and nothing else.
 * Signer data lives in `waiver_instances` / `waiver_responses` and NONE of it is
 * read, written, or referenced here. The payload is PII-free by construction.
 *
 * THREE INDEPENDENT LOCKS AGAINST TOUCHING PRODUCTION'S DOCUMENT
 * -------------------------------------------------------------
 * The production template is the legally-operative document the operator edits
 * by hand. No bootstrap may EVER overwrite or duplicate it. The arming env var
 * (dev/predeploy.php's SEED_WAIVER_TEMPLATE_FILE, never set on production) is
 * only the FIRST lock, and it is the weakest kind — a human promise. Two
 * mechanical locks stand behind it, and they are the reason a mis-copied
 * variable cannot become an incident:
 *
 *   Lock 2 — INSERT-ONLY-IF-ABSENT. If a row with the payload's id already
 *            exists, seed() returns RESULT_ALREADY_PRESENT and writes NOTHING.
 *            There is no UPDATE statement in this file, at all. On production
 *            template 2 exists, so the seed is a no-op there BY CONSTRUCTION,
 *            armed or not. This is also what makes the seed idempotent across
 *            every redeploy.
 *   Lock 3 — REFUSE A DATABASE THAT HOLDS SIGNED WAIVERS. A database with rows
 *            in `waiver_responses` is a database with real signatures in it; a
 *            bootstrap has no business writing there. Production always has
 *            them; a freshly re-provisioned staging has none. A partially-lost
 *            database (responses present, template gone) is a case for a human,
 *            not for an automatic seed, so refusing is the correct direction.
 *
 * Neither lock depends on knowing which host is which, so neither can be
 * defeated by a bad credential rotation — the failure mode that motivated the
 * URL-provenance pre-flight guarding the CI `dump`/`migrate` jobs
 * (scripts/preflight-db-host.php, .github/workflows/ci.yml).
 */
final class TemplateSeed
{
    /** Wrote the template + its versions. */
    public const RESULT_SEEDED = 'seeded';
    /** The id already exists in the target: nothing written (Lock 2). */
    public const RESULT_ALREADY_PRESENT = 'already_present';
    /** The target holds signed waivers: nothing written (Lock 3). */
    public const RESULT_REFUSED_SIGNED_DATA = 'refused_signed_data';

    /** Payload format version — bumped only on a breaking shape change. */
    public const PAYLOAD_VERSION = 1;

    /**
     * The COMPLETE `waiver_templates` column set (migrations/001_init.sql).
     * 002/003/005 never touch this table, so 001_init is authoritative.
     */
    public const TEMPLATE_COLUMNS = [
        'id', 'name', 'is_active', 'created_by', 'created_at', 'updated_at',
    ];

    /**
     * The COMPLETE `waiver_template_versions` column set MINUS `id` and
     * `template_id`. `id` is deliberately NOT carried: nothing outside this
     * table references a version id on a fresh target (staging has 0
     * waiver_instances, and `waiver_instances.template_version_id` is the only
     * referrer), so letting AUTO_INCREMENT assign it avoids a pointless
     * collision surface. The identity that MUST survive the copy is the UNIQUE
     * (template_id, version) pair, and both halves are preserved.
     * `template_id` is re-derived from the template row rather than trusted
     * per-version, so a hand-edited payload cannot scatter versions across
     * two templates.
     */
    public const VERSION_COLUMNS = [
        'version', 'title', 'description', 'fields_json', 'requires_signature',
        'created_by', 'created_at', 'content_html', 'print_css',
        'is_published', 'published_at',
    ];

    /**
     * Read ONE template and ALL of its versions out of $src. READ-ONLY: this
     * method issues SELECTs and nothing else, which is what makes it safe to
     * point at the production database.
     *
     * @throws RuntimeException if the template does not exist, has no versions,
     *         or has no PUBLISHED version (a payload that cannot fix anything is
     *         an operator error worth failing on, not a file worth writing).
     */
    public static function export(PDO $src, int $templateId): array
    {
        $t = $src->prepare(
            'SELECT ' . self::backtickList(self::TEMPLATE_COLUMNS)
            . ' FROM waiver_templates WHERE id = ?'
        );
        $t->execute([$templateId]);
        $template = $t->fetch(PDO::FETCH_ASSOC);
        if ($template === false) {
            throw new RuntimeException(
                'no waiver_templates row with id ' . $templateId . ' in the source database'
            );
        }

        $v = $src->prepare(
            'SELECT ' . self::backtickList(self::VERSION_COLUMNS)
            . ' FROM waiver_template_versions WHERE template_id = ? ORDER BY version ASC'
        );
        $v->execute([$templateId]);
        $versions = $v->fetchAll(PDO::FETCH_ASSOC);

        if ($versions === []) {
            throw new RuntimeException(
                'template ' . $templateId . ' has no waiver_template_versions rows'
            );
        }

        $published = array_filter($versions, static fn(array $r): bool => (int)$r['is_published'] === 1);
        if ($published === []) {
            throw new RuntimeException(
                'template ' . $templateId . ' has ' . count($versions) . ' version(s) but NONE with '
                . 'is_published=1 — seeding it would leave has_published_version false and fix nothing. '
                . 'Publish a version in the source admin UI first (AdminController::publishVersion).'
            );
        }

        return [
            'payload_version'    => self::PAYLOAD_VERSION,
            'exported_at'        => gmdate('Y-m-d\TH:i:s\Z'),
            'source_template_id' => (int)$template['id'],
            'template'           => $template,
            'versions'           => $versions,
        ];
    }

    /**
     * Structural + semantic validation of a payload before a single row is
     * written. Fails LOUD and specific: a bootstrap that half-applies a
     * malformed fixture is worse than one that refuses it.
     *
     * @throws InvalidArgumentException
     */
    public static function validate(array $payload): void
    {
        foreach (['payload_version', 'template', 'versions'] as $key) {
            if (!array_key_exists($key, $payload)) {
                throw new InvalidArgumentException('payload is missing the "' . $key . '" key');
            }
        }
        if ((int)$payload['payload_version'] !== self::PAYLOAD_VERSION) {
            throw new InvalidArgumentException(
                'payload_version is ' . var_export($payload['payload_version'], true)
                . ', this build understands ' . self::PAYLOAD_VERSION
            );
        }
        if (!is_array($payload['template'])) {
            throw new InvalidArgumentException('payload "template" is not an object');
        }
        if (!is_array($payload['versions']) || $payload['versions'] === []) {
            throw new InvalidArgumentException('payload "versions" is empty — nothing to publish');
        }

        foreach (self::TEMPLATE_COLUMNS as $column) {
            if (!array_key_exists($column, $payload['template'])) {
                throw new InvalidArgumentException('payload template is missing column "' . $column . '"');
            }
        }
        $id = $payload['template']['id'];
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            throw new InvalidArgumentException('payload template id must be a positive integer, got ' . var_export($id, true));
        }
        if ((int)$id < 1) {
            throw new InvalidArgumentException('payload template id must be >= 1, got ' . var_export($id, true));
        }

        $seenVersions = [];
        $anyPublished = false;
        foreach ($payload['versions'] as $index => $version) {
            if (!is_array($version)) {
                throw new InvalidArgumentException('payload versions[' . $index . '] is not an object');
            }
            foreach (self::VERSION_COLUMNS as $column) {
                if (!array_key_exists($column, $version)) {
                    throw new InvalidArgumentException(
                        'payload versions[' . $index . '] is missing column "' . $column . '"'
                    );
                }
            }
            $n = $version['version'];
            if (!is_int($n) && !(is_string($n) && ctype_digit($n))) {
                throw new InvalidArgumentException('payload versions[' . $index . '].version must be an integer');
            }
            if (isset($seenVersions[(int)$n])) {
                // UNIQUE (template_id, version) would reject this mid-transaction;
                // saying so up front names the actual problem.
                throw new InvalidArgumentException(
                    'payload carries version ' . (int)$n . ' twice — UNIQUE (template_id, version) forbids it'
                );
            }
            $seenVersions[(int)$n] = true;
            if (json_decode((string)$version['fields_json']) === null
                && strtolower(trim((string)$version['fields_json'])) !== 'null') {
                throw new InvalidArgumentException(
                    'payload versions[' . $index . '].fields_json is not valid JSON — the column is MySQL JSON '
                    . 'NOT NULL and the INSERT would be rejected'
                );
            }
            if ((int)$version['is_published'] === 1) {
                $anyPublished = true;
            }
        }

        if (!$anyPublished) {
            throw new InvalidArgumentException(
                'payload has no version with is_published=1 — seeding it would leave '
                . 'has_published_version false and fix nothing (see this class\'s header)'
            );
        }
    }

    /**
     * Seed the payload into $dst, PRESERVING the template id. Idempotent and
     * insert-only: see the three locks in this class's header.
     *
     * @param int|null $createdByOverride Local user id to attribute the rows to.
     *        The exported `created_by` references a user id in the SOURCE
     *        database that need not exist in the target. No FK is declared
     *        anywhere in migrations/*.sql so a dangling value cannot hard-fail,
     *        and the admin UI never renders it (public/admin.php lists id/name/
     *        latest_version only) — but pointing it at the target's own admin is
     *        strictly better than leaving a lie in the column. Pass null to keep
     *        the source value verbatim.
     *
     * @return array{result:string, template_id:int, versions_inserted:int,
     *               verified_has_published_version:bool, detail:string}
     */
    public static function seed(PDO $dst, array $payload, ?int $createdByOverride = null): array
    {
        self::validate($payload);

        $templateId = (int)$payload['template']['id'];

        // --- Lock 3: never write into a database that holds signed waivers ---
        // Checked BEFORE the id probe so the message names the real reason: on
        // production BOTH conditions hold, and "this database has signatures in
        // it" is the more alarming, more actionable one to surface.
        $signed = (int)$dst->query('SELECT COUNT(*) FROM waiver_responses')->fetchColumn();
        if ($signed > 0) {
            return [
                'result'      => self::RESULT_REFUSED_SIGNED_DATA,
                'template_id' => $templateId,
                'versions_inserted' => 0,
                'verified_has_published_version' => self::hasPublishedVersion($dst, $templateId),
                'detail'      => 'target database holds ' . $signed . ' waiver_responses row(s) — i.e. real '
                    . 'signatures. Refusing to seed: a bootstrap never writes into a database with signed '
                    . 'waivers in it. If this is production, nothing was written and nothing needed to be. '
                    . 'If this is a staging database that lost only its template, that is a case for a human.',
            ];
        }

        $dst->beginTransaction();
        try {
            // --- Lock 2: insert-only-if-absent. No UPDATE exists in this file. ---
            $probe = $dst->prepare('SELECT id FROM waiver_templates WHERE id = ?');
            $probe->execute([$templateId]);
            if ($probe->fetch(PDO::FETCH_ASSOC) !== false) {
                $dst->rollBack();
                return [
                    'result'      => self::RESULT_ALREADY_PRESENT,
                    'template_id' => $templateId,
                    'versions_inserted' => 0,
                    'verified_has_published_version' => self::hasPublishedVersion($dst, $templateId),
                    'detail'      => 'waiver_templates id ' . $templateId . ' already exists — nothing written. '
                        . 'This is the idempotent no-op on every redeploy, and the reason an accidentally-armed '
                        . 'production deploy cannot overwrite or duplicate the operator\'s document.',
                ];
            }

            $tpl = $payload['template'];
            $dst->prepare(
                'INSERT INTO waiver_templates (id, name, is_active, created_by, created_at, updated_at) '
                . 'VALUES (?,?,?,?,?,?)'
            )->execute([
                $templateId,                                   // EXPLICIT id — the whole point
                (string)$tpl['name'],
                (int)$tpl['is_active'],
                $createdByOverride ?? (int)$tpl['created_by'],
                (string)$tpl['created_at'],
                (string)$tpl['updated_at'],
            ]);

            $insert = $dst->prepare(
                'INSERT INTO waiver_template_versions '
                . '(template_id, version, title, description, fields_json, requires_signature, created_by, '
                . 'created_at, content_html, print_css, is_published, published_at) '
                . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $inserted = 0;
            foreach ($payload['versions'] as $version) {
                $isPublished = (int)$version['is_published'];
                // Keep seeded rows shape-identical to AdminController::publishVersion()'s:
                // it always writes published_at alongside is_published=1. A payload that
                // somehow carries the flag without the timestamp gets one here rather than
                // a NULL that would read as "published, but nobody knows when".
                $publishedAt = $version['published_at'];
                if ($isPublished === 1 && ($publishedAt === null || $publishedAt === '')) {
                    $publishedAt = gmdate('Y-m-d H:i:s');
                }
                $insert->execute([
                    $templateId,                               // re-derived, never trusted per-version
                    (int)$version['version'],
                    (string)$version['title'],
                    $version['description'],
                    (string)$version['fields_json'],
                    (int)$version['requires_signature'],
                    $createdByOverride ?? (int)$version['created_by'],
                    (string)$version['created_at'],
                    $version['content_html'],
                    $version['print_css'],
                    $isPublished,
                    $isPublished === 1 ? $publishedAt : null,
                ]);
                $inserted++;
            }

            $dst->commit();
        } catch (\Throwable $e) {
            if ($dst->inTransaction()) {
                $dst->rollBack();
            }
            throw $e;
        }

        // --- PROVE the gate actually flipped ---------------------------------
        // Not "we inserted a row with is_published=1" but "the EXACT query
        // WaiverController::hasPublishedVersion() runs now answers true". That is
        // the property BookingV2 depends on, so it is the one worth asserting.
        $verified = self::hasPublishedVersion($dst, $templateId);

        return [
            'result'      => self::RESULT_SEEDED,
            'template_id' => $templateId,
            'versions_inserted' => $inserted,
            'verified_has_published_version' => $verified,
            'detail'      => $verified
                ? 'seeded template ' . $templateId . ' with ' . $inserted . ' version(s); '
                    . 'has_published_version now answers TRUE'
                : 'seeded template ' . $templateId . ' with ' . $inserted . ' version(s) BUT '
                    . 'has_published_version still answers FALSE — the publish gate did not flip',
        ];
    }

    /**
     * The EXACT query WaiverController::hasPublishedVersion() runs. Duplicated
     * here on purpose: this is the post-condition assertion, and an assertion
     * that shares a code path with the thing it checks proves less.
     */
    public static function hasPublishedVersion(PDO $pdo, int $templateId): bool
    {
        $q = $pdo->prepare('SELECT 1 FROM waiver_template_versions WHERE template_id=? AND is_published=1 LIMIT 1');
        $q->execute([$templateId]);
        return $q->fetch() !== false;
    }

    /**
     * The lowest-id admin in $pdo, or null. Used to attribute seeded rows to the
     * target's OWN admin (seeded by dev/predeploy.php immediately before this).
     */
    public static function localAdminId(PDO $pdo): ?int
    {
        $row = $pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1")->fetchColumn();
        return $row === false ? null : (int)$row;
    }

    /** Column list for a SELECT. Names are class constants, never user input. */
    private static function backtickList(array $columns): string
    {
        return implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', $columns));
    }
}
