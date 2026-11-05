ALTER TABLE schueler
    ADD COLUMN durchschnitt DECIMAL(4,2) AS (
        CASE
            WHEN deutsch IS NULL AND mathematik IS NULL AND sachkunde IS NULL THEN NULL
            ELSE ROUND(
                (COALESCE(deutsch, 0) + COALESCE(mathematik, 0) + COALESCE(sachkunde, 0)) /
                ((deutsch IS NOT NULL) + (mathematik IS NOT NULL) + (sachkunde IS NOT NULL)),
                2
            )
        END
    ) PERSISTENT AFTER sachkunde;
