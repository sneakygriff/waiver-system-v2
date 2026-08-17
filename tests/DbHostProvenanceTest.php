<?php
declare(strict_types=1);

namespace Tests;

use App\Preflight\DbHostProvenance;
use PHPUnit\Framework\TestCase;

/**
 * [CI/CD M5.6, AC4.6] Pure-logic pins on the migrate-job URL-provenance
 * pre-flight (src/Preflight/DbHostProvenance.php). No DB, no network: every
 * assertion drives the pure parser/compare with in-memory inputs, so this suite
 * runs standalone and is NOT DB-backed (tests/README.md's markTestSkipped()
 * convention does not apply -- there is nothing to skip).
 *
 * The property under test: STAGING_WAIVER_DB_URL passes ONLY when its full
 * canonical authority (host AND port) equals the staging MySQL authority Railway
 * reports; a prod-pointing URL, an unparseable one, a shared-proxy prod-PORT
 * one, or an indeterminate Railway read all FAIL closed. Several tests double as
 * documented MUTATION KILLS (named on the assertion) for the distinctness,
 * fail-closed, port, and first-present-decides guards.
 */
final class DbHostProvenanceTest extends TestCase
{
    // A realistic Railway PUBLIC MySQL endpoint: shared proxy DOMAIN, per-service PORT.
    private const STAGING_HOST = 'monorail.proxy.rlwy.net';
    private const STAGING_PORT = 23456;
    private const PROD_PORT    = 54321; // same proxy host, different service port

    private static function stagingUrl(): string
    {
        return 'mysql://appuser:appsecret@' . self::STAGING_HOST . ':' . self::STAGING_PORT . '/railway';
    }

    /** @return array<string,string> a Railway variable map exposing the staging public URL. */
    private static function stagingVarsPublic(): array
    {
        return [
            'MYSQLHOST'        => 'mysql.railway.internal',
            'MYSQLPORT'        => '3306',
            'MYSQL_URL'        => 'mysql://appuser:appsecret@mysql.railway.internal:3306/railway',
            'MYSQL_PUBLIC_URL' => self::stagingUrl(),
        ];
    }

    // ── The happy path: the staging URL matches the live staging authority ──────

    public function testStagingUrlMatchingLiveStagingAuthorityPasses(): void
    {
        $url     = DbHostProvenance::parseAuthorityFromUrl(self::stagingUrl());
        $railway = DbHostProvenance::extractRailwayAuthority(self::stagingVarsPublic());
        $verdict = DbHostProvenance::verdict($url, $railway);
        $this->assertTrue($verdict['ok'], 'a staging URL that matches the live staging host:port must PASS.');
        $this->assertSame('ac4.6.waiver-db-url-provenance', $verdict['code']);
    }

    public function testStrictlyCanonicalIpv4AuthorityMatchPasses(): void
    {
        // No over-refusal: a routable, strictly-canonical dotted-quad on both sides passes.
        $url     = DbHostProvenance::parseAuthorityFromUrl('mysql://u:p@10.0.0.5:3306/railway');
        $railway = DbHostProvenance::extractRailwayAuthority(['MYSQL_PUBLIC_URL' => 'mysql://u:p@10.0.0.5:3306/railway']);
        $this->assertTrue(DbHostProvenance::verdict($url, $railway)['ok']);
    }

    // ── The core guard: a URL that points somewhere else FAILS ─────────────────

    public function testUrlWhoseHostEqualsTheProdHostFails(): void
    {
        // "distinct-host STAGING_WAIVER_DB_URL passes; one whose host EQUALS the prod
        // host FAILS": the URL names the PROD host (different from the live staging
        // host), so provenance fails. MUTATION KILL for the host-equality guard --
        // inverting `host !== host` to `===` would flip this to PASS.
        $url     = DbHostProvenance::parseAuthorityFromUrl('mysql://u:p@prod-db.proxy.rlwy.net:3306/railway');
        $railway = DbHostProvenance::extractRailwayAuthority(self::stagingVarsPublic());
        $verdict = DbHostProvenance::verdict($url, $railway);
        $this->assertFalse($verdict['ok'], 'a URL pointing at a different host than the live staging DB must FAIL.');
        $this->assertSame('ac4.6.waiver-db-url-provenance.host-mismatch', $verdict['code']);
    }

