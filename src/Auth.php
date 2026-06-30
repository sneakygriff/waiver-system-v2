<?php
namespace App;
class Auth {
  public static function ensureLogin(array $cfg, Database $db) {
    session_name($cfg['security']['session_name']); session_start();
    if (!isset($_SESSION['admin_id'])) { header('Location: /admin.php?action=login'); exit; }
  }
  public static function login(array $cfg, Database $db, string $email, string $password): bool {
    session_name($cfg['security']['session_name']); session_start();
    $stmt = $db->pdo()->prepare('SELECT id, password_hash FROM users WHERE email=?'); $stmt->execute([$email]);
    $u = $stmt->fetch();
    if ($u && password_verify($password, $u['password_hash'])) {
      $_SESSION['admin_id'] = $u['id']; $_SESSION['admin_email'] = $email; return true;
    }
    return false;
  }
  public static function logout(array $cfg) { session_name($cfg['security']['session_name']); session_start(); session_destroy(); }
}
