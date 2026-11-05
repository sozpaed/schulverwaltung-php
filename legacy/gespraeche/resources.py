"""
Beschreibung: Import-/Export-Ressourcen für Schüler, Erziehungsberechtigte
               und Aufnahmegespräche einschließlich Auflösung von Katalogwerten.
Urheber: Christian Leeser mit KI Claude, Gemini und Copilot
Version: 0.3.0
Datei: resources.py
Projekt/Repository: https://github.com/sozpaed/schulverwaltung
Lizenz: Creative Commons Namensnennung-Weitergabe unter gleichen Bedingungen
        4.0 International (CC BY-SA 4.0)
        https://creativecommons.org/licenses/by-sa/4.0/
Datum: 01.11.2026
Sicherheitshinweis: Die Anwendung verarbeitet personenbezogene Schüler- und
                    Kontaktdaten. Echtdaten und Exporte vor unberechtigtem
                    Zugriff schützen und nicht in öffentliche Repositories
                    oder Beispieldateien übernehmen.
"""

import tablib
from import_export import resources, fields, widgets
from import_export.formats import base_formats
from django.core.exceptions import ValidationError
from .models import (
    Erziehungsberechtigter,
    Gespraech,
    Grundschule,
    Religion,
    Schueler,
    Staatsangehoerigkeit,
)

class GrundschuleWidget(widgets.ForeignKeyWidget):
    """Löst einen importierten Schulnamen in einen Katalogeintrag auf.

    Der Fremdschlüssel-Import verwendet den Namen statt einer Datenbank-ID,
    die zwischen verschiedenen Installationen nicht stabil sein muss.
    """
    def clean(self, value, row=None, *args, **kwargs):
        """Liefert ``None`` für leere Werte, andernfalls den passenden Eintrag.

        Unbekannte Grundschulnamen werden bei Bedarf neu angelegt. ``strip()``
        entfernt nur Rand-Leerraum, damit versehentliche Leerzeichen keinen
        separaten Katalogeintrag erzeugen.
        """
        if not value:
            return None
        # get_or_create gibt sowohl den gefundenen als auch den neu erstellten
        # Datensatz zurück; für den Import wird das Objekt benötigt.
        obj, created = self.model.objects.get_or_create(name=value.strip())
        return obj

class ReligionWidget(widgets.ForeignKeyWidget):
    """Ordnet importierte Religionsnamen dem zentralen Religionskatalog zu."""
    def clean(self, value, row=None, *args, **kwargs):
        """Behandelt leere Werte als nicht gesetzte Religion und legt fehlende Einträge an."""
        if not value:
            return None
        obj, created = self.model.objects.get_or_create(name=value.strip())
        return obj


class StaatsangehoerigkeitWidget(widgets.ForeignKeyWidget):
    """Löst einen importierten Staatsangehörigkeitsnamen im Katalog auf."""

    def clean(self, value, row=None, *args, **kwargs):
        """Legt unbekannte Werte an und behandelt leere Angaben als nicht gesetzt."""
        if not value:
            return None
        obj, created = self.model.objects.get_or_create(name=value.strip())
        return obj


class SchuelerStaatsangehoerigkeitWidget(StaatsangehoerigkeitWidget):
    """Verwendet bei leeren Schüler-Importwerten den Standard Deutschland."""

    def clean(self, value, row=None, *args, **kwargs):
        if not value:
            value = 'Deutschland'
        return super().clean(value, row, *args, **kwargs)


class SemicolonCSV(base_formats.CSV):
    """Liest Excel-CSV mit Semikolon-Trennzeichen und optionalem UTF-8-BOM."""

    def get_title(self):
        return 'CSV (Semikolon)'

    def create_dataset(self, in_stream, **kwargs):
        if isinstance(in_stream, bytes):
            in_stream = in_stream.decode(self.encoding or 'utf-8-sig')
        if in_stream.startswith('\ufeff'):
            in_stream = in_stream[1:]
        return tablib.import_set(
            in_stream,
            format='csv',
            delimiter=';',
            **kwargs,
        )

    def can_export(self):
        return False


class NewSchuelerSemicolonCSV(SemicolonCSV):
    """CSV-Auswahl für ausschließlich neue Schülerdatensätze."""

    schueler_import_mode = 'create_only'

    def get_title(self):
        return 'CSV (Semikolon) – neue Schülerinnen ohne ID'


class UpdateSchuelerSemicolonCSV(SemicolonCSV):
    """CSV-Auswahl für Updates, die ausschließlich vorhandene IDs ändern."""

    schueler_import_mode = 'update_only'

    def get_title(self):
        return 'CSV (Semikolon) – Schülerinnen aktualisieren mit ID'


class NewErziehungsberechtigterSemicolonCSV(SemicolonCSV):
    """CSV-Auswahl für ausschließlich neue Erziehungsberechtigte."""

    erziehungsberechtigter_import_mode = 'create_only'

    def get_title(self):
        return 'CSV (Semikolon) – neue Erziehungsberechtigte ohne ID'


