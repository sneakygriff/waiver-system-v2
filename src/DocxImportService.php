<?php
namespace App;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Writer\HTML;

class DocxImportService {
  public function convertDocxToShortcodeHtml(string $docxPath): array {
    Settings::setZipClass(Settings::ZIPARCHIVE);
    $phpWord = IOFactory::load($docxPath, 'Word2007');
    $writer = new HTML($phpWord);
    ob_start(); $writer->save('php://output'); $rawHtml = ob_get_clean();
    // PHPWord emits a full <html><head><body> document; keep only the body's inner
    // content so it isn't nested inside w.php's own document shell.
    if (preg_match('#<body[^>]*>(.*)</body>#is', $rawHtml, $bm)) { $rawHtml = $bm[1]; }
    $html = preg_replace('/\s+/', ' ', $rawHtml);

    $html = preg_replace('/\{\{\s*signature\s*\}\}/i', '[signature]', $html);
    $html = preg_replace_callback('/\{\{\s*text\s*:\s*([a-zA-Z0-9_\-]+)(!)?\s*\}\}/', function($m){
      $key=$m[1]; $req=!empty($m[2])?' required':''; return '[field key="'.$key.'" type="text"'.$req.']';
    }, $html);
    $html = preg_replace_callback('/\{\{\s*textarea\s*:\s*([a-zA-Z0-9_\-]+)(!)?\s*\}\}/', function($m){
      $key=$m[1]; $req=!empty($m[2])?' required':''; return '[field key="'.$key.'" type="textarea"'.$req.']';
    }, $html);
    $html = preg_replace_callback('/\{\{\s*radio\s*:\s*([a-zA-Z0-9_\-]+)(!)?\s*:\s*([^}]+)\}\}/', function($m){
      $key=$m[1]; $req=!empty($m[2])?' required':''; $opts=preg_replace('/\s*\/\s*/','|',trim($m[3]));
      return '[field key="'.$key.'" type="radio" options="'.htmlspecialchars($opts, ENT_QUOTES).'"'.$req.']';
    }, $html);
    // [FK-Tconsent] Optional GDPR/marketing consent checkbox shortcode. Note:
    // no `!` (required) variant is supported here -- this field type is
    // ALWAYS optional (enforced again downstream in
    // WaiverController::normalizeFields regardless of what lands in
    // fields_json), so a trailing "!" is silently ignored rather than parsed.
    $html = preg_replace_callback('/\{\{\s*gdpr_consent\s*:\s*([a-zA-Z0-9_\-]+)\s*\}\}/', function($m){
      $key=$m[1]; return '[field key="'.$key.'" type="gdpr_consent"]';
    }, $html);

    $fields=[];
    if (preg_match_all('/\[field\s+([^\]]+)\]/', $html, $mm)) {
      foreach ($mm[1] as $attr) {
        $a=$this->parseAttributes($attr); if (!isset($a['key'])) continue;
        $key=$a['key']; $type=$a['type']??'text';
        // [FK-Tconsent] Never required, regardless of the source markup.
        $required=($type==='gdpr_consent') ? false : isset($a['required']);
        $f=['key'=>$key,'label'=>ucwords(str_replace('_',' ',$key)),'type'=>$type,'required'=>$required];
        if ($type==='radio' && isset($a['options'])) $f['options']=explode('|',$a['options']);
        $fields[$key]=$f;
      }
    }
    $html=preg_replace('#<script\b[^<]*(?:(?!</script>)<[^<]*)*</script>#i','',$html);
    return ['content_html'=>$html, 'fields_json'=>array_values($fields)];
  }
  private function parseAttributes(string $s): array {
    $out=[];
    $re='/([a-zA-Z0-9_]+)\s*=\s*"([^"]*)"|([a-zA-Z0-9_]+)\s*=\s*\'([^\']*)\'|([a-zA-Z0-9_]+)\s*=\s*([^\s"]+)/';
    if (preg_match_all($re,$s,$m,PREG_SET_ORDER)) {
      foreach ($m as $mm) {
        if (!empty($mm[1])) $out[$mm[1]]=$mm[2];
        elseif (!empty($mm[3])) $out[$mm[3]]=$mm[4];
        elseif (!empty($mm[5])) $out[$mm[5]]=$mm[6];
      }
    }
    if (preg_match('/\brequired\b/',$s)) $out['required']=true;
    return $out;
  }
}
