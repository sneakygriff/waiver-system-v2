<?php
namespace App;
use PDO;
class Database {
  private PDO $pdo;
  public function __construct(array $cfg) {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
      $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']);
    $this->pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }
  public function pdo(): PDO { return $this->pdo; }
}
