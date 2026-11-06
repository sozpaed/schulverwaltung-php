CREATE TABLE totp_wiederherstellungscodes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    benutzer_id BIGINT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    verwendet_am DATETIME NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_totp_codes_benutzer (benutzer_id, verwendet_am),
    CONSTRAINT fk_totp_codes_benutzer FOREIGN KEY (benutzer_id)
        REFERENCES app_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_protokoll (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    administrator_id BIGINT UNSIGNED NULL,
    administrator_name VARCHAR(150) NOT NULL,
    benutzer_id BIGINT UNSIGNED NULL,
    benutzername VARCHAR(150) NOT NULL,
    aktion VARCHAR(32) NOT NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_admin_protokoll_erstellt (erstellt_am),
    CONSTRAINT fk_admin_protokoll_admin FOREIGN KEY (administrator_id)
        REFERENCES app_users (id) ON DELETE SET NULL,
    CONSTRAINT fk_admin_protokoll_benutzer FOREIGN KEY (benutzer_id)
        REFERENCES app_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