class UpdateErziehungsberechtigterSemicolonCSV(SemicolonCSV):
    """CSV-Auswahl für Updates vorhandener Erziehungsberechtigter."""

    erziehungsberechtigter_import_mode = 'update_only'

    def get_title(self):
        return 'CSV (Semikolon) – Erziehungsberechtigte aktualisieren mit ID'


class SchuelerResource(resources.ModelResource):
    """Definiert die Import-/Exportfelder für Schülerdatensätze."""
    # Fremdschlüssel werden in Dateien über den lesbaren Namen ausgetauscht,
    # nicht über die installationsabhängige numerische Primärschlüssel-ID.
    grundschule = fields.Field(
        column_name='grundschule',
        attribute='grundschule',
        widget=GrundschuleWidget(Grundschule, 'name')
    )
    
    religion = fields.Field(
        column_name='religion',
        attribute='religion',
        widget=ReligionWidget(Religion, 'name')
    )

    staatsangehoerigkeit = fields.Field(
        column_name='staatsangehoerigkeit',
        attribute='staatsangehoerigkeit',
        widget=SchuelerStaatsangehoerigkeitWidget(Staatsangehoerigkeit, 'name')
    )

    class Meta:
        # Verknüpft die Import-/Exportdefinition mit dem Schüler-Modell.
        model = Schueler
        # Unveränderte Zeilen werden beim Import übersprungen; das Ergebnis
        # kann diese übersprungenen Datensätze trotzdem gesondert ausweisen.
        skip_unchanged = True
        report_skipped = True

    def get_or_init_instance(self, instance_loader, row):
        """Erzwingt je ausgewähltem CSV-Format Neuanlage oder reines Update."""
        mode = getattr(self, 'schueler_import_mode', None)
        row_id = str(row.get('id') or '').strip()

        if mode == 'create_only':
            if row_id:
                raise ValidationError(
                    'Im Modus für neue Schülerinnen darf die CSV keine ID enthalten.'
                )
            return self.init_instance(row), True

        if mode == 'update_only':
            if not row_id:
                raise ValidationError(
                    'Zum Aktualisieren muss jede Zeile eine vorhandene ID enthalten.'
                )
            instance = self.get_instance(instance_loader, row)
            if instance is None:
                raise ValidationError(
                    f'Es gibt keine Schülerin mit der ID „{row_id}“; '
                    'im Update-Modus werden keine neuen Datensätze angelegt.'
                )
            return instance, False

        return super().get_or_init_instance(instance_loader, row)

    def before_import(self, dataset, **kwargs):
        """Merkt sich den gewählten Schülerimportmodus für die Zeilenverarbeitung."""
        self.schueler_import_mode = kwargs.get('schueler_import_mode')

class ErziehungsberechtigterResource(resources.ModelResource):
    """Definiert Import und Export der Erziehungsberechtigten."""
    # Importdateien enthalten den Religionsnamen; das Widget löst ihn wie beim
    # Schülerimport in einen Katalogeintrag auf.
    religion = fields.Field(
        column_name='religion',
        attribute='religion',
        widget=ReligionWidget(Religion, 'name')
    )

    staatsangehoerigkeit = fields.Field(
        column_name='staatsangehoerigkeit',
        attribute='staatsangehoerigkeit',
        widget=StaatsangehoerigkeitWidget(Staatsangehoerigkeit, 'name')
    )

    class Meta:
        model = Erziehungsberechtigter
        # Verhindert unnötige Updates und ermöglicht die Anzeige übersprungener Zeilen.
        skip_unchanged = True
        report_skipped = True

    def get_or_init_instance(self, instance_loader, row):
        """Erzwingt je ausgewähltem CSV-Format Neuanlage oder reines Update."""
        mode = getattr(self, 'erziehungsberechtigter_import_mode', None)
        row_id = str(row.get('id') or '').strip()

        if mode == 'create_only':
            if row_id:
                raise ValidationError(
                    'Im Modus für neue Erziehungsberechtigte darf die CSV keine ID enthalten.'
                )
            return self.init_instance(row), True

        if mode == 'update_only':
            if not row_id:
                raise ValidationError(
                    'Zum Aktualisieren muss jede Zeile eine vorhandene ID enthalten.'
                )
            instance = self.get_instance(instance_loader, row)
            if instance is None:
                raise ValidationError(
                    f'Es gibt keinen Erziehungsberechtigten mit der ID „{row_id}“; '
                    'im Update-Modus werden keine neuen Datensätze angelegt.'
                )
            return instance, False

        return super().get_or_init_instance(instance_loader, row)

    def before_import(self, dataset, **kwargs):
        """Merkt sich den gewählten Importmodus für die Zeilenverarbeitung."""
        self.erziehungsberechtigter_import_mode = kwargs.get(
            'erziehungsberechtigter_import_mode'
        )

class GespraechResource(resources.ModelResource):
    """Definiert Import und Export der Gesprächsprotokolle."""
    class Meta:
        model = Gespraech
        # Bereits identische Gesprächsdatensätze werden nicht erneut geändert.
        skip_unchanged = True
        report_skipped = True