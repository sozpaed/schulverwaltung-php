"""
Gemeinsame Export-Einstellungen für die Admin-Klassen.

- Exportieren dürfen nur Superuser und Mitglieder der Gruppe "Datenexport".
- Formate: XLSX (Excel) und CSV (Semikolon, UTF-8 mit BOM, öffnet in deutschem Excel
  korrekt).
- Jeder Export wird protokolliert (Benutzer, Bereich, Anzahl Datensätze, Format).
"""

import logging

from import_export.formats import base_formats

EXPORT_GRUPPE = 'Datenexport'

logger = logging.getLogger('export')


class CSVDeutsch(base_formats.CSV):
    """CSV für deutsches Excel: Semikolon als Trenner, UTF-8 mit BOM."""

    def export_data(self, dataset, **kwargs):
        kwargs.setdefault('delimiter', ';')
        return '\ufeff' + super().export_data(dataset, **kwargs)


class ExportNurGruppe:
    export_formats = [base_formats.XLSX, CSVDeutsch]

    def has_export_permission(self, request):
        benutzer = request.user
        if not (benutzer.is_active and benutzer.is_staff):
            return False
        return (
            benutzer.is_superuser
            or benutzer.groups.filter(name=EXPORT_GRUPPE).exists()
        )

    def get_export_data(self, file_format, request, queryset, **kwargs):
        daten = super().get_export_data(file_format, request, queryset, **kwargs)
        logger.info(
            'EXPORT benutzer=%s bereich=%s datensaetze=%s format=%s',
            request.user.get_username(),
            self.model._meta.model_name,
            queryset.count(),
            file_format.get_title(),
        )
        return daten