ALTER TABLE schueler
    MODIFY COLUMN geburtsdatum DATE NULL,
    MODIFY COLUMN plz CHAR(5) NULL,
    MODIFY COLUMN staatsangehoerigkeit_id BIGINT UNSIGNED NULL;
