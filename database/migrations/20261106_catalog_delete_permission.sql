ALTER TABLE schueler
    DROP FOREIGN KEY fk_schueler_religion,
    DROP FOREIGN KEY fk_schueler_grundschule,
    ADD CONSTRAINT fk_schueler_religion_restrict FOREIGN KEY (religion_id)
        REFERENCES religionen (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_schueler_grundschule_restrict FOREIGN KEY (grundschule_id)
        REFERENCES grundschulen (id) ON DELETE RESTRICT;

ALTER TABLE erziehungsberechtigte
    DROP FOREIGN KEY fk_erziehungsberechtigte_religion,
    DROP FOREIGN KEY fk_erziehungsberechtigte_staatsangehoerigkeit,
    ADD CONSTRAINT fk_erziehungsberechtigte_religion_restrict FOREIGN KEY (religion_id)
        REFERENCES religionen (id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_erziehungsberechtigte_staatsangehoerigkeit_restrict FOREIGN KEY (staatsangehoerigkeit_id)
        REFERENCES staatsangehoerigkeiten (id) ON DELETE RESTRICT;

ALTER TABLE gespraech_marketing_kanaele
    DROP FOREIGN KEY fk_gmk_marketing_kanal,
    ADD CONSTRAINT fk_gmk_marketing_kanal_restrict FOREIGN KEY (marketing_kanal_id)
        REFERENCES marketing_kanaele (id) ON DELETE RESTRICT;

ALTER TABLE app_users
    ADD COLUMN can_delete_catalogs TINYINT(1) NOT NULL DEFAULT 0 AFTER can_export;
