CREATE TABLE login_versuche (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    benutzername VARCHAR(150) NOT NULL,
    benutzer_id BIGINT UNSIGNED NULL,
    ip VARBINARY(16) NOT NULL,
    erfolgreich TINYINT(1) NOT NULL,
    grund VARCHAR(32) NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_login_versuche_benutzername (benutzername, erstellt_am),
    KEY ix_login_versuche_ip (ip, erstellt_am),
    KEY ix_login_versuche_erstellt_am (erstellt_am),
    CONSTRAINT fk_login_versuche_benutzer FOREIGN KEY (benutzer_id)
        REFERENCES app_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