    public function testSharedProxyDomainWithProdPortFails(): void
    {
        // THE fork-side hazard: Railway public MySQL endpoints share a proxy DOMAIN
        // distinguished ONLY by port. A prod URL on the SAME proxy host as staging
        // has the SAME host but a different port -- host-only equality would PASS it
        // (a false-PASS of the exact thing this gate blocks). MUTATION KILL for the
        // port guard: dropping the port comparison flips this to PASS.
        $prodUrlOnSharedProxy = 'mysql://u:p@' . self::STAGING_HOST . ':' . self::PROD_PORT . '/railway';
        $url     = DbHostProvenance::parseAuthorityFromUrl($prodUrlOnSharedProxy);
        $railway = DbHostProvenance::extractRailwayAuthority(self::stagingVarsPublic());
        $verdict = DbHostProvenance::verdict($url, $railway);
        $this->assertFalse($verdict['ok'], 'a matching host on a different (prod) proxy port must FAIL.');
        $this->assertSame('ac4.6.waiver-db-url-provenance.port-mismatch', $verdict['code']);
    }

    // ── Fail-closed on every unusable input ────────────────────────────────────

    public function testMissingUrlFailsClosed(): void
    {
        // MUTATION KILL for the fail-closed guard: turning `url === null -> fail`
        // into a pass flips this to PASS.
        $verdict = DbHostProvenance::verdict(
            DbHostProvenance::parseAuthorityFromUrl(''),
            DbHostProvenance::extractRailwayAuthority(self::stagingVarsPublic())
        );
        $this->assertFalse($verdict['ok']);
        $this->assertSame('ac4.6.waiver-db-url-provenance.url-unparseable', $verdict['code']);
    }

    public function testIndeterminateRailwayReadFailsClosed(): void
    {
        // A resolvable URL but NO usable Railway authority (API down / empty map /
        // wrong shape) must FAIL, never PASS on the URL alone.
        $verdict = DbHostProvenance::verdict(
            DbHostProvenance::parseAuthorityFromUrl(self::stagingUrl()),
            null
        );
        $this->assertFalse($verdict['ok']);
        $this->assertSame('ac4.6.waiver-db-url-provenance.railway-host-indeterminate', $verdict['code']);
    }

