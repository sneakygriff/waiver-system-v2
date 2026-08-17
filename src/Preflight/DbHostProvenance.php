<?php
declare(strict_types=1);

namespace App\Preflight;

/**
 * DbHostProvenance — the fork's migrate-job URL-provenance pre-flight logic.
 * [CI/CD M5.6, AC4.6 — the mechanical, each-run provenance proof]
 *
 * WHAT THIS PROVES (and why it exists)
 * ------------------------------------
 * The fork's staging CI holds `STAGING_WAIVER_DB_URL` — the connection URL the
 * `dump` and `migrate` jobs point `mysqldump` / `migrations/run.php` at. That
 * URL is operator-set. If it ever named the PRODUCTION waiver database (a
 * mis-mint, a wrong copy-paste from the wrong Railway panel, a later rotation to
 * the wrong value), a staging CI run would DUMP and MIGRATE production data —
 * the exact cross-tier PII/data-destruction event the whole CI/CD tier split
 * exists to make impossible.
 *
 * The M4 gate1 adjudication (Part 3) ruled the mechanical closer for M5: query
 * the STAGING MySQL service's OWN Railway-provisioned connection variables and
 * assert `STAGING_WAIVER_DB_URL` reaches THE SAME endpoint Railway reports for
 * the staging MySQL service. Railway is the independent source of truth for
 * "what the staging DB actually is", so the URL cannot silently point elsewhere.
 * A prod-host NEGATIVE literal was explicitly REJECTED (it would plant a prod
 * identifier in the fork tree, violating AC4.4). A second `EXPECTED_HOST` env
 * var was REJECTED (same operator provenance as the URL → zero added proof).
 * This is a POSITIVE sameness proof against a live, independent oracle.
 *
 * COMPARISON UNIT — HOST **AND PORT** (the fork-side divergence from M5.5)
 * ----------------------------------------------------------------------
 * The admin sibling (`ac4.6.waiver-db-host-distinct`, M5.5) proves staging and
 * production are DISTINCT endpoints — a DISTINCTNESS test, where a host-only
 * compare's worst case is a false-FAIL (a re-runnable red; safe). THIS check is
 * the opposite polarity — a SAMENESS test — where a host-only compare's worst
 * case is a false-PASS (unsafe). And it matters concretely: Railway's PUBLIC
 * MySQL endpoints (the only ones a GitHub-hosted runner can reach — the private
 * `*.railway.internal` network is unreachable from CI) draw their HOST from a
 * shared TCP-proxy DOMAIN POOL (e.g. `*.proxy.rlwy.net`) and are distinguished
 * ONLY by their per-service proxy PORT. Staging and production MySQL public URLs
 * therefore very likely share the proxy host, differing only in port — so a
 * host-only sameness compare would PASS a production URL that happens to sit on
 * the same proxy domain. That would make this gate worse than useless (it would
 * PASS the exact thing it exists to block). So this check compares the FULL
 * canonical AUTHORITY — host AND port — and an explicit port is REQUIRED on both
 * sides (a Railway public MySQL endpoint always carries its proxy port; the
 * runbook-built staging URL always carries it too). This is a strict
 * fail-CLOSED strengthening of the adjudication's "host equals": host equality
 * is still necessary, and the port equality closes the shared-proxy false-PASS.
 *
 * HOST-PARSE POSTURE — CANONICAL-ONLY, FAIL-CLOSED (ported from M5.5 round 3–6)
 * ---------------------------------------------------------------------------
 * A host is trusted ONLY as an unambiguous canonical form; every ambiguity is
 * refused to `null` → the verdict fails closed (deny-on-doubt):
 *   · STRICT `parse_url`; the scheme MUST be a mysql-family DSN scheme
 *     (`mysql`/`mysql2`/`mariadb` — the fork's own URL contract, see
 *     `migrations/run.php`) — any other scheme (`https:`, …) → `null`. NO regex
 *     fallback that could surface a username/authority fragment as a "host".
 *   · The host is lowercased, a single trailing FQDN dot stripped, then
 *     CHARSET-ALLOWLISTED (`^[a-z0-9.-]+$`) and RFC-1123 STRUCTURE-validated
 *     (labels 1–63, no label-edge hyphen, ≤253 total). The allowlist rejects
 *     `:` / `[` / `@` / `=` / whitespace, so an IPv6 literal, a URL authority,
 *     or a credential-bearing DSN fragment can never become a "host".
 *   · IPv4 literals: ONLY a strictly-canonical dotted quad is trusted — exactly
 *     four octets, each ASCII-decimal 0–255, NO leading-zero octet (the client
 *     resolvers read `017.0.0.1` as OCTAL = 15.0.0.1: a parser differential —
 *     CVE-2021-29923 class), and NOT in a special-use block (`127.0.0.0/8`
 *     loopback, `0.0.0.0/8` unspecified). ANY all-numeric-and-dots value is
 *     treated as an IPv4-literal ATTEMPT and MUST pass that bar (so `1.2.3`,
 *     `1.2.3.4.5`, `999.1.1.1` all → `null`); a real DNS host always carries a
 *     non-digit character. An IPv6 literal in any spelling → `null`.
 *
 * SECRET SAFETY
 * -------------
 * The Railway variable map CONTAINS SECRETS (passwords, full connection URLs)
 * and `STAGING_WAIVER_DB_URL` is itself a secret. This class parses both for an
 * AUTHORITY only and NEVER returns, logs, or places in a verdict reason the raw
 * URL, the credentials, or even the host/port VALUES. Verdict reasons name only
 * the ROLE ("STAGING_WAIVER_DB_URL host"), the fixed Railway variable NAME the
 * staging authority resolved from (a public constant like `MYSQL_PUBLIC_URL`,
 * not a value), and an explicit "values withheld" — never a concrete host, port,
 * user, or password.
 */
