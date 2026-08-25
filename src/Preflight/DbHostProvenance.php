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
     * CONFIRMED-LIVE 2026-08-26 (was UNVERIFIED-LIVE): the Railway GraphQL shape
     * that reads one service instance's variable map as a JSON SCALAR (no
     * sub-selection). Live probing against backboard.railway.app/graphql/v2 with
     * the staging PROJECT token proved `variables(...)` REQUIRES `projectId`:
     * omitting it returns a bare HTTP 400 (request rejected before the GraphQL
     * error layer), while `variables(projectId, environmentId, serviceId)`
     * returns the service's variable map. (This differs from the
     * `serviceInstance(environmentId, serviceId)` baseline read the migrate/
     * discover jobs use, which needs no projectId.) `projectId` is supplied from
     * the RAILWAY_STAGING_PROJECT_ID workflow env constant. A wrong shape still
     * fails GraphQL validation LOUDLY → an `errors` array → `parseRailwayVariables`
     * returns `null` → the verdict fails closed (re-runnable red, never a silent
     * pass).
     */
    public const RAILWAY_SERVICE_VARIABLES_QUERY =
        'query ($projectId: String!, $environmentId: String!, $serviceId: String!) { variables(projectId: $projectId, environmentId: $environmentId, serviceId: $serviceId) }';

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

    /**
     * The Railway GraphQL API endpoint the pre-flight POSTs the staging
     * Project-Access-Token to — the SAME hardcoded https default the workflow env
     * sets `RAILWAY_API_URL` to (.github/workflows/ci.yml). It is duplicated here
     * (not read from that file at runtime — the pre-flight container never sees the
     * workflow) so the token's DESTINATION HOST can be pinned in code: the host the
     * token may be sent to is DERIVED from this URL's parse_url host
     * (`expectedRailwayApiHost()`), never hand-typed as a bare host literal. If the
     * workflow default ever changes host, change this constant in the same commit.
     */
    public const RAILWAY_API_URL_DEFAULT = 'https://backboard.railway.app/graphql/v2';

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
        // Any value whose labels are ALL integer spellings — decimal, or a C-style
        // hex/octal octet (`0x7f`, `017`) — is an IPv4-literal ATTEMPT and MUST pass
        // the strictly-canonical dotted-quad bar. This defeats the octal AND the hex
        // parser differentials at once: inet_aton-family resolvers read `017.0.0.1`
        // (octal) and `0x7f.0.0.1` / `0x7f000001` (hex) as 127.0.0.1, but a bare
        // `/^[0-9.]+$/` trigger would let the hex spellings slip through as
        // DNS-shaped hosts. A real DNS host always carries a label that is not an
        // integer spelling and skips this. normalizeIpv4Strict then trusts only a
        // canonical decimal quad, so every hex/octal/short form → null (fail-closed).
        if (self::isNumericIpv4Attempt($stripped)) {
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
            // A PRESENT candidate whose value is a NON-STRING is malformed —
            // Railway serializes every connection URL as a JSON string. This is
            // "present but not usable", NOT "absent": first-present-decides means it
            // fails the map to INDETERMINATE (return null), never a fall-through to a
            // lower, possibly-weaker candidate an ambiguous map could exploit.
            if (!is_string($value)) {
                return null;
            }
            // An EMPTY string is Railway's serialization of an UNSET variable (not a
            // malformed value) → treat as genuinely absent and try the next one.
            if (trim($value) === '') {
                continue;
            }
            // PRESENT, non-empty string decides (fail-closed): trusted → use it;
            // untrusted (unparseable) → `null`, never a fall-through to a lower one.
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
        if (isset($decoded['errors'])) {
            // isset() is already false for a null `errors`, so we are here only for a
            // PRESENT, non-null errors field. A clean GraphQL success omits it (or
            // sends null); per the spec a present errors field is a NON-EMPTY LIST.
            //   · not a list (a string / number / object) → a MALFORMED response, not
            //     a success → fail closed, so we never read `data` out of an answer
            //     whose shape we do not understand (an HTTP-200 with a non-array
            //     `errors` must never be able to reach a PASS).
            //   · a non-empty list → an actual error set → fail closed.
            //   · an empty list is spec-violating but carries no error signal; it
            //     falls through to the data check below, which fails closed on its
            //     own if the payload is unusable.
            if (!is_array($decoded['errors'])) {
                return null;
            }
            if (count($decoded['errors']) > 0) {
                return null;
            }
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
     * The Railway GraphQL endpoint the pre-flight sends its project token to must
     * be an unambiguous `https://HOST…` URL — and this predicate is the TRANSPORT
     * half of the gate on that (the destination-host half is apiUrlHostIsExpected()).
     * The token (`Project-Access-Token`) is a bearer credential; sending it over
     * http:// (or any non-https scheme) would put it on the wire in cleartext.
     * Returns true ONLY when parse_url yields BOTH an exactly-`https` scheme
     * (case-insensitive, RFC 3986 §3.1) AND a NON-EMPTY host. Every other value
     * fails CLOSED so the caller never places the token in a header for it: http,
     * ws, ftp, a scheme-less or scheme-relative URL, an unparseable one, a
     * mis-pasted mysql DSN — AND the hostless/opaque forms parse_url reports a
     * scheme-but-no-host for (`https:`, `https:foo`, `https:host/path`: a missing
     * `//` turns the authority into a path, so there is no host to send a token to).
     *
     * RAILWAY_API_URL ships as a hardcoded https default in the workflow env, with
     * no override path wired today; this guard exists for OVERRIDE-SAFETY — a later
     * edit to that env constant can never silently downgrade the token's transport
     * to a non-https scheme or a hostless URL.
     */
    public static function isHttpsApiUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (strtolower((string) $parts['scheme']) !== 'https') {
            return false;
        }
        return trim((string) $parts['host']) !== '';
    }

    /**
     * The DESTINATION half of the token gate (beside isHttpsApiUrl()'s transport
     * half): the Railway Project-Access-Token may be sent to the Railway API host
     * and NOTHING else. A scheme-only guard still lets `https://user:pass@attacker/`
     * receive the bearer token — a valid https URL with a non-empty host — so this
     * pins the host to the ONE the workflow's hardcoded RAILWAY_API_URL default
     * names (`expectedRailwayApiHost()`, DERIVED from RAILWAY_API_URL_DEFAULT, never
     * a hand-typed host). Returns true ONLY when the configured URL's PARSED host
     * (lowercased; userinfo is already stripped by parse_url, so the raw
     * `user:pass@` authority is never part of the compare) equals that expected
     * host. Every other value — a different host, a hostless/opaque URL, an
     * unparseable one — fails CLOSED.
     */
    public static function apiUrlHostIsExpected(string $url): bool
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['host'])) {
            return false;
        }
        $host = strtolower(trim((string) $parts['host']));
        if ($host === '') {
            return false;
        }
        return $host === self::expectedRailwayApiHost();
    }

    /**
     * The expected Railway API host — the parse_url host of the hardcoded
     * RAILWAY_API_URL_DEFAULT, lowercased. Derived, never a bare host literal, so
     * the destination pin cannot drift from the URL the workflow actually POSTs to.
     */
    private static function expectedRailwayApiHost(): string
    {
        $host = parse_url(self::RAILWAY_API_URL_DEFAULT, PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
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

    /**
     * True when every dot-separated label of $host is a C-style integer spelling
     * — decimal (`10`), hex (`0x7f`), or octal (leading-zero decimal `017`) —
     * which is exactly the set inet_aton-family resolvers parse as a packed IPv4
     * address. Such a value is an IPv4-literal ATTEMPT and must be held to
     * normalizeIpv4Strict's canonical-quad bar rather than trusted as a DNS host;
     * a genuine hostname always carries at least one non-integer label. `$host`
     * arrives already lowercased from canonicalizeHost, so `0x` (not `0X`) is the
     * only hex prefix to match.
     */
    private static function isNumericIpv4Attempt(string $host): bool
    {
        foreach (explode('.', $host) as $label) {
            if (!preg_match('/^(?:0x[0-9a-f]+|[0-9]+)$/', $label)) {
                return false;
            }
        }
        return true;
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
