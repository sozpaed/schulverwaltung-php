ALTER TABLE erziehungsberechtigte
    DROP FOREIGN KEY fk_erziehungsberechtigte_schueler,
    ADD CONSTRAINT fk_erziehungsberechtigte_schueler_restrict FOREIGN KEY (schueler_id)
        REFERENCES schueler (id) ON DELETE RESTRICT;

ALTER TABLE app_users
    ADD COLUMN can_delete_students TINYINT(1) NOT NULL DEFAULT 0 AFTER can_delete_catalogs;