final class DbHostProvenance
{
    /**
     * UNVERIFIED-LIVE (M5.9 first-run confirmation owed): the Railway GraphQL
     * shape that reads one service instance's variable map as a JSON SCALAR (no
     * sub-selection). The fork holds a Railway PROJECT token (staging-scoped),
     * which — like the migrate job's already-live-verified
     * `serviceInstance(environmentId, serviceId)` baseline read — is implicitly
     * scoped to one project, so this shape passes `environmentId` + `serviceId`
     * WITHOUT a `projectId` (the fork deliberately holds no project id). If the
     * live field name, its argument set, or its scalar-vs-selection shape differs,
     * this query fails GraphQL validation LOUDLY → an `errors` array →
     * `parseRailwayVariables` returns `null` → the verdict fails closed. A wrong
     * shape can therefore only ever produce a re-runnable red, never a silent
     * pass. M5.9 pins the confirmed shape, exactly as M3's §6e residuals did.
     */
    public const RAILWAY_SERVICE_VARIABLES_QUERY =
        'query ($environmentId: String!, $serviceId: String!) { variables(environmentId: $environmentId, serviceId: $serviceId) }';

    /**
     * mysql-family DSN schemes — the fork's OWN URL contract (`migrations/run.php`
     * accepts exactly these; the `dump` job's python guard the same). Matching
     * run.php exactly means every URL this gate accepts, run.php also connects to
     * (no false-FAIL on a documented-valid staging URL), and vice-versa. Every
     * non-DSN scheme (`https:`, `file:`, …) is refused → `null` (fail-closed).
     */
    public const SCHEME_ALLOWLIST = ['mysql', 'mysql2', 'mariadb'];

    /**
     * The connection-URL variables to read the staging MySQL AUTHORITY from, in
     * strict preference order — PUBLIC endpoints first (the only ones a
     * GitHub-hosted runner can reach; a private/internal URL is unreachable from
     * CI, so `STAGING_WAIVER_DB_URL` is a public URL and the meaningful Railway
     * authority is the public one). The FIRST PRESENT candidate DECIDES
     * (fail-closed): a present-but-untrusted value returns `null` rather than
     * falling through to a lower candidate (an ambiguous map must not be able to
     * force resolution from a weaker source). The `RAILWAY_TCP_PROXY_DOMAIN`
     * direct-host variable is DELIBERATELY EXCLUDED: it carries the proxy DOMAIN
     * without the distinguishing port, so it cannot yield a full authority.
     */
    public const URL_VARIABLE_CANDIDATES = [
        'MYSQL_PUBLIC_URL',
        'DATABASE_PUBLIC_URL',
        'MYSQL_URL',
        'DATABASE_URL',
    ];

    private const CANONICAL_HOST_ALLOWLIST = '/^[a-z0-9.-]+$/';
    private const HOSTNAME_LABEL           = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';
    private const RULE                     = 'ac4.6.waiver-db-url-provenance';