    /**
     * @dataProvider malformedUrlProvider
     */
    public function testMalformedOrDisallowedUrlsFailClosed(string $url, string $why): void
    {
        $this->assertNull(DbHostProvenance::parseAuthorityFromUrl($url), $why);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function malformedUrlProvider(): array
    {
        return [
            'no host'                 => ['mysql://', 'a scheme with no host is not an authority'],
            'garbage'                 => ['not a url at all', 'unparseable → null'],
            'https scheme'            => ['https://stage.proxy.rlwy.net:443/x', 'a non-mysql scheme is refused'],
            'file scheme'             => ['file:///etc/passwd', 'a non-mysql scheme is refused'],
            'no port'                 => ['mysql://stage.rlwy.net/railway', 'an explicit port is required (proxy defeat)'],
            'port out of range'       => ['mysql://stage.rlwy.net:70000/railway', 'a >65535 port is invalid'],
            'octal ipv4 host'         => ['mysql://017.0.0.1:3306/db', 'a leading-zero octet is read as octal → refuse'],
            'ipv6 literal host'       => ['mysql://[::1]:3306/db', 'an IPv6 literal is refused (allowlist)'],
            'loopback ipv4 host'      => ['mysql://127.0.0.1:3306/db', 'special-use loopback is refused'],
            'unspecified ipv4 host'   => ['mysql://0.0.0.0:3306/db', 'special-use 0.0.0.0/8 is refused'],
            'out of range ipv4 octet' => ['mysql://256.1.1.1:3306/db', 'an octet >255 is not IPv4'],
            'three part numeric host' => ['mysql://1.2.3:3306/db', 'a non-4-octet all-numeric host is an IPv4 attempt → refuse'],
            'credential-severed'      => ['mysql://user:pw/frag@host:3306/db', 'a severed authority never yields a host fragment'],
        ];
    }

    // ── canonicalizeHost directly (the shared choke point) ─────────────────────

    /**
     * @dataProvider hostProvider
     */
    public function testCanonicalizeHost(string $host, ?string $expected): void
    {
        $this->assertSame($expected, DbHostProvenance::canonicalizeHost($host));
    }

    /** @return array<string,array{0:string,1:?string}> */
    public static function hostProvider(): array
    {
        return [
            'dns host lowercased'   => ['Stage.Proxy.Rlwy.Net', 'stage.proxy.rlwy.net'],
            'trailing dot stripped' => ['db.railway.internal.', 'db.railway.internal'],
            'routable ipv4'         => ['10.0.0.5', '10.0.0.5'],
            'octal ipv4'            => ['017.0.0.1', null],
            'leading zero octet'    => ['10.000.000.001', null],
            'loopback'              => ['127.0.0.1', null],
            'unspecified'           => ['0.0.0.0', null],
            'out of range'          => ['999.1.1.1', null],
            'three octets'          => ['1.2.3', null],
            'five octets'           => ['1.2.3.4.5', null],
            'ipv6 bracketed'        => ['[::1]', null],
            'ipv6 bare'             => ['::1', null],
            'has at sign'           => ['user@host', null],
            'has colon'             => ['host:3306', null],
            'empty label'           => ['a..b', null],
            'edge hyphen'           => ['-x.example', null],
            'bare dot'              => ['.', null],
            'empty'                 => ['', null],
        ];
    }

    // ── Railway variable extraction: first-present-decides, fail-closed ────────

    public function testRailwayExtractionFirstPresentUntrustedDoesNotFallThrough(): void
    {
        // MUTATION KILL for first-present-decides: a PRESENT-but-untrusted preferred
        // candidate (an https URL) must fail the map to INDETERMINATE, NEVER fall
        // through to a valid lower candidate. A fall-through mutation returns the
        // MYSQL_URL authority instead of null.
        $vars = [
            'MYSQL_PUBLIC_URL' => 'https://decoy.proxy.rlwy.net:443/x', // present, untrusted
            'MYSQL_URL'        => self::stagingUrl(),                    // valid, lower priority
        ];
        $this->assertNull(
            DbHostProvenance::extractRailwayAuthority($vars),
            'a present-but-untrusted preferred candidate must not fall through to a lower one.'
        );
    }

    public function testRailwayExtractionSkipsGenuinelyAbsentCandidate(): void
    {
        // An ABSENT (or empty) higher candidate is skipped to the next present one.
        $vars = [
            'MYSQL_PUBLIC_URL' => '',              // empty → treated as absent
            'MYSQL_URL'        => self::stagingUrl(),
        ];
        $authority = DbHostProvenance::extractRailwayAuthority($vars);
        $this->assertNotNull($authority);
        $this->assertSame('MYSQL_URL', $authority['source']);
        $this->assertSame(self::STAGING_HOST, $authority['host']);
    }

    public function testRailwayExtractionPrefersPublicOverLowerCandidates(): void
    {
        $authority = DbHostProvenance::extractRailwayAuthority(self::stagingVarsPublic());
        $this->assertNotNull($authority);
        $this->assertSame('MYSQL_PUBLIC_URL', $authority['source']);
    }

    public function testRailwayExtractionNoCandidateIsIndeterminate(): void
    {
        $this->assertNull(DbHostProvenance::extractRailwayAuthority([]));
        $this->assertNull(DbHostProvenance::extractRailwayAuthority(['MYSQLHOST' => 'mysql.railway.internal']));
    }

    // ── parseRailwayVariables: GraphQL response validation, fail-closed ────────

    /**
     * @dataProvider railwayBodyProvider
     */
    public function testParseRailwayVariablesFailsClosedOnUnusableBodies(?string $body, bool $expectNull): void
    {
        $result = DbHostProvenance::parseRailwayVariables($body);
        if ($expectNull) {
            $this->assertNull($result);
        } else {
            $this->assertIsArray($result);
        }
    }

    /** @return array<string,array{0:?string,1:bool}> */
    public static function railwayBodyProvider(): array
    {
        return [
            'null body'          => [null, true],
            'empty body'         => ['', true],
            'unparseable'        => ['<html>502</html>', true],
            'graphql errors'     => ['{"errors":[{"extensions":{"code":"NOT_AUTHORIZED"}}],"data":null}', true],
            'null data'          => ['{"data":null}', true],
            'no variables field' => ['{"data":{"other":1}}', true],
            'variables not obj'  => ['{"data":{"variables":"nope"}}', true],
            'valid map'          => ['{"data":{"variables":{"MYSQL_PUBLIC_URL":"mysql://u:p@h.rlwy.net:1/db"}}}', false],
        ];
    }

    public function testValidRailwayBodyThreadsThroughToAPass(): void
    {
        // End-to-end of the pure pipeline: a well-formed Railway body whose public
        // URL matches STAGING_WAIVER_DB_URL yields a PASS.
        $body     = '{"data":{"variables":{"MYSQL_PUBLIC_URL":' . json_encode(self::stagingUrl()) . '}}}';
        $vars     = DbHostProvenance::parseRailwayVariables($body);
        $railway  = $vars === null ? null : DbHostProvenance::extractRailwayAuthority($vars);
        $url      = DbHostProvenance::parseAuthorityFromUrl(self::stagingUrl());
        $this->assertTrue(DbHostProvenance::verdict($url, $railway)['ok']);
    }

    // ── Secret safety: no host/port/credential/raw-URL ever reaches a reason ───

    public function testVerdictReasonsWithholdHostsPortsAndCredentials(): void
    {
        $host = 'sekret-staging-9z.proxy.rlwy.net';
        $user = 'dbuser9z';
        $pass = 'p4ssw0rd9z';
        $port = 54329;
        $rawUrl = 'mysql://' . $user . ':' . $pass . '@' . $host . ':' . $port . '/railway';

        $urlAuthority = DbHostProvenance::parseAuthorityFromUrl($rawUrl);
        $this->assertNotNull($urlAuthority);

        // Exercise PASS, host-mismatch, port-mismatch, and both indeterminate reasons.
        $reasons = [
            DbHostProvenance::verdict($urlAuthority, $urlAuthority + ['source' => 'MYSQL_PUBLIC_URL'])['reason'],
            DbHostProvenance::verdict($urlAuthority, ['host' => 'other.rlwy.net', 'port' => $port, 'source' => 'MYSQL_PUBLIC_URL'])['reason'],
            DbHostProvenance::verdict($urlAuthority, ['host' => $host, 'port' => 9999, 'source' => 'MYSQL_PUBLIC_URL'])['reason'],
            DbHostProvenance::verdict(null, null)['reason'],
            DbHostProvenance::verdict($urlAuthority, null)['reason'],
        ];

        foreach ($reasons as $reason) {
            $this->assertStringNotContainsString($host, $reason, 'a host value must never appear in a verdict reason.');
            $this->assertStringNotContainsString($user, $reason, 'a username must never appear in a verdict reason.');
            $this->assertStringNotContainsString($pass, $reason, 'a password must never appear in a verdict reason.');
            $this->assertStringNotContainsString((string) $port, $reason, 'a port value must never appear in a verdict reason.');
            $this->assertStringNotContainsString($rawUrl, $reason, 'the raw URL must never appear in a verdict reason.');
        }
        // The failing reasons carry explicit value-withheld phrasing.
        $this->assertStringContainsString('withheld', $reasons[1]);
        $this->assertStringContainsString('withheld', $reasons[2]);
    }

    // ── AC4.6: the CLI shell never routes a secret to output ───────────────────

    public function testPreflightCliNeverRoutesTheUrlOrTokenToOutput(): void
    {
        $source = file_get_contents(__DIR__ . '/../scripts/preflight-db-host.php');
        $this->assertIsString($source);

        $outputSinks = ['fwrite(STDOUT', 'fwrite(STDERR', 'echo ', 'print ', 'print(', 'preflight_fail(', 'preflight_ok(', 'var_dump', 'var_export'];
        foreach (['$dbUrl', '$railwayToken', '$body', '$payload'] as $secretVar) {
            foreach (explode("\n", $source) as $lineNo => $line) {
                if (!str_contains($line, $secretVar)) {
                    continue;
                }
                foreach ($outputSinks as $sink) {
                    $this->assertStringNotContainsString(
                        $sink,
                        $line,
                        "scripts/preflight-db-host.php line " . ($lineNo + 1) . " routes {$secretVar} to an output sink ({$sink}) -- "
                        . 'the URL, the token, and the raw Railway body/payload must never be echoed.'
                    );
                }
            }
        }
        // The script's own error phrasing withholds values.
        $this->assertStringContainsString('withheld', $source, 'the CLI must use value-withheld error phrasing.');
    }
}
