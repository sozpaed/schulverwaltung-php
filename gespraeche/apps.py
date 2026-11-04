"""
Beschreibung: App-Konfiguration der Django-Anwendung "gespraeche".
Urheber: Christian Leeser mit KI Claude, Gemini und Copilot
Version: 0.1.0
Datei: apps.py
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

from django.apps import AppConfig


class GespraecheConfig(AppConfig):
    """Registrierung und sichtbarer Name der App ``gespraeche``.

    Django liest diese Konfiguration beim Laden der installierten Apps aus.
    Der Standardtyp für automatisch erzeugte Primärschlüssel wird hier für
    Modelle dieser App festgelegt.
    """
    default_auto_field = 'django.db.models.BigAutoField'
    # Python-Modulpfad, unter dem Django die Anwendung findet.
    name = 'gespraeche'
    # Lesbarer Name, der beispielsweise in der Admin-Navigation angezeigt wird.
    verbose_name = 'Anmeldeeingaben'
