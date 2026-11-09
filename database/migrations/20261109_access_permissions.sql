ALTER TABLE app_users
    ADD COLUMN can_view_maintenance TINYINT(1) NOT NULL DEFAULT 0 AFTER can_edit_letter_templates,
    ADD COLUMN can_view_statistics TINYINT(1) NOT NULL DEFAULT 0 AFTER can_view_maintenance;

UPDATE app_users
SET can_view_maintenance = 1,
    can_view_statistics = can_export;

CREATE TABLE berechtigungsgruppen (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    can_export TINYINT(1) NOT NULL DEFAULT 0,
    can_edit_letter_templates TINYINT(1) NOT NULL DEFAULT 0,
    can_view_maintenance TINYINT(1) NOT NULL DEFAULT 0,
    can_view_statistics TINYINT(1) NOT NULL DEFAULT 0,
    can_delete_catalogs TINYINT(1) NOT NULL DEFAULT 0,
    can_delete_students TINYINT(1) NOT NULL DEFAULT 0,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aktualisiert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_berechtigungsgruppen_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE benutzer_berechtigungsgruppen (
    benutzer_id BIGINT UNSIGNED NOT NULL,
    gruppe_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (benutzer_id, gruppe_id),
    KEY ix_benutzer_berechtigungsgruppen_gruppe (gruppe_id),
    CONSTRAINT fk_benutzer_berechtigungsgruppen_benutzer FOREIGN KEY (benutzer_id)
        REFERENCES app_users (id) ON DELETE CASCADE,
    CONSTRAINT fk_benutzer_berechtigungsgruppen_gruppe FOREIGN KEY (gruppe_id)
        REFERENCES berechtigungsgruppen (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
