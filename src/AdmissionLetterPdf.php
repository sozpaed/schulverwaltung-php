<?php

declare(strict_types=1);

namespace Schulverwaltung;

require_once __DIR__ . '/vendor/fpdf/fpdf.php';

use RuntimeException;

final class AdmissionLetterPdf extends \FPDF
{
    private const BRAND_COLOR = [227, 28, 121];

    public function __construct(private readonly array $settings)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->SetMargins(20, 15, 20);
        $this->SetAutoPageBreak(true, 24);
        $this->SetTitle($this->pdfText('Serienbrief - ' . $settings['school_name']));
        $this->SetAuthor($this->pdfText($settings['school_name']));
        $this->SetCreator('Schulverwaltung');
    }

    public function Header(): void
    {
        if ($this->settings['logo_data'] !== null) {
            $type = $this->settings['logo_type'];
            if (!in_array($type, ['PNG', 'JPEG'], true)) {
                throw new RuntimeException('Das gespeicherte Logo hat ein ungültiges Format.');
            }
            $data = base64_decode($this->settings['logo_data'], true);
            $size = $data === false ? false : getimagesizefromstring($data);
            if ($size === false || $size[0] < 1 || $size[1] < 1
                || ($type === 'PNG' && $size[2] !== IMAGETYPE_PNG)
                || ($type === 'JPEG' && $size[2] !== IMAGETYPE_JPEG)) {
                throw new RuntimeException('Das gespeicherte Logo ist ungültig.');
            }
            $scale = min(57 / $size[0], 25 / $size[1]);
            $this->Image('school-logo', 20, 12, $size[0] * $scale, $size[1] * $scale, $type);
        } else {
            $this->SetFont('Arial', 'B', 11);
            $this->SetXY(20, 18);
            $this->Cell(100, 6, $this->pdfText($this->settings['school_name']));
        }
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
        $this->Cell(170, 4, $this->pdfText($this->senderLine()), 0, 1, 'C');
        $contact = array_filter(
            [$this->settings['phone'], $this->settings['email'], $this->settings['website']],
            static fn (string $value): bool => $value !== ''
        );
        $this->Cell(170, 4, $this->pdfText(implode(' · ', $contact)), 0, 0, 'C');
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
            $this->pdfText($this->senderLine()),
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

        $content = AdmissionLetters::letterContent($filters, $recipient['student_name'], $this->settings['school_name']);
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
        $this->Cell(170, 6, $this->pdfText($this->settings['head_name']), 0, 1);
        $this->SetFont('Arial', '', 10);
        $this->Cell(170, 5, $this->pdfText($this->settings['school_name']), 0, 1);
    }

    private function senderLine(): string
    {
        $location = trim($this->settings['postal_code'] . ' ' . $this->settings['city']);
        return implode(' · ', array_filter(
            [$this->settings['school_name'], $this->settings['street'], $location],
            static fn (string $value): bool => $value !== ''
        ));
    }

    protected function _parsepng($file)
    {
        if ($file !== 'school-logo') {
            return parent::_parsepng($file);
        }
        $data = base64_decode($this->settings['logo_data'], true);
        if ($data === false) {
            throw new RuntimeException('Das gespeicherte Logo ist ungültig.');
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Das Logo konnte nicht verarbeitet werden.');
        }
        try {
            if (fwrite($stream, $data) !== strlen($data) || !rewind($stream)) {
                throw new RuntimeException('Das Logo konnte nicht verarbeitet werden.');
            }
            return $this->_parsepngstream($stream, 'Schullogo');
        } finally {
            fclose($stream);
        }
    }

    protected function _parsejpg($file)
    {
        if ($file !== 'school-logo') {
            return parent::_parsejpg($file);
        }
        $data = base64_decode($this->settings['logo_data'], true);
        $image = $data === false ? false : getimagesizefromstring($data);
        if ($image === false || $image[2] !== IMAGETYPE_JPEG) {
            throw new RuntimeException('Das gespeicherte Logo ist ungültig.');
        }
        $colorSpace = !isset($image['channels']) || $image['channels'] === 3
            ? 'DeviceRGB'
            : ($image['channels'] === 4 ? 'DeviceCMYK' : 'DeviceGray');

        return [
            'w' => $image[0],
            'h' => $image[1],
            'cs' => $colorSpace,
            'bpc' => $image['bits'] ?? 8,
            'f' => 'DCTDecode',
            'data' => $data,
        ];
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
