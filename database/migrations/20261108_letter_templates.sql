ALTER TABLE app_users
    ADD COLUMN can_edit_letter_templates TINYINT(1) NOT NULL DEFAULT 0 AFTER can_export;

CREATE TABLE serienbriefvorlagen (
    schulform VARCHAR(20) NOT NULL,
    briefart VARCHAR(20) NOT NULL,
    betreff VARCHAR(500) NOT NULL,
    inhalt TEXT NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    aktualisiert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (schulform, briefart),
    CONSTRAINT ck_vorlage_schulform CHECK (schulform IN ('keine', 'foerderstufe', 'gymnasium')),
    CONSTRAINT ck_vorlage_briefart CHECK (briefart IN ('in_pruefung', 'angenommen', 'abgelehnt', 'warteliste'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