    /**
     * Reduce a raw host token to its canonical comparison form, or `null` when it
     * is not an unambiguous bare hostname / strictly-canonical IPv4 literal.
     * The single shared choke point for both the URL host and the Railway host.
     */
    public static function canonicalizeHost(string $host): ?string
    {
        $lowered = strtolower(trim($host));
        if ($lowered === '') {
            return null;
        }
        // Strip a single trailing FQDN root dot so `db.x` and `db.x.` compare equal.
        $stripped = (strlen($lowered) > 1 && str_ends_with($lowered, '.'))
            ? substr($lowered, 0, -1)
            : $lowered;
        if ($stripped === '') {
            return null;
        }
        // CHARSET ALLOWLIST — rejects `:` `[` `@` `=` `;` `/` whitespace etc., so an
        // IPv6 literal, a URL authority, or a credential fragment cannot survive.
        if (!preg_match(self::CANONICAL_HOST_ALLOWLIST, $stripped)) {
            return null;
        }
        // RFC-1123 STRUCTURE — rejects `a..b`, `-x`, `x-`, a bare `.`.
        if (!self::isStructurallyValidHostname($stripped)) {
            return null;
        }
        // Any all-numeric-and-dots value is an IPv4-literal ATTEMPT and MUST be a
        // strictly-canonical quad (defeats the octal/short-form parser differential);
        // a real DNS host always carries a non-digit character and skips this.
        if (preg_match('/^[0-9.]+$/', $stripped)) {
            return self::normalizeIpv4Strict($stripped);
        }
        return $stripped;
    }

    /**
     * Parse a mysql-family connection URL STRICTLY into its canonical
     * `{host, port}` AUTHORITY, or `null` on any doubt. An explicit, in-range
     * port is REQUIRED (see the class docblock — the shared-proxy defeat).
     *
     * @return array{host:string,port:int}|null
     */
    public static function parseAuthorityFromUrl(string $url): ?array
    {
        $raw = trim($url);
        if ($raw === '') {
            return null;
        }
        $parts = parse_url($raw);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        if (!in_array(strtolower((string) $parts['scheme']), self::SCHEME_ALLOWLIST, true)) {
            return null;
        }
        // Explicit port required. `parse_url` already returns it as an int with any
        // leading zeros stripped, so there is no octal port ambiguity to guard.
        if (!isset($parts['port']) || !is_int($parts['port'])) {
            return null;
        }
        $port = $parts['port'];
        if ($port < 1 || $port > 65535) {
            return null;
        }
        $host = self::canonicalizeHost((string) $parts['host']);
        if ($host === null) {
            return null;
        }
        return ['host' => $host, 'port' => $port];
    }

    /**
     * Resolve the staging MySQL AUTHORITY from a Railway variable map. First
     * present candidate decides, fail-closed. Returns the authority WITH its
     * `source` (the variable NAME — safe to surface), or `null` when no candidate
     * is present or the first present one is untrusted.
     *
     * @param  array<string,mixed> $variables
     * @return array{host:string,port:int,source:string}|null
     */
    public static function extractRailwayAuthority(array $variables): ?array
    {
        foreach (self::URL_VARIABLE_CANDIDATES as $key) {
            if (!array_key_exists($key, $variables)) {
                continue; // genuinely ABSENT → try the next candidate
            }
            $value = $variables[$key];
            if (!is_string($value) || trim($value) === '') {
                continue; // empty/non-string is treated as absent → next candidate
            }
            // PRESENT decides (fail-closed): trusted → use it; untrusted → `null`,
            // never a fall-through to a lower, possibly-weaker candidate.
            $authority = self::parseAuthorityFromUrl($value);
            if ($authority === null) {
                return null;
            }
            return ['host' => $authority['host'], 'port' => $authority['port'], 'source' => $key];
        }
        return null; // no candidate present → indeterminate
    }

    /**
     * Validate a raw Railway GraphQL response body and return the service
     * instance's variable map, or `null` on ANY unusable answer (fail-closed):
     * unparseable JSON, a non-empty `errors` array, a null/absent `data`, an
     * absent `variables` field, or a `variables` that is not an object.
     *
     * @return array<string,mixed>|null
     */
    public static function parseRailwayVariables(?string $body): ?array
    {
        if ($body === null || trim($body) === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }
        if (isset($decoded['errors']) && is_array($decoded['errors']) && count($decoded['errors']) > 0) {
            return null; // the query failed validation/authorization → fail closed
        }
        $data = $decoded['data'] ?? null;
        if (!is_array($data) || !array_key_exists('variables', $data)) {
            return null;
        }
        $variables = $data['variables'];
        if (!is_array($variables)) {
            return null;
        }
        /** @var array<string,mixed> $variables */
        return $variables;
    }

