<?php

declare(strict_types=1);

namespace Schulverwaltung;

use DOMDocument;
use DOMElement;
use DOMNode;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class LetterTemplates
{
    public const FORMS = ['keine' => 'Keine', 'foerderstufe' => 'Förderstufe', 'gymnasium' => 'Gymnasium'];
    public const TYPES = ['in_pruefung' => 'In Prüfung', 'angenommen' => 'Angenommen', 'abgelehnt' => 'Abgelehnt', 'warteliste' => 'Warteliste (Ja)'];
    public const PLACEHOLDERS = ['schuelerin', 'schulname', 'klasse', 'erziehungsberechtigter_nachname', 'erziehungsberechtigter_anrede'];

    public static function checkKey(mixed $form, mixed $type): void
    {
        if (!is_string($form) || !isset(self::FORMS[$form]) || !is_string($type) || !isset(self::TYPES[$type])) {
            throw new InvalidArgumentException('Ungültige Vorlagenauswahl.');
        }
    }

    public static function initialize(PDO $database): void
    {
        $find = $database->prepare('SELECT 1 FROM serienbriefvorlagen WHERE schulform = :form AND briefart = :type');
        $insert = $database->prepare('INSERT INTO serienbriefvorlagen (schulform, briefart, betreff, inhalt) VALUES (:form, :type, :subject, :body)');
        $database->beginTransaction();
        try {
            foreach (self::FORMS as $form => $label) {
                foreach (self::TYPES as $type => $typeLabel) {
                    $find->execute(['form' => $form, 'type' => $type]);
                    if ($find->fetchColumn() !== false) {
                        continue;
                    }
                    $filters = AdmissionLetters::validateFilters($form, $type === 'warteliste' ? 'warteliste' : 'status', $type === 'warteliste' ? 'ja' : $type);
                    $content = AdmissionLetters::letterContent($filters, '{{schuelerin}}', '{{schulname}}');
                    $paragraphs = array_map(
                        static fn (string $text): string => '<p>' . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</p>',
                        $content['paragraphs']
                    );
                    $insert->execute(['form' => $form, 'type' => $type, 'subject' => $content['subject'], 'body' => implode('', $paragraphs)]);
                }
            }
            $database->commit();
        } catch (\Throwable $exception) {
            $database->rollBack();
            throw $exception;
        }
    }

    public static function get(PDO $database, string $form, string $type): array
    {
        self::checkKey($form, $type);
        $statement = $database->prepare('SELECT betreff, inhalt, version FROM serienbriefvorlagen WHERE schulform = :form AND briefart = :type');
        $statement->execute(['form' => $form, 'type' => $type]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Die Serienbriefvorlage fehlt. Bitte die Vorlageninitialisierung ausführen.');
        }
        return $row;
    }

    public static function save(PDO $database, string $form, string $type, string $subject, string $body, int $version): bool
    {
        self::checkKey($form, $type);
        self::validate($subject, $body);
        $statement = $database->prepare(
            'UPDATE serienbriefvorlagen SET betreff = :subject, inhalt = :body, version = version + 1
             WHERE schulform = :form AND briefart = :type AND version = :version'
        );
        $statement->execute(['subject' => $subject, 'body' => $body, 'form' => $form, 'type' => $type, 'version' => $version]);
        return $statement->rowCount() === 1;
    }

    public static function validate(string $subject, string $body): void
    {
        if (trim($subject) === '' || strlen($subject) > 500 || preg_match('//u', $subject) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $subject)) {
            throw new InvalidArgumentException('Der Betreff muss gültigen Text enthalten und darf höchstens 500 Bytes lang sein.');
        }
        if (trim($body) === '' || strlen($body) > 30000 || preg_match('//u', $body) !== 1) {
            throw new InvalidArgumentException('Der Brieftext ist leer, ungültig oder zu lang.');
        }
        self::validatePlaceholders($subject);
        $document = self::parse($body);
        if (trim($document->documentElement->textContent) === '') {
            throw new InvalidArgumentException('Der Brieftext darf nicht leer sein.');
        }
        self::validatePlaceholders($document->documentElement->textContent);
    }

    private static function validatePlaceholders(string $text): void
    {
        $remaining = preg_replace_callback('/\{\{([^{}]*)\}\}/u', static function (array $match): string {
            if (!in_array($match[1], self::PLACEHOLDERS, true)) {
                throw new InvalidArgumentException('Unbekannter Platzhalter: ' . $match[0]);
            }
            return '';
        }, $text);
        if ($remaining === null || str_contains($remaining, '{{') || str_contains($remaining, '}}')) {
            throw new InvalidArgumentException('Ein Platzhalter ist unvollständig oder ungültig.');
        }
    }

    public static function parse(string $body): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $ok = $document->loadXML('<?xml version="1.0" encoding="UTF-8"?><root>' . $body . '</root>', LIBXML_NONET);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }
        if (!$ok) {
            throw new InvalidArgumentException('Die Formatierung des Brieftexts ist ungültig.');
        }
        self::checkNodes($document->documentElement, true);
        return $document;
    }

    private static function checkNodes(DOMNode $node, bool $root): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                self::validatePlaceholders($child->textContent);
                continue;
            }
            if (!$child instanceof DOMElement || $child->hasAttributes()
                || !in_array($child->tagName, ['p', 'br', 'strong', 'em'], true)
                || ($child->tagName === 'p' && !$root)
                || ($child->tagName === 'br' && $child->hasChildNodes())) {
                throw new InvalidArgumentException('Nur Absätze, Zeilenumbrüche, Fett und Kursiv sind erlaubt.');
            }
            self::checkNodes($child, false);
        }
    }

    public static function replace(string $text, array $values): string
    {
        $replacements = [];
        foreach (self::PLACEHOLDERS as $name) {
            $replacements['{{' . $name . '}}'] = $values[$name];
        }
        return strtr($text, $replacements);
    }
}
