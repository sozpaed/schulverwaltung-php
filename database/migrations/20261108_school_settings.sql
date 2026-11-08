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
