<?php
namespace App;
class PdfService {
  private string $artifactsPath;
  public function __construct(string $artifactsPath) {
    $this->artifactsPath = $artifactsPath; if (!is_dir($this->artifactsPath)) @mkdir($this->artifactsPath, 0775, true);
  }
  public function generate(string $html, string $basename): string {
    $pdfFile = $this->artifactsPath . '/' . $basename . '.pdf';
    $htmlFile = $this->artifactsPath . '/' . $basename . '.html';
    try {
      if (class_exists('\Dompdf\Dompdf')) {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html); $dompdf->setPaper('A4', 'portrait'); $dompdf->render();
        file_put_contents($pdfFile, $dompdf->output()); return $pdfFile;
      }
    } catch (\Throwable $e) { /* fall back */ }
    file_put_contents($htmlFile, $html); return $htmlFile;
  }
}