    /**
     * The verdict. PASS only when BOTH authorities resolved AND their host and
     * port are equal. Every other state fails CLOSED. Reasons are secret-safe:
     * ROLE + Railway variable NAME + "values withheld" — never a host/port value,
     * a credential, or the raw URL.
     *
     * @param  array{host:string,port:int}|null        $urlAuthority
     * @param  array{host:string,port:int,source:string}|null $railwayAuthority
     * @return array{ok:bool,code:string,reason:string}
     */
    public static function verdict(?array $urlAuthority, ?array $railwayAuthority): array
    {
        if ($urlAuthority === null) {
            return self::fail(
                self::RULE . '.url-unparseable',
                'STAGING_WAIVER_DB_URL did not parse to a canonical mysql host:port authority (value withheld) — the migrate target is unverifiable. Refusing to migrate/dump.'
            );
        }
        if ($railwayAuthority === null) {
            return self::fail(
                self::RULE . '.railway-host-indeterminate',
                'The staging MySQL service exposed no canonical connection-URL authority via Railway (value withheld) — provenance cannot be proven (Railway API down, an unexpected query shape, or no usable public URL variable). Refusing to migrate/dump; the run is re-runnable.'
            );
        }
        if ($urlAuthority['host'] !== $railwayAuthority['host']) {
            return self::fail(
                self::RULE . '.host-mismatch',
                'STAGING_WAIVER_DB_URL host does not match the staging MySQL host Railway reports (from ' . $railwayAuthority['source'] . '; both values withheld). The URL does not point at the staging waiver database — refusing to migrate/dump (a host mismatch is exactly how a production URL would present).'
            );
        }
        if ($urlAuthority['port'] !== $railwayAuthority['port']) {
            return self::fail(
                self::RULE . '.port-mismatch',
                'STAGING_WAIVER_DB_URL host matches but its port does not match the staging MySQL port Railway reports (from ' . $railwayAuthority['source'] . '; both values withheld). Railway public MySQL endpoints share a proxy DOMAIN distinguished ONLY by port, so a matching host on a different port is how a production endpoint on the same proxy pool would present — refusing to migrate/dump.'
            );
        }
        return self::pass(
            self::RULE,
            'STAGING_WAIVER_DB_URL authority matches the staging MySQL authority Railway reports (from ' . $railwayAuthority['source'] . '; values withheld) — provenance confirmed.'
        );
    }

    /** RFC-1123 hostname STRUCTURE: ≤253 total, every label a valid 1–63 char label. */
    private static function isStructurallyValidHostname(string $host): bool
    {
        if (strlen($host) > 253) {
            return false;
        }
        foreach (explode('.', $host) as $label) {
            if (!preg_match(self::HOSTNAME_LABEL, $label)) {
                return false;
            }
        }
        return true;
    }

    /**
     * A strictly-canonical dotted-quad IPv4, or `null`. Rejects: not-exactly-four
     * octets, a non-ASCII-decimal octet, a leading-zero octet (octal differential),
     * an out-of-range octet (>255), and the special-use `0.0.0.0/8` + `127.0.0.0/8`
     * blocks (never a real Railway MySQL endpoint).
     */
    private static function normalizeIpv4Strict(string $host): ?string
    {
        $parts = explode('.', $host);
        if (count($parts) !== 4) {
            return null;
        }
        $octets = [];
        foreach ($parts as $part) {
            if (!preg_match('/^\d{1,3}$/', $part)) {
                return null;
            }
            if (strlen($part) > 1 && $part[0] === '0') {
                return null; // leading zero → octal to the client resolvers; refuse
            }
            $n = (int) $part;
            if ($n > 255) {
                return null;
            }
            $octets[] = $n;
        }
        if ($octets[0] === 0 || $octets[0] === 127) {
            return null; // 0.0.0.0/8 (unspecified) + 127.0.0.0/8 (loopback) special-use
        }
        return implode('.', $octets);
    }

    /** @return array{ok:bool,code:string,reason:string} */
    private static function fail(string $code, string $reason): array
    {
        return ['ok' => false, 'code' => $code, 'reason' => $reason];
    }

    /** @return array{ok:bool,code:string,reason:string} */
    private static function pass(string $code, string $reason): array
    {
        return ['ok' => true, 'code' => $code, 'reason' => $reason];
    }
}
