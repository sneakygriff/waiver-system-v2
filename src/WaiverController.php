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
    if(!$template_id){ return ['error'=>'template_id is required']; }
    $v=$this->db->pdo()->prepare('SELECT id, version, fields_json, title FROM waiver_template_versions WHERE template_id=? ORDER BY version DESC LIMIT 1');
    $v->execute([$template_id]); $version=$v->fetch(); if(!$version) return ['error'=>'No published version for template'];
    $token=Utils::randomToken(32);
    $stmt=$this->db->pdo()->prepare('INSERT INTO waiver_instances (template_version_id, reservation_id, group_token, guest_name, guest_email, link_token, status, created_at, updated_at) VALUES (?,?,?,?,?,?,"pending",UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $stmt->execute([$version['id'],$reservation_id,$group_token,$guest_name,$guest_email,$token]);
    $id=(int)$this->db->pdo()->lastInsertId(); $link=rtrim($this->cfg['app']['base_url'],'/').'/w.php?token='.$token;
    $this->audit('instance',$id,'created',['reservation_id'=>$reservation_id,'template_version_id'=>$version['id'],'group_token'=>$group_token]);
    return ['waiver_id'=>$id,'link'=>$link,'group_token'=>$group_token];
  }

  public function renderGuestForm(string $token): array {
    $q=$this->db->pdo()->prepare('SELECT wi.*, wtv.title, wtv.description, wtv.fields_json, wtv.content_html, wtv.print_css FROM waiver_instances wi JOIN waiver_template_versions wtv ON wi.template_version_id=wtv.id WHERE link_token=? LIMIT 1');
    $q->execute([$token]); $row=$q->fetch();
    if(!$row) return ['error'=>'Invalid link']; if($row['status']==='completed') return ['error'=>'This waiver has already been completed.'];
    $fields=json_decode($row['fields_json'],true)??[]; return ['instance'=>$row,'fields'=>$fields];
  }

  public function submitGuestForm(string $token, array $post): array {
    $q=$this->db->pdo()->prepare('SELECT wi.*, wtv.id as version_id, wtv.title, wtv.fields_json, wtv.content_html, wtv.print_css FROM waiver_instances wi JOIN waiver_template_versions wtv ON wi.template_version_id=wtv.id WHERE link_token=? LIMIT 1');
    $q->execute([$token]); $instance=$q->fetch(); if(!$instance) return ['error'=>'Invalid link']; if($instance['status']==='completed') return ['error'=>'Already completed'];
    $fields=json_decode($instance['fields_json'], true)??[]; $answers=[];
    foreach($fields as $f){ $key=$f['key']; $val=$post[$key]??null; if(!empty($f['required']) && ($val===null || $val==='')) return ['error'=>'Missing field: '.$key]; $answers[$key]=$val; }

    $sigData=$post['signature_data']??''; if(!preg_match('#^data:image/png;base64,#',$sigData)) return ['error'=>'Missing signature'];
    $png=base64_decode(substr($sigData,22)); $sigDir=$this->cfg['storage']['signatures_path']; if(!is_dir($sigDir)) @mkdir($sigDir,0775,true);
    $sigFile=$sigDir.'/'.Utils::randomToken(16).'.png'; file_put_contents($sigFile,$png);

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

    $this->db->pdo()->beginTransaction();
    $stmt=$this->db->pdo()->prepare('INSERT INTO waiver_responses (waiver_instance_id, answers_json, signature_png, signer_full_name, signed_at, signer_ip, signer_user_agent, hash_sha256, pdf_path, created_at) VALUES (?,?,?,?,UTC_TIMESTAMP(),?,?,?, ?, UTC_TIMESTAMP())');
    $stmt->execute([$instance['id'], json_encode($answers, JSON_UNESCAPED_UNICODE), $png, $post['full_name']??null, $_SERVER['REMOTE_ADDR']??null, $_SERVER['HTTP_USER_AGENT']??null, $hash, $artifact]);
    $this->db->pdo()->prepare('UPDATE waiver_instances SET status="completed", completed_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$instance['id']]);
    $this->audit('response', $instance['id'], 'submitted', $payload);
    $this->db->pdo()->commit();

    return ['ok'=>true,'artifact'=>$artifact];
  }

  public function linkWaiversToReservation(string $reservationId, array $waiverIds=[], ?string $groupToken=null, bool $includePending=false): array {
    if(!$reservationId) return ['error'=>'reservation_id is required'];
    if(empty($waiverIds) && !$groupToken) return ['error'=>'Provide waiver_ids or group_token'];
    if($groupToken){
      $sql='SELECT id FROM waiver_instances WHERE group_token=?'; if(!$includePending){ $sql.=' AND status="completed"'; }
      $sel=$this->db->pdo()->prepare($sql); $sel->execute([$groupToken]); $ids=array_column($sel->fetchAll(),'id');
    } else {
      $ids=array_values(array_filter(array_map('intval',$waiverIds))); if(!$ids) return ['error'=>'No valid waiver_ids'];
      $in=implode(',',array_fill(0,count($ids),'?')); $sql='SELECT id FROM waiver_instances WHERE id IN ('.$in.')'; if(!$includePending){ $sql.=' AND status="completed"'; }
      $sel=$this->db->pdo()->prepare($sql); $sel->execute($ids); $ids=array_column($sel->fetchAll(),'id');
    }
    if(!$ids) return ['updated'=>0,'ids'=>[]];
    $in=implode(',',array_fill(0,count($ids),'?')); $upd=$this->db->pdo()->prepare('UPDATE waiver_instances SET reservation_id=?, updated_at=UTC_TIMESTAMP() WHERE id IN ('.$in.')'); $upd->execute(array_merge([$reservationId],$ids));
    foreach($ids as $id){ $this->audit('instance',(int)$id,'linked_to_reservation',['reservation_id'=>$reservationId]); }
    return ['updated'=>count($ids),'ids'=>$ids];
  }

  public function audit(string $type, int $id, string $event, array $meta=[]): void {
    $stmt=$this->db->pdo()->prepare('INSERT INTO audit_events (entity_type, entity_id, event, meta_json, created_at) VALUES (?,?,?, ?, UTC_TIMESTAMP())');
    $stmt->execute([$type,$id,$event,json_encode($meta, JSON_UNESCAPED_UNICODE)]);
  }

  // -------- Rendering helpers for formatted templates --------
  private function parseAttrs(string $s): array {
    $out=[]; $re='/([a-zA-Z0-9_]+)\s*=\s*"([^"]*)"|([a-zA-Z0-9_]+)\s*=\s*\'+"'" + r"([^']*)" + "'" + r'|([a-zA-Z0-9_]+)\s*=\s*([^\s"]+)/';
    if (preg_match_all($re,$s,$m,PREG_SET_ORDER)) {
      foreach ($m as $mm){ if(!empty($mm[1])) $out[$mm[1]]=$mm[2]; elseif(!empty($mm[3])) $out[$mm[3]]=$mm[4]; elseif(!empty($mm[5])) $out[$mm[5]]=$mm[6]; }
    }
    if (preg_match('/\brequired\b/',$s)) $out['required']=true; return $out;
  }
  public function renderContentForWeb(string $html, array $fields): string {
    $html=preg_replace_callback('#\[field\s+([^\]]+)\]#', function($m){
      $a=$this->parseAttrs($m[1]); $key=$a['key']??''; $type=$a['type']??'text'; $req=!empty($a['required'])?'required':'';
      if($type==='radio'){ $opts=isset($a['options'])?explode('|',$a['options']):['Yes','No']; $out='<span class="d-inline-block">'; foreach($opts as $o){ $out.='<label class="me-3"><input class="form-check-input me-1" type="radio" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars($o).'" '.$req.'>'.htmlspecialchars($o).'</label>'; } return $out.'</span>'; }
      if($type==='textarea'){ return '<textarea name="'.htmlspecialchars($key).'" class="form-control d-inline-block" style="width:100%; min-height:80px; border:1px solid #ccc;">'</textarea>'; }
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
      $disp=$val!==''?htmlspecialchars($val):'&nbsp;'; return '<span class="inline-line">'.$disp.'</span>';
    }, $html);
    $html=preg_replace_callback('#\[signature(?:\s+[^\]]+)?\]#', function() use ($answers){ if(!empty($answers['_signature_png_base64'])) return '<img style="max-width:300px;border:1px solid #ccc" src="data:image/png;base64,'.htmlspecialchars($answers['_signature_png_base64']).'">'; return '<span class="inline-line">&nbsp;</span>'; }, $html);
    $html=preg_replace_callback('#\[if\s+key="([^"]+)"\s+equals="([^"]+)"\](.*?)\[/if\]#s', function($m) use ($answers){ $key=$m[1]; $eq=$m[2]; $inner=$m[3]; return (isset($answers[$key]) && (string)$answers[$key]===$eq)?$inner:''; }, $html);
    $css='<style>.inline-line{border-bottom:1px solid #000; min-width:220px; display:inline-block;} .checkbox{display:inline-block; border:1px solid #000; width:14px; height:14px; text-align:center; line-height:14px; font-size:12px; margin:0 6px;} p{margin:6px 0}</style>'; if($printCss) $css.='<style>'.$printCss.'</style>'; return $css.$html;
  }
}
