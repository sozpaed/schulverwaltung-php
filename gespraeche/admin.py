"""
Beschreibung: Konfiguration der Django-Admin-Oberflächen für Schüler,
               Erziehungsberechtigte, Gespräche und Stammdaten.
Urheber: Christian Leeser mit KI Claude, Gemini und Copilot
Version: 0.3.0
Datei: admin.py
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

from django.contrib import admin
from django import forms
from django.utils.html import format_html
from django.utils import timezone
from django.forms import CheckboxSelectMultiple
from django.db import models
from import_export.admin import ImportExportModelAdmin
from import_export.formats import base_formats

from .export_admin import ExportNurGruppe
from .models import (
    Erziehungsberechtigter,
    Gespraech,
    Grundschule,
    MarketingKanal,
    Religion,
    Schueler,
    Staatsangehoerigkeit,
)
from .resources import (
    ErziehungsberechtigterResource,
    GespraechResource,
    NewErziehungsberechtigterSemicolonCSV,
    NewSchuelerSemicolonCSV,
    SchuelerResource,
    UpdateErziehungsberechtigterSemicolonCSV,
    UpdateSchuelerSemicolonCSV,
)

# === Admin-Klassen für die Stammdaten (Links im Menü) ===
# Diese Kataloge sind im Admin direkt erreichbar und können dort gesucht bzw.
# gepflegt werden; Schüler und Gespräche verwenden ihre Einträge als Auswahlwerte.

@admin.register(Grundschule)
class GrundschuleAdmin(admin.ModelAdmin):
    """Konfiguration der Such- und Listenansicht für Grundschulen."""
    list_display = ['name', 'ort']
    search_fields = ['name', 'ort']

@admin.register(MarketingKanal)
class MarketingKanalAdmin(admin.ModelAdmin):
    """Stellt die für Gesprächszuordnungen verfügbaren Kanäle bereit."""
    list_display = ['name']
    search_fields = ['name']

@admin.register(Religion)
class ReligionAdmin(admin.ModelAdmin):
    """Pflegt Religionen zentral für Schüler und Erziehungsberechtigte."""
    list_display = ['name']
    search_fields = ['name']

@admin.register(Staatsangehoerigkeit)
class StaatsangehoerigkeitAdmin(admin.ModelAdmin):
    """Pflegt den gemeinsamen Staatsangehörigkeitskatalog."""
    list_display = ['name']
    search_fields = ['name']

# =============================================================

class PflichtfeldMarkierung:
    """Bindet das app-eigene Stylesheet in Admin-Formularen ein."""
    class Media:
        css = {'all': ('gespraeche/pflichtfeld.css',)}


class ErziehungsberechtigterAdminForm(forms.ModelForm):
    """Zeigt bei aktivierten Übernahmeoptionen die Schülerwerte schreibgeschützt."""

    class Meta:
        model = Erziehungsberechtigter
        fields = '__all__'

    UEBERNAHMEN = {
        'religion_wie_schuelerin': ('religion', 'religion'),
        'staatsangehoerigkeit_wie_schuelerin': (
            'staatsangehoerigkeit',
            'staatsangehoerigkeit',
        ),
        'adresse_wie_schuelerin': (
            ('strasse', 'plz', 'ort'),
            ('strasse', 'plz', 'ort'),
        ),
    }

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)

        schuelerin = self._ausgewaehlte_schuelerin()
        if schuelerin is None:
            return

        for checkbox, (formularfelder, schuelerfelder) in self.UEBERNAHMEN.items():
            aktiviert = (
                self.fields[checkbox].to_python(
                    self.data.get(self.add_prefix(checkbox))
                )
                if self.is_bound
                else getattr(self.instance, checkbox)
            )
            if not aktiviert:
                continue

            if isinstance(formularfelder, str):
                formularfelder = (formularfelder,)
                schuelerfelder = (schuelerfelder,)

            for formularfeld, schuelerfeld in zip(formularfelder, schuelerfelder):
                wert = getattr(schuelerin, schuelerfeld)
                self.initial[formularfeld] = wert
                self.fields[formularfeld].disabled = True

    def _ausgewaehlte_schuelerin(self):
        """Ermittelt den im Formular gewählten Schüler, auch bei Inline-Formularen."""
        if self.is_bound:
            schueler_id = self.data.get(self.add_prefix('schueler'))
            if not schueler_id:
                return None
            return Schueler.objects.filter(pk=schueler_id).first()

        if self.instance.schueler_id:
            return self.instance.schueler
        return None


class ErziehungsberechtigterInline(admin.TabularInline):
    """Bearbeitet Kontaktpersonen direkt auf der Schülerseite."""
    model = Erziehungsberechtigter
    form = ErziehungsberechtigterAdminForm
    # Ein leeres Formular erleichtert das Anlegen einer weiteren Kontaktperson.
    extra = 1
    fields = [
        'vorname', 'nachname', 'beziehung',
        ('religion', 'religion_wie_schuelerin'),
        ('staatsangehoerigkeit', 'staatsangehoerigkeit_wie_schuelerin'),
        'adresse_wie_schuelerin',
        'strasse', 'plz', 'ort',
        'telefon', 'mobil', 'email',
    ]
    autocomplete_fields = ['religion', 'staatsangehoerigkeit']

class GespraechInline(admin.TabularInline):
    """Zeigt vorhandene Gespräche am Schüler, ohne dort neue anzulegen."""
    model = Gespraech
    extra = 0
    fields = ['datum', 'gewuenschte_schulform', 'protokollant']
    # Der Protokollant wird beim Anlegen automatisch aus dem angemeldeten
    # Benutzer übernommen und ist in dieser Übersicht nicht änderbar.
    readonly_fields = ['protokollant']
    # Löschungen und Neuanlagen erfolgen über die eigenständige Gesprächsverwaltung.
    can_delete = False
    show_change_link = True

    def has_add_permission(self, request, obj=None):
        """Unterbindet das Anlegen von Gesprächen über das Inline-Formular."""
        return False


@admin.register(Schueler)
class SchuelerAdmin(PflichtfeldMarkierung, ExportNurGruppe, ImportExportModelAdmin):
    """Admin-Oberfläche für Schüler einschließlich Import und Export."""
    resource_classes = [SchuelerResource]
    
    # Verwendet die Suchauswahl der Katalogmodelle statt langer Dropdownlisten.
    autocomplete_fields = ['grundschule', 'religion', 'staatsangehoerigkeit']
    
    # Kompakte Übersicht: Status und Warteliste werden eigens formatiert;
    # Durchschnitt und Änderungszeit sind direkt in der Liste sichtbar.
    list_display = [
        'nachname', 'vorname', 'klasse', 'status_colored',
        'durchschnitt', 'warteliste_icon', 'aktualisiert_am'
    ]
    list_filter = [
        'status', 'schulform', 'klasse', 'warteliste',
        'religion', 'staatsangehoerigkeit'
    ]
    search_fields = ['vorname', 'nachname', 'grundschule__name', 'ort']
    # Zugehörige Kontakte und Gespräche lassen sich aus dem Schülerdatensatz
    # heraus ansehen und bearbeiten.
    inlines = [ErziehungsberechtigterInline, GespraechInline]
    date_hierarchy = 'erstellt_am'

    # Gruppiert die umfangreiche Eingabemaske fachlich, damit zusammengehörige
    # Stammdaten, Noten und interne Entscheidungen gemeinsam erscheinen.
    fieldsets = (
        ('Stammdaten', {
            'fields': (
                ('vorname', 'nachname'),
                'geburtsdatum',
                ('strasse', 'plz', 'ort'),
                'geburtsort',
                ('religion', 'staatsangehoerigkeit'),
                'grundschule',
                ('anzahl_geschwister', 'davon_an_der_marienschule'),
                'freundin'
            )
        }),
        ('Noten', {
            'fields': (('deutsch', 'mathematik', 'sachkunde'),),
            'description': 'Durchschnitt wird automatisch berechnet'
        }),
        ('Kopfnoten', {
            'fields': (('av', 'sv'),)
        }),
        ('Intern', {
            'fields': ('status', ('schulform', 'klasse'), 'warteliste')
        }),
    )

    def status_colored(self, obj):
        """Zeigt den Aufnahmezustand als farbigen Punkt samt Beschriftung."""
        colors = {
            'angenommen': 'green',
            'abgelehnt': 'red',
            'in_pruefung': 'orange',
            'verzicht': 'gray'
        }
        color = colors.get(obj.status, 'black')
        # format_html maskiert den dynamischen Text sicher und erlaubt nur das
        # hier festgelegte HTML-Gerüst.
        return format_html(
            '<span style="color: {}; font-weight: bold;">●</span> {}',
            color, obj.get_status_display()
        )
    status_colored.short_description = "Status"
    status_colored.admin_order_field = 'status'

    def warteliste_icon(self, obj):
        """Hebt Schüler auf der Warteliste optisch in der Übersicht hervor."""
        if obj.warteliste:
            return format_html('<span style="color: orange;">⏱ Warteliste</span>')
        return '—'
    warteliste_icon.short_description = "Warteliste"

    # Diese Aktionen ändern mehrere markierte Datensätze in einem Datenbankaufruf.
    actions = ['markiere_angenommen', 'markiere_abgelehnt', 'auf_warteliste']

    def markiere_angenommen(self, request, queryset):
        """Setzt für alle markierten Schüler den Status auf angenommen."""
        queryset.update(status='angenommen', aktualisiert_am=timezone.now())
    markiere_angenommen.short_description = "Als angenommen markieren"

    def markiere_abgelehnt(self, request, queryset):
        """Setzt für alle markierten Schüler den Status auf abgelehnt."""
        queryset.update(status='abgelehnt', aktualisiert_am=timezone.now())
    markiere_abgelehnt.short_description = "Als abgelehnt markieren"

    def auf_warteliste(self, request, queryset):
        """Markiert alle ausgewählten Schüler als auf der Warteliste."""
        queryset.update(warteliste=True, aktualisiert_am=timezone.now())
    auf_warteliste.short_description = "Auf Warteliste setzen"

    def has_import_permission(self, request):
        """Beschränkt den Import auf Superuser; Exporte folgen der Admin-Berechtigung."""
        return request.user.is_superuser

    def get_import_formats(self):
        """Stellt getrennte Formate für Neuanlage und Aktualisierung bereit."""
        standard_formats = [
            format_class
            for format_class in super().get_import_formats()
            if format_class is not base_formats.CSV
        ]
        return [
            NewSchuelerSemicolonCSV,
            UpdateSchuelerSemicolonCSV,
            *standard_formats,
        ]

    def get_import_data_kwargs(self, **kwargs):
        """Übergibt den gewählten Neuanlage-/Update-Modus an die Ressource."""
        form = kwargs.get('form')
        mode = None
        if form is not None:
            format_index = int(form.cleaned_data['format'])
            format_class = self.get_import_formats()[format_index]
            mode = getattr(format_class, 'schueler_import_mode', None)

        import_data_kwargs = super().get_import_data_kwargs(**kwargs)
        if mode is not None:
            import_data_kwargs['schueler_import_mode'] = mode
        return import_data_kwargs


@admin.register(Erziehungsberechtigter)
class ErziehungsberechtigterAdmin(
    PflichtfeldMarkierung,
    ExportNurGruppe,
    ImportExportModelAdmin,
):
    """Listen- und Formularansicht für Erziehungsberechtigte."""
    form = ErziehungsberechtigterAdminForm
    # Lädt die verknüpften Objekte gemeinsam mit den Kontakten, um zusätzliche
    # Datenbankabfragen bei der Listenanzeige zu vermeiden.
    list_select_related = ['schueler', 'religion']
    resource_classes = [ErziehungsberechtigterResource]
    
    # Religionen werden über eine durchsuchbare Auswahl verknüpft.
    autocomplete_fields = ['religion', 'staatsangehoerigkeit']
    
    list_display = [
        'nachname', 'vorname', 'beziehung', 'schueler',
        'erziehungsberechtigt', 'telefon', 'email'
    ]
    list_filter = ['beziehung', 'erziehungsberechtigt', 'religion']
    search_fields = [
        'vorname', 'nachname',
        'schueler__vorname', 'schueler__nachname', 'email'
    ]

    # Teilt das Formular in Stammdaten, Adresse und Kontaktwege auf.
    fieldsets = (
        ('Stammdaten', {
            'fields': (
                'schueler',
                ('vorname', 'nachname'),
                'geburtsdatum',
                'beziehung',
                ('religion', 'religion_wie_schuelerin'),
                ('staatsangehoerigkeit', 'staatsangehoerigkeit_wie_schuelerin'),
                'erziehungsberechtigt'
            )
        }),
        ('Adresse', {
            'fields': (
                ('strasse', 'adresse_wie_schuelerin'),
                ('plz', 'ort'),
            )
        }),
        ('Kontaktdaten', {
            'fields': ('telefon', 'mobil', 'email')
        }),
    )

    def has_import_permission(self, request):
        """Lässt auch hier nur Superuser Daten importieren."""
        return request.user.is_superuser

    def get_import_formats(self):
        """Stellt getrennte Semikolon-Formate für Neuanlage und Update bereit."""
        standard_formats = [
            format_class
            for format_class in super().get_import_formats()
            if format_class is not base_formats.CSV
        ]
        return [
            NewErziehungsberechtigterSemicolonCSV,
            UpdateErziehungsberechtigterSemicolonCSV,
            *standard_formats,
        ]

    def get_import_data_kwargs(self, **kwargs):
        """Übergibt den gewählten Neuanlage-/Update-Modus an die Ressource."""
        form = kwargs.get('form')
        mode = None
        if form is not None:
            format_index = int(form.cleaned_data['format'])
            format_class = self.get_import_formats()[format_index]
            mode = getattr(format_class, 'erziehungsberechtigter_import_mode', None)

        import_data_kwargs = super().get_import_data_kwargs(**kwargs)
        if mode is not None:
            import_data_kwargs['erziehungsberechtigter_import_mode'] = mode
        return import_data_kwargs


@admin.register(Gespraech)
class GespraechAdmin(PflichtfeldMarkierung, ExportNurGruppe, ImportExportModelAdmin):
    """Admin-Oberfläche für Gesprächsprotokolle samt Import und Export."""
    # Das zugehörige Schülerobjekt wird direkt mit der Gesprächsliste geladen.
    list_select_related = ['schueler']
    
    # Viele Marketingkanäle lassen sich als übersichtliche Checkboxen auswählen.
    formfield_overrides = {
        models.ManyToManyField: {'widget': CheckboxSelectMultiple},
    }

    resource_classes = [GespraechResource]
    # Die Liste zeigt Termin, Schüler, Wunsch, Empfehlung und Protokollant.
    list_display = [
        'datum_formatted', 'schueler', 'gewuenschte_schulform',
        'aufnahmeempfehlung_status', 'protokollant'
    ]
    list_filter = [
        'datum', 'gewuenschte_schulform', 'aufnahmeempfehlung', 
        'schueler__klasse', 'protokollant'
    ]
    search_fields = [
        'schueler__vorname', 'schueler__nachname',
        'hobbys_lieblingsfaecher_ags', 'warum_diese_schule', 'sonstiges'
    ]
    date_hierarchy = 'datum'
    # Protokollant und Zeitstempel sind Metadaten und im Formular nicht manuell
    # veränderbar.
    readonly_fields = ['erstellt_am', 'aktualisiert_am', 'protokollant']

    # Trennt Basisdaten, Gesprächsnotizen, Förderhinweise und Metadaten in
    # aufklappbare bzw. thematisch benannte Formularbereiche.
    fieldsets = (
        ('Basisdaten', {
            'fields': ('schueler', 'datum')
        }),
        ('Allgemein', {
            'fields': (
                'gewuenschte_schulform',
                'offenheit_christlicher_ru', 'anwesend_ausser_tochter'
            )
        }),
        ('Notizen: Erstkontakt', {
            'fields': (
                ('erstkontakt_tdot', 'erstkontakt_forscherinnennachmittag',
                 'erstkontakt_workshop_jakoby'),
            )
        }),
        ('Notizen: Wie auf uns aufmerksam geworden?', {
            'fields': ('aufmerksam_durch',)
        }),
        ('Notizen: Freitextfelder', {
            'fields': (
                'hobbys_lieblingsfaecher_ags', 'warum_diese_schule',
                'kirchliche_sozialisation', 'sonstiges'
            )
        }),
        ('Notizen: Förderbedarf', {
            'fields': (
                ('foerderbedarf_lrs', 'foerderbedarf_dyskalkulie', 'foerderbedarf_tha'),
            )
        }),
        ('Notizen: Elternengagement', {
            'fields': ('elternengagement',)
        }),
        ('Aufnahmeempfehlung', {
            'fields': ('aufnahmeempfehlung',)
        }),
        ('Metadaten', {
            'fields': ('protokollant', 'erstellt_am', 'aktualisiert_am'),
            'classes': ('collapse',)
        }),
    )

    def datum_formatted(self, obj):
        """Formatiert den Gesprächszeitpunkt für die Tabellenansicht."""
        return obj.datum.strftime('%d.%m.%Y %H:%M')
    datum_formatted.short_description = "Datum"
    datum_formatted.admin_order_field = 'datum'

    def aufnahmeempfehlung_status(self, obj):
        """Stellt die Aufnahmeempfehlung mit passendem Symbol und Farbe dar."""
        if obj.aufnahmeempfehlung == 'ja':
            return format_html('<span style="color: green; font-weight: bold;">✓ Ja</span>')
        elif obj.aufnahmeempfehlung == 'vielleicht':
            return format_html('<span style="color: orange; font-weight: bold;">? Vielleicht</span>')
        elif obj.aufnahmeempfehlung == 'nein':
            return format_html('<span style="color: red; font-weight: bold;">✗ Nein</span>')
        return '—'
    aufnahmeempfehlung_status.short_description = "Empfehlung"

    def save_model(self, request, obj, form, change):
        """Speichert bei neu angelegten Gesprächen den angemeldeten Protokollanten.

        Bei Änderungen bleibt der bereits gespeicherte Protokollant unverändert.
        Das eigentliche Speichern übernimmt anschließend die Django-Basisklasse.
        """
        if not change:
            obj.protokollant = request.user
        super().save_model(request, obj, form, change)

    def has_import_permission(self, request):
        """Beschränkt den Gesprächsimport auf Superuser."""
        return request.user.is_superuser

# Überschriften der gesamten Django-Adminseite, nicht nur dieses App-Bereichs.
admin.site.site_header = "Aufnahmeverwaltung"
admin.site.site_title = "Aufnahmeverwaltung"
admin.site.index_title = "Verwaltung"