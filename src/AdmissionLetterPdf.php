<?php

declare(strict_types=1);

namespace Schulverwaltung;

require_once __DIR__ . '/vendor/fpdf/fpdf.php';

use RuntimeException;

final class AdmissionLetterPdf extends \FPDF
{
    private const BRAND_COLOR = [227, 28, 121];

    public function __construct(private readonly string $logoPath)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->SetMargins(20, 15, 20);
        $this->SetAutoPageBreak(true, 24);
        $this->SetTitle('Serienbrief - Marienschule Offenbach');
        $this->SetAuthor('Marienschule Offenbach');
        $this->SetCreator('Schulverwaltung');
    }

    public function Header(): void
    {
        if (!is_file($this->logoPath)) {
            throw new RuntimeException('Das Logo für den Serienbrief wurde nicht gefunden.');
        }

        $this->Image($this->logoPath, 20, 12, 57);
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(...self::BRAND_COLOR);
        $this->SetXY(130, 18);
        $this->Cell(60, 5, $this->pdfText('MUSTER – VOR VERSAND PRÜFEN'), 0, 0, 'R');
        $this->SetDrawColor(...self::BRAND_COLOR);
        $this->SetLineWidth(0.7);
        $this->Line(20, 41, 190, 41);
        $this->SetTextColor(32, 51, 72);
    }

    public function Footer(): void
    {
        $this->SetY(-19);
        $this->SetDrawColor(...self::BRAND_COLOR);
        $this->SetLineWidth(0.35);
        $this->Line(20, $this->GetY(), 190, $this->GetY());
        $this->SetY(-16);
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(
            170,
            4,
            $this->pdfText('Marienschule Offenbach · Ahornstraße 33 · 63071 Offenbach am Main · 069 / 85 10 81'),
            0,
            1,
            'C'
        );
        $this->Cell(
            170,
            4,
            $this->pdfText('verwaltung@marienschule-offenbach.de · www.marienschule-offenbach.de'),
            0,
            0,
            'C'
        );
    }

    public function addLetter(array $recipient, array $filters): void
    {
        $this->AddPage();
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(100, 116, 139);
        $this->SetXY(20, 45);
        $this->Cell(
            170,
            4,
            $this->pdfText('Marienschule Offenbach · Ahornstraße 33 · 63071 Offenbach am Main'),
            0,
            1
        );

        $this->SetFont('Arial', '', 11);
        $this->SetTextColor(32, 51, 72);
        $this->SetXY(20, 59);
        foreach ($recipient['recipients'] as $name) {
            $this->Cell(90, 5, $this->pdfText($name), 0, 1);
        }
        foreach ($recipient['address'] as $line) {
            if ($line !== '') {
                $this->Cell(90, 5, $this->pdfText($line), 0, 1);
            }
        }

        $this->SetFont('Arial', '', 10);
        $this->SetXY(130, 64);
        $this->Cell(60, 5, date('d.m.Y'), 0, 0, 'R');

        $content = AdmissionLetters::letterContent($filters, $recipient['student_name']);
        $subjectY = max($this->GetY() + 14, 99);
        $this->SetXY(20, $subjectY);
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(...self::BRAND_COLOR);
        $this->MultiCell(170, 7, $this->pdfText($content['subject']));

        $this->Ln(7);
        $this->SetFont('Arial', '', 11);
        $this->SetTextColor(32, 51, 72);
        $this->MultiCell(
            170,
            6,
            $this->pdfText('Sehr geehrte Erziehungsberechtigte,')
        );
        $this->Ln(4);
        foreach ($content['paragraphs'] as $paragraph) {
            $this->MultiCell(170, 6, $this->pdfText($paragraph));
            $this->Ln(4);
        }
        $this->Cell(170, 6, $this->pdfText('Mit freundlichen Grüßen'), 0, 1);
        $this->Ln(8);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(170, 6, $this->pdfText('Markus Tumbrink'), 0, 1);
        $this->SetFont('Arial', '', 10);
        $this->Cell(170, 5, $this->pdfText('Marienschule Offenbach'), 0, 1);
    }

    private function pdfText(string $text): string
    {
        $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($converted === false) {
            throw new RuntimeException('Ein Text konnte nicht für das PDF umgewandelt werden.');
        }

        return $converted;
    }
}
