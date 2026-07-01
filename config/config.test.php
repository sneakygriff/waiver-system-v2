<?php
// [F1-fork-erasure] Test-only config: points at the dedicated `waiver_test`
// MySQL schema (never `waiver_db`) so the phpunit micro-harness can freely
// INSERT/DELETE fixture rows without touching real data. Loaded only by
// tests/bootstrap.php — never by the public entrypoints (api.php/admin.php/
// w.php), which always load config/config.php.
//
// Secrets here are deliberately real-looking (not the CHANGE_ME placeholder)
// so a test run never trips Utils::assertNoPlaceholderSecrets() if a test
// ever exercises that path directly.
return [
  'db' => [
    'host' => getenv('WAIVER_TEST_DB_HOST') ?: 'db',
    'port' => (int)(getenv('WAIVER_TEST_DB_PORT') ?: 3306),
    'name' => getenv('WAIVER_TEST_DB_NAME') ?: 'waiver_test',
    'user' => getenv('WAIVER_TEST_DB_USER') ?: 'app',
    'pass' => getenv('WAIVER_TEST_DB_PASS') ?: 'app',
    'charset' => 'utf8mb4'
  ],
  'app' => [
    'base_url' => 'http://localhost:8080',
    'timezone' => 'UTC'
  ],
  'security' => [
    'session_name' => 'waiver_admin_test',
    'password_algo' => PASSWORD_ARGON2ID,
    'api_hmac_secret' => 'test-only-secret-not-a-placeholder-0001',
    'inbound_hmac_keys' => [
      'k1' => 'test-only-secret-not-a-placeholder-0001'
    ]
  ],
  'callback' => [
    'base_url'        => null, // no outbound webhook calls in this micro-harness
    'outbound_secret' => 'test-only-secret-not-a-placeholder-0002',
    'outbound_key_id' => 'k1',
    'evidence_url'    => null,
  ],
  'storage' => [
    'signatures_path' => sys_get_temp_dir() . '/waiver_test_signatures',
    'artifacts_path'  => sys_get_temp_dir() . '/waiver_test_artifacts'
  ]
];
