<?php
namespace App;
use PDO;
class AdminController {
  private Database $db; private array $cfg;
  public function __construct(array $cfg, Database $db){ $this->cfg=$cfg; $this->db=$db; }
  public function listTemplates(): array {
    return $this->db->pdo()->query('SELECT wt.id, wt.name, wt.is_active, wt.created_at, MAX(wtv.version) latest_version FROM waiver_templates wt LEFT JOIN waiver_template_versions wtv ON wtv.template_id=wt.id GROUP BY wt.id ORDER BY wt.created_at DESC')->fetchAll();
  }
  public function createTemplate(string $name, int $userId): int {
    $stmt=$this->db->pdo()->prepare('INSERT INTO waiver_templates (name, is_active, created_by, created_at, updated_at) VALUES (?,1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $stmt->execute([$name,$userId]); return (int)$this->db->pdo()->lastInsertId();
  }
  public function publishVersion(int $templateId, string $title, string $description, string $fieldsJson, int $userId, ?string $contentHtml=null, ?string $printCss=null): int {
    $v=$this->db->pdo()->prepare('SELECT COALESCE(MAX(version),0) v FROM waiver_template_versions WHERE template_id=?'); $v->execute([$templateId]); $next=(int)$v->fetch()['v']+1;
    $stmt=$this->db->pdo()->prepare('INSERT INTO waiver_template_versions (template_id, version, title, description, fields_json, requires_signature, created_by, created_at, content_html, print_css) VALUES (?,?,?,?,?,1,?,UTC_TIMESTAMP(),?,?)');
    $stmt->execute([$templateId,$next,$title,$description,$fieldsJson,$userId,$contentHtml,$printCss]);
    return (int)$this->db->pdo()->lastInsertId();
  }
  public function listWaivers(?string $q=null): array {
    $sql='SELECT wi.id, wi.reservation_id, wi.status, wi.created_at, wi.completed_at, wtv.title FROM waiver_instances wi JOIN waiver_template_versions wtv ON wi.template_version_id=wtv.id';
    if ($q){ $stmt=$this->db->pdo()->prepare($sql.' WHERE wi.reservation_id LIKE ? ORDER BY wi.created_at DESC LIMIT 200'); $stmt->execute(['%'.$q.'%']); return $stmt->fetchAll(); }
    return $this->db->pdo()->query($sql.' ORDER BY wi.created_at DESC LIMIT 200')->fetchAll();
  }
  public function stats(): array {
    $pdo=$this->db->pdo();
    $total=(int)$pdo->query("SELECT COUNT(*) FROM waiver_instances WHERE status='completed'")->fetchColumn();
    $today=(int)$pdo->query("SELECT COUNT(*) FROM waiver_instances WHERE status='completed' AND DATE(completed_at)=UTC_DATE()" )->fetchColumn();
    $last7=(int)$pdo->query("SELECT COUNT(*) FROM waiver_instances WHERE status='completed' AND completed_at>=UTC_TIMESTAMP()-INTERVAL 7 DAY")->fetchColumn();
    $byTpl=$pdo->query("SELECT wt.name template, COUNT(*) cnt FROM waiver_instances wi JOIN waiver_template_versions wtv ON wi.template_version_id=wtv.id JOIN waiver_templates wt ON wtv.template_id=wt.id WHERE wi.status='completed' GROUP BY wt.id, wt.name ORDER BY cnt DESC, wt.name ASC")->fetchAll();
    $byDay=$pdo->query("SELECT DATE(completed_at) day, COUNT(*) cnt FROM waiver_instances WHERE status='completed' AND completed_at>=UTC_DATE()-INTERVAL 14 DAY GROUP BY DATE(completed_at) ORDER BY day ASC")->fetchAll();
    return ['total_completed'=>$total,'completed_today'=>$today,'completed_7d'=>$last7,'by_template'=>$byTpl,'by_day_14'=>$byDay];
  }
  public function responsesByReservation(?int $days=30, ?string $like=null): array {
    $pdo=$this->db->pdo(); $where='wi.reservation_id IS NOT NULL'; $p=[];
    if ($days and $days>0){ $where.=' AND wr.signed_at>=UTC_TIMESTAMP()-INTERVAL ? DAY'; $p[]=$days; }
    if ($like){ $where.=' AND wi.reservation_id LIKE ?'; $p[]='%'.$like.'%'; }
    $sql="SELECT wi.reservation_id, COUNT(wr.id) responses_count, MAX(wr.signed_at) last_signed_at FROM waiver_responses wr JOIN waiver_instances wi ON wi.id=wr.waiver_instance_id WHERE $where GROUP BY wi.reservation_id ORDER BY last_signed_at DESC LIMIT 1000";
    $st=$pdo->prepare($sql); $st->execute($p); return $st->fetchAll();
  }
  public function responsesByGroupToken(?int $days=30, ?string $groupLike=null): array {
    $pdo=$this->db->pdo(); $where='wi.group_token IS NOT NULL'; $p=[];
    if ($days and $days>0){ $where.=' AND wr.signed_at>=UTC_TIMESTAMP()-INTERVAL ? DAY'; $p[]=$days; }
    if ($groupLike){ $where.=' AND wi.group_token LIKE ?'; $p[]='%'.$groupLike.'%'; }
    $sql="SELECT wi.group_token, COUNT(wr.id) responses_count, MAX(wr.signed_at) last_signed_at FROM waiver_responses wr JOIN waiver_instances wi ON wi.id=wr.waiver_instance_id WHERE $where GROUP BY wi.group_token ORDER BY last_signed_at DESC LIMIT 1000";
    $st=$pdo->prepare($sql); $st->execute($p); return $st->fetchAll();
  }
  public function listUnlinkedResponses(?int $days=30, ?string $q=null, ?string $group=null, int $limit=500): array {
    $limit = max(1, min((int)$limit, 5000)); // clamp: negative/zero -> MySQL LIMIT syntax error
    $pdo=$this->db->pdo(); $where='wi.reservation_id IS NULL'; $p=[];
    if ($days and $days>0){ $where.=' AND wr.signed_at>=UTC_TIMESTAMP()-INTERVAL ? DAY'; $p[]=$days; }
    if ($group){ $where.=' AND wi.group_token LIKE ?'; $p[]='%'.$group.'%'; }
    if ($q){ $where.=' AND (wr.signer_full_name LIKE ? OR wi.guest_email LIKE ? OR wtv.title LIKE ?)'; $p[]='%'.$q.'%'; $p[]='%'.$q.'%'; $p[]='%'.$q.'%'; }
    $sql="SELECT wr.id response_id, wr.signed_at, wr.signer_full_name, wi.id instance_id, wi.group_token, wi.guest_name, wi.guest_email, wtv.title template_title FROM waiver_responses wr JOIN waiver_instances wi ON wi.id=wr.waiver_instance_id JOIN waiver_template_versions wtv ON wtv.id=wi.template_version_id WHERE $where ORDER BY wr.signed_at DESC LIMIT ".(int)$limit;
    $st=$pdo->prepare($sql); $st->execute($p); return $st->fetchAll();
  }
}
