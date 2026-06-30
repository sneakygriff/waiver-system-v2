<?php
namespace App;
class Utils {
  public static function nowUtc(): string { return gmdate('Y-m-d H:i:s'); }
  public static function jsonResponse(int $code, $data) {
    http_response_code($code); header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
  }
  public static function hmacValid(string $body, string $secret, ?string $header): bool {
    if (!$header) return false;
    $calc = hash_hmac('sha256', $body, $secret);
    return hash_equals($calc, $header);
  }
  public static function randomToken(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }
}
