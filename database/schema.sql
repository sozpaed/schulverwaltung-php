CREATE TABLE schuleinstellungen (
    id TINYINT UNSIGNED NOT NULL,
    school_name VARCHAR(150) NOT NULL DEFAULT '',
    head_name VARCHAR(150) NOT NULL DEFAULT '',
    street VARCHAR(200) NOT NULL DEFAULT '',
    postal_code VARCHAR(20) NOT NULL DEFAULT '',
    city VARCHAR(100) NOT NULL DEFAULT '',
    phone VARCHAR(50) NOT NULL DEFAULT '',
    email VARCHAR(254) NOT NULL DEFAULT '',
    website VARCHAR(200) NOT NULL DEFAULT '',
    logo_data MEDIUMTEXT NULL,
    logo_type VARCHAR(4) NULL,
    PRIMARY KEY (id),
    CONSTRAINT ck_schuleinstellungen_id CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_superuser TINYINT(1) NOT NULL DEFAULT 0,
    can_export TINYINT(1) NOT NULL DEFAULT 0,
    can_delete_catalogs TINYINT(1) NOT NULL DEFAULT 0,
    can_delete_students TINYINT(1) NOT NULL DEFAULT 0,
    sitzungs_version INT UNSIGNED NOT NULL DEFAULT 0,
    totp_secret VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    totp_last_counter BIGINT UNSIGNED NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aktualisiert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_app_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE grundschulen (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(200) NOT NULL,
    ort VARCHAR(100) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY ix_grundschulen_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE marketing_kanaele (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    PRIMARY KEY (id),
    KEY ix_marketing_kanaele_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE religionen (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL,
    PRIMARY KEY (id),
    KEY ix_religionen_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staatsangehoerigkeiten (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staatsangehoerigkeiten_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE schueler (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vorname VARCHAR(100) NOT NULL,
    nachname VARCHAR(100) NOT NULL,
    geburtsdatum DATE NULL,
    strasse VARCHAR(200) NOT NULL,
    plz CHAR(5) NULL,
    ort VARCHAR(100) NOT NULL,
    geburtsort VARCHAR(100) NOT NULL,
    religion_id BIGINT UNSIGNED NULL,
    staatsangehoerigkeit_id BIGINT UNSIGNED NULL,
    grundschule_id BIGINT UNSIGNED NULL,
    anzahl_geschwister INT UNSIGNED NOT NULL DEFAULT 0,
    davon_an_der_marienschule INT UNSIGNED NOT NULL DEFAULT 0,
    freundin VARCHAR(100) NOT NULL DEFAULT '',
    deutsch TINYINT UNSIGNED NULL,
    mathematik TINYINT UNSIGNED NULL,
    sachkunde TINYINT UNSIGNED NULL,
    durchschnitt DECIMAL(4,2) AS (
        CASE
            WHEN deutsch IS NULL AND mathematik IS NULL AND sachkunde IS NULL THEN NULL
            ELSE ROUND(
                (COALESCE(deutsch, 0) + COALESCE(mathematik, 0) + COALESCE(sachkunde, 0)) /
                ((deutsch IS NOT NULL) + (mathematik IS NOT NULL) + (sachkunde IS NOT NULL)),
                2
            )
        END
    ) STORED,
    av TINYINT UNSIGNED NULL,
    sv TINYINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'in_pruefung',
    schulform VARCHAR(20) NOT NULL DEFAULT '',
    klasse VARCHAR(10) NOT NULL DEFAULT '',
    warteliste TINYINT(1) NOT NULL DEFAULT 0,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aktualisiert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_schueler_name (nachname, vorname),
    KEY ix_schueler_klasse (klasse),
    KEY ix_schueler_status (status),
    CONSTRAINT ck_schueler_plz CHECK (plz REGEXP '^[0-9]{5}$'),
    CONSTRAINT ck_schueler_geschwister CHECK (davon_an_der_marienschule <= anzahl_geschwister),
    CONSTRAINT ck_schueler_deutsch CHECK (deutsch IS NULL OR deutsch BETWEEN 1 AND 6),
    CONSTRAINT ck_schueler_mathematik CHECK (mathematik IS NULL OR mathematik BETWEEN 1 AND 6),
    CONSTRAINT ck_schueler_sachkunde CHECK (sachkunde IS NULL OR sachkunde BETWEEN 1 AND 6),
    CONSTRAINT ck_schueler_av CHECK (av IS NULL OR av BETWEEN 1 AND 6),
    CONSTRAINT ck_schueler_sv CHECK (sv IS NULL OR sv BETWEEN 1 AND 6),
    CONSTRAINT ck_schueler_status CHECK (status IN ('abgelehnt', 'angenommen', 'in_pruefung', 'verzicht')),
    CONSTRAINT ck_schueler_schulform CHECK (schulform IN ('', 'foerderstufe', 'gymnasium')),
    CONSTRAINT ck_schueler_klasse CHECK (klasse IN ('', '5a', '5b', '5c', '5d', '5e')),
    CONSTRAINT fk_schueler_religion_restrict FOREIGN KEY (religion_id)
        REFERENCES religionen (id) ON DELETE RESTRICT,
    CONSTRAINT fk_schueler_staatsangehoerigkeit FOREIGN KEY (staatsangehoerigkeit_id)
        REFERENCES staatsangehoerigkeiten (id) ON DELETE RESTRICT,
    CONSTRAINT fk_schueler_grundschule_restrict FOREIGN KEY (grundschule_id)
        REFERENCES grundschulen (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE erziehungsberechtigte (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    schueler_id BIGINT UNSIGNED NOT NULL,
    vorname VARCHAR(100) NOT NULL,
    nachname VARCHAR(100) NOT NULL,
    geburtsdatum DATE NULL,
    religion_id BIGINT UNSIGNED NULL,
    staatsangehoerigkeit_id BIGINT UNSIGNED NULL,
    religion_wie_schuelerin TINYINT(1) NOT NULL DEFAULT 0,
    staatsangehoerigkeit_wie_schuelerin TINYINT(1) NOT NULL DEFAULT 0,
    beziehung VARCHAR(20) NOT NULL,
    erziehungsberechtigt TINYINT(1) NOT NULL DEFAULT 1,
    strasse VARCHAR(200) NOT NULL DEFAULT '',
    plz CHAR(5) NULL,
    ort VARCHAR(100) NOT NULL DEFAULT '',
    adresse_wie_schuelerin TINYINT(1) NOT NULL DEFAULT 0,
    telefon VARCHAR(50) NOT NULL DEFAULT '',
    mobil VARCHAR(50) NOT NULL DEFAULT '',
    email VARCHAR(254) NOT NULL DEFAULT '',
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aktualisiert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_erziehungsberechtigte_name (nachname, vorname),
    KEY ix_erziehungsberechtigte_schueler (schueler_id),
    KEY ix_erziehungsberechtigte_email (email),
    CONSTRAINT ck_erziehungsberechtigte_plz CHECK (plz IS NULL OR plz REGEXP '^[0-9]{5}$'),
    CONSTRAINT ck_erziehungsberechtigte_beziehung
        CHECK (beziehung IN ('mutter', 'vater', 'vormund', 'pflegeeltern', 'sonstiges')),
    CONSTRAINT fk_erziehungsberechtigte_schueler_restrict FOREIGN KEY (schueler_id)
        REFERENCES schueler (id) ON DELETE RESTRICT,
    CONSTRAINT fk_erziehungsberechtigte_religion_restrict FOREIGN KEY (religion_id)
        REFERENCES religionen (id) ON DELETE RESTRICT,
    CONSTRAINT fk_erziehungsberechtigte_staatsangehoerigkeit_restrict FOREIGN KEY (staatsangehoerigkeit_id)
        REFERENCES staatsangehoerigkeiten (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gespraeche (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    schueler_id BIGINT UNSIGNED NOT NULL,
    protokollant_id BIGINT UNSIGNED NULL,
    datum DATETIME NOT NULL,
    gewuenschte_schulform VARCHAR(30) NOT NULL,
    offenheit_christlicher_ru VARCHAR(20) NOT NULL DEFAULT '',
    anwesend_ausser_tochter VARCHAR(20) NOT NULL,
    erstkontakt_tdot TINYINT(1) NOT NULL DEFAULT 0,
    erstkontakt_forscherinnennachmittag TINYINT(1) NOT NULL DEFAULT 0,
    erstkontakt_workshop_jakoby TINYINT(1) NOT NULL DEFAULT 0,
    hobbys_lieblingsfaecher_ags TEXT NOT NULL,
    warum_diese_schule TEXT NOT NULL,
    kirchliche_sozialisation TEXT NOT NULL,
    sonstiges TEXT NOT NULL,
    foerderbedarf_lrs TINYINT(1) NOT NULL DEFAULT 0,
    foerderbedarf_dyskalkulie TINYINT(1) NOT NULL DEFAULT 0,
    foerderbedarf_tha TINYINT(1) NOT NULL DEFAULT 0,
    elternengagement TEXT NOT NULL,
    aufnahmeempfehlung VARCHAR(15) NOT NULL DEFAULT '',
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aktualisiert_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_gespraeche_datum (datum),
    KEY ix_gespraeche_schueler_datum (schueler_id, datum),
    CONSTRAINT ck_gespraeche_schulform
        CHECK (gewuenschte_schulform IN ('gymnasium', 'foerderstufe_realschule', 'unklar')),
    CONSTRAINT ck_gespraeche_offenheit
        CHECK (offenheit_christlicher_ru IN ('', 'katholisch', 'evangelisch', 'nein')),
    CONSTRAINT ck_gespraeche_anwesend
        CHECK (anwesend_ausser_tochter IN ('mutter', 'vater', 'mutter_vater', 'sonstige')),
    CONSTRAINT ck_gespraeche_empfehlung
        CHECK (aufnahmeempfehlung IN ('', 'ja', 'vielleicht', 'nein')),
    CONSTRAINT fk_gespraeche_schueler FOREIGN KEY (schueler_id)
        REFERENCES schueler (id) ON DELETE RESTRICT,
    CONSTRAINT fk_gespraeche_protokollant FOREIGN KEY (protokollant_id)
        REFERENCES app_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gespraech_marketing_kanaele (
    gespraech_id BIGINT UNSIGNED NOT NULL,
    marketing_kanal_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (gespraech_id, marketing_kanal_id),
    CONSTRAINT fk_gmk_gespraech FOREIGN KEY (gespraech_id)
        REFERENCES gespraeche (id) ON DELETE CASCADE,
    CONSTRAINT fk_gmk_marketing_kanal_restrict FOREIGN KEY (marketing_kanal_id)
        REFERENCES marketing_kanaele (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE export_protokolle (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    benutzer_id BIGINT UNSIGNED NULL,
    bereich VARCHAR(50) NOT NULL,
    datensaetze INT UNSIGNED NOT NULL,
    format VARCHAR(50) NOT NULL,
    erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_export_protokolle_erstellt_am (erstellt_am),
    CONSTRAINT fk_export_protokolle_benutzer FOREIGN KEY (benutzer_id)
        REFERENCES app_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
