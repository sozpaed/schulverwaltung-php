"""
Beschreibung: Datenmodelle für Schüler, Erziehungsberechtigte, Gespräche und
               die dazugehörigen Auswahllisten.
Urheber: Christian Leeser mit KI Claude, Gemini und Copilot
Version: 0.3.0
Datei: models.py
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

from django.db import models
from django.contrib.auth.models import User
from django.core.validators import MinValueValidator, MaxValueValidator, RegexValidator

class Grundschule(models.Model):
    """Pflegt Grundschulen als zentral wiederverwendbare Auswahlliste.

    Schülerdatensätze verweisen auf einen Eintrag dieser Tabelle. So bleiben
    Schreibweisen einheitlich und Name sowie Ort müssen nicht bei jedem Schüler
    erneut als Freitext eingegeben werden.
    """
    name = models.CharField(max_length=200, verbose_name="Name der Grundschule")
    ort = models.CharField(max_length=100, blank=True, verbose_name="Ort")

    class Meta:
        # Diese Bezeichnungen erscheinen unter anderem in der Django-Administration.
        verbose_name = "Grundschule"
        verbose_name_plural = "Grundschulen"
        # Grundschulen werden alphabetisch nach Namen angezeigt.
        ordering = ['name']

    def __str__(self):
        """Liefert eine kompakte Bezeichnung für Auswahlfelder und Listen."""
        if self.ort:
            return f"{self.name} ({self.ort})"
        return self.name

class MarketingKanal(models.Model):
    """Zentral gepflegter Eintrag für einen Weg der Kontaktaufnahme.

    Ein Gespräch kann mit mehreren Kanälen verknüpft werden; die Zuordnung
    selbst wird am Gespräch gespeichert.
    """
    name = models.CharField(max_length=100, verbose_name="Kanal (z.B. Instagram, Verwandte)")

    class Meta:
        verbose_name = "Marketingkanal"
        verbose_name_plural = "Marketingkanäle"
        # Sorgt auch in Auswahlfeldern für eine vorhersehbare Reihenfolge.
        ordering = ['name']

    def __str__(self):
        """Verwendet den Kanalnamen als lesbare Objektbezeichnung."""
        return self.name

class Religion(models.Model):
    """Zentral gepflegte Liste möglicher Religionen oder Konfessionen.

    Schüler und Erziehungsberechtigte können optional auf denselben Katalog
    verweisen, anstatt uneinheitliche Freitexte zu verwenden.
    """
    name = models.CharField(max_length=50, verbose_name="Religion / Konfession")

    class Meta:
        verbose_name = "Religion"
        verbose_name_plural = "Religionen"
        # Die Einträge werden in Auswahlfeldern alphabetisch angeboten.
        ordering = ['name']

    def __str__(self):
        """Gibt den Namen der Religion für Django-Auswahlfelder zurück."""
        return self.name


class Staatsangehoerigkeit(models.Model):
    """Zentraler Katalog der für Schüler und Erziehungsberechtigte nutzbaren Staatsangehörigkeiten."""

    name = models.CharField(
        max_length=100,
        unique=True,
        verbose_name="Staatsangehörigkeit"
    )

    class Meta:
        verbose_name = "Staatsangehörigkeit"
        verbose_name_plural = "Staatsangehörigkeiten"
        ordering = ['name']

    def __str__(self):
        """Zeigt den Namen in Auswahlfeldern und Admin-Listen an."""
        return self.name


def default_staatsangehoerigkeit_id():
    """Liefert die ID der Standard-Staatsangehörigkeit für neue Schüler."""
    eintrag, _ = Staatsangehoerigkeit.objects.get_or_create(name='Deutschland')
    return eintrag.pk


class Schueler(models.Model):
    """Stammdaten, schulische Angaben und interner Aufnahmezustand eines Kindes.

    Ein Schüler kann mehrere Erziehungsberechtigte und Aufnahmegespräche haben.
    Die erlaubten Werte für Status, Schulform und Klasse sind als Auswahlwerte
    definiert, damit Eingaben im Adminbereich konsistent bleiben.
    """

    # Interner Bearbeitungsstand der Aufnahme; gespeichert wird der technische
    # Wert, während Django in der Oberfläche die zugehörige Beschriftung anzeigt.
    STATUS_CHOICES = [
        ('abgelehnt', 'Abgelehnt'),
        ('angenommen', 'Angenommen'),
        ('in_pruefung', 'In Prüfung'),
        ('verzicht', 'Verzicht'),
    ]

    # Schulform und Klassenstufe sind interne Zuordnungen und keine Freitexte.
    SCHULFORM_CHOICES = [
        ('foerderstufe', 'Förderstufe'),
        ('gymnasium', 'Gymnasium'),
    ]

    KLASSE_CHOICES = [
        ('5a', '5a'),
        ('5b', '5b'),
        ('5c', '5c'),
        ('5d', '5d'),
        ('5e', '5e'),
    ]

    # === STAMMDATEN ===
    # Adressangaben sind Text, damit etwa führende Nullen der Postleitzahl
    # erhalten bleiben.
    vorname = models.CharField(max_length=100, verbose_name="Vorname")
    nachname = models.CharField(max_length=100, verbose_name="Nachname")
    geburtsdatum = models.DateField(verbose_name="Geburtsdatum")
    strasse = models.CharField(max_length=200, verbose_name="Straße")
    plz = models.CharField(
        max_length=5,
        validators=[RegexValidator(r'^\d{5}$', 'Ungültige PLZ (muss 5 Ziffern enthalten)')],
        verbose_name="PLZ"
    )
    ort = models.CharField(max_length=100, verbose_name="Ort")
    geburtsort = models.CharField(max_length=100, verbose_name="Geburtsort")
    
    # Optionale Katalogverknüpfung. SET_NULL erhält den Schülerdatensatz,
    # wenn ein Religionseintrag später entfernt wird.
    religion = models.ForeignKey(
        Religion,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        verbose_name="Religion"
    )
    
    # Schüler wählen einen Eintrag aus dem gemeinsamen Staatsangehörigkeitskatalog.
    # Neue Datensätze erhalten wie bisher "Deutschland" als Standard.
    staatsangehoerigkeit = models.ForeignKey(
        Staatsangehoerigkeit,
        on_delete=models.PROTECT,
        default=default_staatsangehoerigkeit_id,
        verbose_name="Staatsangehörigkeit"
    )
    
    # Bei Entfernung einer Grundschule bleibt der Schüler bestehen; lediglich
    # die Zuordnung wird durch SET_NULL geleert.
    grundschule = models.ForeignKey(
        Grundschule,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        verbose_name="Grundschule"
    )
    
    # Anzahl der Geschwister als nichtnegative Ganzzahl. Null ist der
    # Standardwert, wenn keine Geschwister angegeben werden.
    anzahl_geschwister = models.PositiveIntegerField(
        default=0,
        verbose_name="Anzahl Geschwister"
    )
    # Teilmenge der Geschwister, die ebenfalls die Marienschule besuchen.
    davon_an_der_marienschule = models.PositiveIntegerField(
        default=0,
        verbose_name="davon an der Marienschule"
    )
    freundin = models.CharField(
        max_length=100,
        blank=True,
        verbose_name="Freundin/Freund"
    )

    # === NOTEN ===
    # Noten sind optional. Die Validatoren grenzen sie bei einer Django-
    # Formular- oder Modellvalidierung auf den Bereich 1 bis 6 ein.
    deutsch = models.IntegerField(
        null=True, blank=True,
        validators=[MinValueValidator(1), MaxValueValidator(6)],
        verbose_name="Deutsch", help_text="Note 1-6"
    )
    mathematik = models.IntegerField(
        null=True, blank=True,
        validators=[MinValueValidator(1), MaxValueValidator(6)],
        verbose_name="Mathematik", help_text="Note 1-6"
    )
    sachkunde = models.IntegerField(
        null=True, blank=True,
        validators=[MinValueValidator(1), MaxValueValidator(6)],
        verbose_name="Sachkunde", help_text="Note 1-6"
    )

    # === KOPFNOTEN ===
    # Arbeits- und Sozialverhalten werden unabhängig von den Fachnoten erfasst.
    av = models.IntegerField(
        null=True, blank=True,
        validators=[MinValueValidator(1), MaxValueValidator(6)],
        verbose_name="AV (Arbeitsverhalten)", help_text="Note 1-6"
    )
    sv = models.IntegerField(
        null=True, blank=True,
        validators=[MinValueValidator(1), MaxValueValidator(6)],
        verbose_name="SV (Sozialverhalten)", help_text="Note 1-6"
    )

    # === INTERN ===
    # Der Status beginnt mit "in Prüfung". Schulform und Klasse können bis zur
    # internen Zuordnung unausgefüllt bleiben.
    status = models.CharField(
        max_length=20, choices=STATUS_CHOICES,
        default='in_pruefung', verbose_name="Status"
    )
    schulform = models.CharField(
        max_length=20, choices=SCHULFORM_CHOICES,
        blank=True, verbose_name="Schulform"
    )
    klasse = models.CharField(
        max_length=10, choices=KLASSE_CHOICES,
        blank=True, verbose_name="Klasse"
    )
    warteliste = models.BooleanField(default=False, verbose_name="Auf Warteliste")

    # Django setzt diese Zeitstempel automatisch beim Erstellen bzw. Speichern.
    erstellt_am = models.DateTimeField(auto_now_add=True)
    aktualisiert_am = models.DateTimeField(auto_now=True)

    class Meta:
        verbose_name = "Schülerin"
        verbose_name_plural = "Schülerinnen"
        # Schülerlisten werden nach Nach- und anschließend Vornamen sortiert.
        ordering = ['nachname', 'vorname']
        # Indizes unterstützen häufige Such- und Filterabfragen in diesen Feldern.
        indexes = [
            models.Index(fields=['nachname', 'vorname']),
            models.Index(fields=['klasse']),
            models.Index(fields=['status']),
        ]
        # Die Zahl der Geschwister an der Marienschule kann nicht größer sein
        # als die Gesamtzahl der Geschwister.
        constraints = [
            models.CheckConstraint(
                condition=models.Q(davon_an_der_marienschule__lte=models.F('anzahl_geschwister')),
                name='marienschule_siblings_lte_total',
            ),
        ]

    def __str__(self):
        """Kurzform für Admin-Listen und Verknüpfungen."""
        return f"{self.nachname}, {self.vorname}"

    def vollstaendiger_name(self):
        """Gibt Vor- und Nachnamen in natürlicher Reihenfolge zurück."""
        return f"{self.vorname} {self.nachname}"
    # Django-Admin verwendet diese Beschriftung als Spaltenüberschrift.
    vollstaendiger_name.short_description = "Name"

    def durchschnitt(self):
        """Berechnet den Mittelwert der vorhandenen Fachnoten.

        Nicht eingetragene Noten werden ausgelassen. Gibt es noch keine Note,
        ist das Ergebnis ``None`` statt eines irreführenden Nullwerts.
        """
        noten = [n for n in [self.deutsch, self.mathematik, self.sachkunde] if n is not None]
        if not noten:
            return None
        return round(sum(noten) / len(noten), 2)
    durchschnitt.short_description = "Notendurchschnitt"

    def adresse_komplett(self):
        """Setzt Straße, Postleitzahl und Ort für die Anzeige zusammen."""
        return f"{self.strasse}, {self.plz} {self.ort}"
    adresse_komplett.short_description = "Adresse"


class Erziehungsberechtigter(models.Model):
    """Kontakt- und Adressdaten einer Bezugsperson eines Schülers.

    Der Fremdschlüssel bindet jede Person an genau einen Schüler. Wird der
    Schüler gelöscht, werden zugehörige Erziehungsberechtigte durch ``CASCADE``
    ebenfalls gelöscht.
    """

    # Beschreibt die Beziehung zum Kind als festgelegten Auswahlwert.
    BEZIEHUNG_CHOICES = [
        ('mutter', 'Mutter'),
        ('vater', 'Vater'),
        ('vormund', 'Vormund'),
        ('pflegeeltern', 'Pflegeeltern'),
        ('sonstiges', 'Sonstiges'),
    ]

    schueler = models.ForeignKey(
        Schueler,
        on_delete=models.CASCADE,
        related_name='erziehungsberechtigte',
        verbose_name="Schülerin"
    )

    # === STAMMDATEN ===
    # Geburtsdatum und Religion können fehlen, wenn diese Informationen nicht
    # für jede Kontaktperson vorliegen.
    vorname = models.CharField(max_length=100, verbose_name="Vorname")
    nachname = models.CharField(max_length=100, verbose_name="Nachname")
    geburtsdatum = models.DateField(null=True, blank=True, verbose_name="Geburtsdatum")
    
    # Bei Entfernung eines Religionseintrags bleibt die Kontaktperson erhalten.
    religion = models.ForeignKey(
        Religion,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        verbose_name="Religion"
    )

    # Gemeinsamer Katalog mit Schülern; bei bestehenden Kontakten kann der
    # Wert fehlen, da Staatsangehörigkeit dort neu erfasst wird.
    staatsangehoerigkeit = models.ForeignKey(
        Staatsangehoerigkeit,
        on_delete=models.SET_NULL,
        null=True,
        blank=True,
        verbose_name="Staatsangehörigkeit"
    )

    # Aktivierte Optionen übernehmen beim Speichern den entsprechenden Wert
    # aus dem verknüpften Schülerdatensatz.
    religion_wie_schuelerin = models.BooleanField(
        default=False,
        verbose_name="Wie bei Schülerin"
    )
    staatsangehoerigkeit_wie_schuelerin = models.BooleanField(
        default=False,
        verbose_name="Wie bei Schülerin"
    )

    # Das Flag unterscheidet Erziehungsberechtigte von weiteren Kontaktpersonen.
    beziehung = models.CharField(
        max_length=20, choices=BEZIEHUNG_CHOICES,
        verbose_name="Beziehung zum Schülerin"
    )
    erziehungsberechtigt = models.BooleanField(
        default=True, verbose_name="Erziehungsberechtigt"
    )

    # Adresse: PLZ bleibt Text, damit führende Nullen erhalten bleiben.
    strasse = models.CharField(max_length=200, blank=True, verbose_name="Straße")
    plz = models.CharField(
        max_length=5,
        null=True, blank=True,
        validators=[RegexValidator(r'^\d{5}$', 'Ungültige PLZ (muss 5 Ziffern enthalten)')],
        verbose_name="PLZ"
    )
    ort = models.CharField(max_length=100, blank=True, verbose_name="Ort")

    adresse_wie_schuelerin = models.BooleanField(
        default=False,
        verbose_name="Wie bei Schülerin"
    )

    # Kontaktmöglichkeiten sind freiwillig; Django prüft beim E-Mail-Feld das Format.
    telefon = models.CharField(max_length=50, blank=True, verbose_name="Telefon")
    mobil = models.CharField(max_length=50, blank=True, verbose_name="Mobil")
    email = models.EmailField(blank=True, verbose_name="E-Mail-Adresse")

    class Meta:
        verbose_name = "Erziehungsberechtigter"
        verbose_name_plural = "Erziehungsberechtigte"
        # Sortiert Kontakte in Listen nach Nach- und Vornamen.
        ordering = ['nachname', 'vorname']

    def __str__(self):
        """Zeigt Name und Beziehungsart in Auswahlfeldern und Listen."""
        return f"{self.vorname} {self.nachname} ({self.get_beziehung_display()})"

    def save(self, *args, **kwargs):
        """Kopiert aktivierte Angaben vor jedem Speichern aus dem Schülerdatensatz."""
        if self.schueler_id:
            schuelerin = self.schueler
            zu_synchronisieren = set()

            if self.religion_wie_schuelerin:
                self.religion_id = schuelerin.religion_id
                zu_synchronisieren.add('religion')

            if self.staatsangehoerigkeit_wie_schuelerin:
                self.staatsangehoerigkeit_id = schuelerin.staatsangehoerigkeit_id
                zu_synchronisieren.add('staatsangehoerigkeit')

            if self.adresse_wie_schuelerin:
                self.strasse = schuelerin.strasse
                self.plz = schuelerin.plz
                self.ort = schuelerin.ort
                zu_synchronisieren.update({'strasse', 'plz', 'ort'})

            update_fields = kwargs.get('update_fields')
            if update_fields is not None and zu_synchronisieren:
                kwargs['update_fields'] = set(update_fields) | zu_synchronisieren

        super().save(*args, **kwargs)

    def adresse_komplett(self):
        """Liefert nur bei vollständiger Anschrift eine formatierte Adresse."""
        if self.strasse and self.plz and self.ort:
            return f"{self.strasse}, {self.plz} {self.ort}"
        # Macht im Admin sichtbar, dass keine vollständige Adresse vorliegt.
        return "—"
    adresse_komplett.short_description = "Adresse"


class Gespraech(models.Model):
    """Protokolliert ein Aufnahmegespräch zu einem Schüler.

    Neben Zeitpunkt und Schulformwunsch hält der Datensatz Gesprächsnotizen,
    Förderhinweise, Erstkontakt und Aufnahmeempfehlung fest. Zu einem Schüler
    können mehrere Gespräche chronologisch gespeichert werden.
    """

    # Auswahlwerte für Schulformwunsch, religiöse Offenheit, anwesende Personen
    # und Empfehlung sorgen für einheitliche Eingaben und Auswertungen.
    SCHULFORM_WUNSCH_CHOICES = [
        ('gymnasium', 'Gymnasium'),
        ('foerderstufe_realschule', 'Förderstufe/Realschule'),
        ('unklar', 'Noch unklar'),
    ]

    OFFENHEIT_RU_CHOICES = [
        ('katholisch', 'Katholisch'),
        ('evangelisch', 'Evangelisch'),
        ('nein', 'Nein'),
    ]

    ANWESEND_CHOICES = [
        ('mutter', 'Mutter'),
        ('vater', 'Vater'),
        ('mutter_vater', 'Mutter und Vater'),
        ('sonstige', 'Sonstige'),
    ]

    EMPFEHLUNG_CHOICES = [
        ('ja', 'Ja'),
        ('vielleicht', 'Vielleicht'),
        ('nein', 'Nein'),
    ]

    # PROTECT verhindert, dass Schüler mit vorhandenen Gesprächsprotokollen
    # versehentlich gelöscht werden.
    schueler = models.ForeignKey(
        Schueler,
        on_delete=models.PROTECT,
        related_name='gespraeche',
        verbose_name="Schülerin"
    )
    datum = models.DateTimeField(verbose_name="Datum und Uhrzeit")

    # Der Schulformwunsch ist verpflichtend; die weiteren Angaben bilden
    # eigene Gesprächsfragen ab.
    gewuenschte_schulform = models.CharField(
        max_length=30, choices=SCHULFORM_WUNSCH_CHOICES,
        verbose_name="Gewünschte Schulform"
    )
    
    offenheit_christlicher_ru = models.CharField(
        max_length=20, choices=OFFENHEIT_RU_CHOICES,
        blank=True,
        verbose_name="Muslimisch: Offenheit für christlichen RU?",
        help_text="Falls IRU überbucht"
    )
    anwesend_ausser_tochter = models.CharField(
        max_length=20, choices=ANWESEND_CHOICES,
        verbose_name="Anwesend außer der Tochter"
    )

    # Erstkontakte können unabhängig voneinander angekreuzt werden.
    erstkontakt_tdot = models.BooleanField(default=False, verbose_name="TdoT (Tag der offenen Tür)")
    erstkontakt_forscherinnennachmittag = models.BooleanField(default=False, verbose_name="Forscherinnennachmittag")
    erstkontakt_workshop_jakoby = models.BooleanField(default=False, verbose_name="Workshop Jakoby")

    # Ein Gespräch kann über keinen, einen oder mehrere Marketingkanäle
    # zustande gekommen sein; die Kanäle werden separat gepflegt.
    aufmerksam_durch = models.ManyToManyField(
        MarketingKanal,
        blank=True,
        verbose_name="Wie auf uns aufmerksam geworden?"
    )

    # Freitextnotizen halten Antworten fest, die nicht in feste Auswahlfelder passen.
    hobbys_lieblingsfaecher_ags = models.TextField(blank=True, verbose_name="Hobbys/Lieblingsfächer/AGs")
    warum_diese_schule = models.TextField(blank=True, verbose_name="Warum diese Schule?")
    kirchliche_sozialisation = models.TextField(blank=True, verbose_name="Kirchliche Sozialisation")
    sonstiges = models.TextField(blank=True, verbose_name="Sonstiges")

    # Die genannten Förderbedarfe sind voneinander unabhängige Angaben.
    foerderbedarf_lrs = models.BooleanField(default=False, verbose_name="LRS (Lese-Rechtschreib-Schwäche)")
    foerderbedarf_dyskalkulie = models.BooleanField(default=False, verbose_name="Dyskalkulie")
    foerderbedarf_tha = models.BooleanField(default=False, verbose_name="THA")

    elternengagement = models.TextField(blank=True, verbose_name="Elternengagement?")

    # Die Empfehlung bleibt optional, solange noch keine Einschätzung vorliegt.
    aufnahmeempfehlung = models.CharField(
        max_length=15, 
        choices=EMPFEHLUNG_CHOICES,
        blank=True,
        verbose_name="Aufnahmeempfehlung"
    )

    protokollant = models.ForeignKey(
        User,
        on_delete=models.SET_NULL,
        null=True,
        verbose_name="Protokolliert von",
        related_name='protokollierte_gespraeche'
    )
    # Django verwaltet Erstellungs- und Änderungszeitpunkt automatisch.
    erstellt_am = models.DateTimeField(auto_now_add=True)
    aktualisiert_am = models.DateTimeField(auto_now=True)

    class Meta:
        verbose_name = "Gespräch"
        verbose_name_plural = "Gespräche"
        # Neueste Gespräche erscheinen standardmäßig zuerst.
        ordering = ['-datum']
        # Indizes unterstützen die Datumslisten sowie Abfragen je Schüler.
        indexes = [
            models.Index(fields=['-datum']),
            models.Index(fields=['schueler', '-datum']),
        ]

    def __str__(self):
        """Kurzbezeichnung mit Schülernachnamen und formatiertem Datum."""
        return f"Aufnahmegespräch {self.schueler.nachname} ({self.datum.strftime('%d.%m.%Y')})"