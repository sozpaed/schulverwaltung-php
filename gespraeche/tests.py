from datetime import date
from types import SimpleNamespace

from django.contrib.auth import get_user_model
from django.contrib.sites.models import Site
from django.core.exceptions import PermissionDenied, ValidationError
from django.test import RequestFactory, TestCase, override_settings
from django.template.loader import render_to_string
from django.contrib import admin
from django.urls import reverse

from .models import (
    Erziehungsberechtigter,
    Gespraech,
    Religion,
    Schueler,
    Staatsangehoerigkeit,
)
from .admin import ErziehungsberechtigterAdmin, SchuelerAdmin
from .admin import ErziehungsberechtigterAdminForm
from .resources import (
    NewSchuelerSemicolonCSV,
    NewErziehungsberechtigterSemicolonCSV,
    SemicolonCSV,
    ErziehungsberechtigterResource,
    SchuelerResource,
    SchuelerStaatsangehoerigkeitWidget,
    StaatsangehoerigkeitWidget,
    UpdateErziehungsberechtigterSemicolonCSV,
    UpdateSchuelerSemicolonCSV,
)
import tablib


class AdminOTPSetupFlowTests(TestCase):
    def test_two_factor_site_uses_configured_authenticator_issuer(self):
        self.assertEqual(
            Site.objects.get(pk=1).name,
            'MSO-Anmeldeverwaltung',
        )

    def test_two_factor_login_uses_project_base_template(self):
        response = self.client.get(reverse('two_factor:login'))

        self.assertEqual(response.status_code, 200)
        self.assertNotContains(response, 'Provide a template named')
        self.assertContains(response, 'Schulverwaltung')
        self.assertContains(response, 'id="container"')

    @override_settings(STORAGES={
        'default': {'BACKEND': 'django.core.files.storage.FileSystemStorage'},
        'staticfiles': {
            'BACKEND': 'django.contrib.staticfiles.storage.StaticFilesStorage',
        },
    })
    def test_unenrolled_staff_can_access_admin_and_manage_two_factor(self):
        user = get_user_model().objects.create_user(
            username='otp-admin',
            password='test-password',
            is_staff=True,
        )
        self.client.force_login(user)

        admin_response = self.client.get('/admin/')
        profile_response = self.client.get(reverse('two_factor:profile'))

        self.assertEqual(admin_response.status_code, 200)
        self.assertContains(
            admin_response,
            f'href="{reverse("two_factor:profile")}">Sicherheitseinstellungen</a>',
        )
        self.assertEqual(profile_response.status_code, 200)
        self.assertContains(profile_response, reverse('two_factor:setup'))


class DataExportPermissionTests(TestCase):
    def setUp(self):
        self.user = get_user_model().objects.create_user(
            username='export-staff',
            password='test-password',
            is_staff=True,
        )
        self.request = RequestFactory().get('/admin/')
        self.request.user = self.user
        self.data_admins = [
            admin.site._registry[model]
            for model in (Schueler, Erziehungsberechtigter, Gespraech)
        ]

    def test_staff_without_export_group_cannot_export(self):
        for data_admin in self.data_admins:
            with self.subTest(model=data_admin.model.__name__):
                self.assertFalse(data_admin.has_export_permission(self.request))
                with self.assertRaises(PermissionDenied):
                    data_admin.export_action(self.request)

    def test_export_group_and_superuser_can_export(self):
        from django.contrib.auth.models import Group

        export_group, _ = Group.objects.get_or_create(name='Datenexport')
        self.user.groups.add(export_group)

        for data_admin in self.data_admins:
            with self.subTest(model=data_admin.model.__name__):
                self.assertTrue(data_admin.has_export_permission(self.request))

        self.user.groups.remove(export_group)
        self.user.is_superuser = True
        self.user.save(update_fields=['is_superuser'])

        for data_admin in self.data_admins:
            with self.subTest(model=data_admin.model.__name__, role='superuser'):
                self.assertTrue(data_admin.has_export_permission(self.request))


def schueler_mit_pflichtangaben(**werte):
    """Erzeugt einen ungespeicherten Schüler mit den Pflichtfeldern."""
    daten = {
        'vorname': 'Anna',
        'nachname': 'Beispiel',
        'geburtsdatum': date(2015, 1, 1),
        'strasse': 'Schulstraße 1',
        'plz': '12345',
        'ort': 'Beispielstadt',
        'geburtsort': 'Beispielstadt',
    }
    daten.update(werte)
    return Schueler(**daten)


class SchuelerGeschwisterTests(TestCase):
    """Prüft die Zählfelder für Geschwister und die Marienschule."""

    def test_geschwisterfelder_haben_standardwert_null(self):
        schueler = schueler_mit_pflichtangaben()

        self.assertEqual(schueler.anzahl_geschwister, 0)
        self.assertEqual(schueler.davon_an_der_marienschule, 0)

    def test_gueltige_geschwisterzahlen_werden_gespeichert(self):
        schueler = schueler_mit_pflichtangaben(
            anzahl_geschwister=3,
            davon_an_der_marienschule=2,
        )
        schueler.full_clean()
        schueler.save()

        gespeicherter_schueler = Schueler.objects.get(pk=schueler.pk)
        self.assertEqual(gespeicherter_schueler.anzahl_geschwister, 3)
        self.assertEqual(gespeicherter_schueler.davon_an_der_marienschule, 2)

    def test_marienschul_anzahl_darf_gesamtzahl_nicht_ueberschreiten(self):
        schueler = schueler_mit_pflichtangaben(
            anzahl_geschwister=1,
            davon_an_der_marienschule=2,
        )

        with self.assertRaises(ValidationError) as fehler:
            schueler.full_clean()

        self.assertIn('__all__', fehler.exception.message_dict)
        self.assertIn(
            'marienschule_siblings_lte_total',
            fehler.exception.message_dict['__all__'][0],
        )

    def test_geschwisterzahlen_duerfen_nicht_negativ_sein(self):
        schueler = schueler_mit_pflichtangaben(anzahl_geschwister=-1)

        with self.assertRaises(ValidationError) as fehler:
            schueler.full_clean()

        self.assertIn('anzahl_geschwister', fehler.exception.message_dict)


class StaatsangehoerigkeitTests(TestCase):
    """Prüft den gemeinsamen Staatsangehörigkeitskatalog."""

    def test_schueler_erhaelt_deutschland_als_standard(self):
        schueler = schueler_mit_pflichtangaben()
        schueler.save()

        self.assertEqual(schueler.staatsangehoerigkeit.name, 'Deutschland')

    def test_schueler_und_erziehungsberechtigter_teilen_katalogeintrag(self):
        schueler = schueler_mit_pflichtangaben()
        schueler.save()
        erziehungsberechtigter = Erziehungsberechtigter.objects.create(
            schueler=schueler,
            vorname='Beate',
            nachname='Beispiel',
            beziehung='mutter',
            staatsangehoerigkeit=schueler.staatsangehoerigkeit,
        )

        self.assertEqual(
            erziehungsberechtigter.staatsangehoerigkeit,
            schueler.staatsangehoerigkeit,
        )

    def test_import_legt_neue_staatsangehoerigkeit_an(self):
        widget = StaatsangehoerigkeitWidget(Staatsangehoerigkeit, 'name')

        ergebnis = widget.clean('  Beispielstaat  ')

        self.assertEqual(ergebnis.name, 'Beispielstaat')
        self.assertTrue(
            Staatsangehoerigkeit.objects.filter(name='Beispielstaat').exists()
        )

    def test_leerer_schueler_import_verwendet_deutschland(self):
        widget = SchuelerStaatsangehoerigkeitWidget(Staatsangehoerigkeit, 'name')

        ergebnis = widget.clean('')

        self.assertEqual(ergebnis.name, 'Deutschland')


class ErziehungsberechtigterUebernahmeTests(TestCase):
    """Prüft die Übernahmeoptionen für Religion, Staatsangehörigkeit und Adresse."""

    def setUp(self):
        self.religion = Religion.objects.create(name='Beispielreligion')
        self.staatsangehoerigkeit = Staatsangehoerigkeit.objects.create(
            name='Beispielstaat'
        )
        self.schueler = schueler_mit_pflichtangaben(
            religion=self.religion,
            staatsangehoerigkeit=self.staatsangehoerigkeit,
            strasse='Schülerstraße 2',
            plz='54321',
            ort='Schülerort',
        )
        self.schueler.save()

    def test_e_mail_adresse_ist_im_adminformular_optional_verfuegbar(self):
        formular = ErziehungsberechtigterAdminForm()

        self.assertIn('email', formular.fields)
        self.assertEqual(formular.fields['email'].label, 'E-Mail-Adresse')
        self.assertFalse(formular.fields['email'].required)

    def test_aktivierte_werte_werden_beim_speichern_uebernommen(self):
        erziehungsberechtigter = Erziehungsberechtigter(
            schueler=self.schueler,
            vorname='Beate',
            nachname='Beispiel',
            beziehung='mutter',
            religion_wie_schuelerin=True,
            staatsangehoerigkeit_wie_schuelerin=True,
            adresse_wie_schuelerin=True,
        )

        erziehungsberechtigter.save()

        self.assertEqual(erziehungsberechtigter.religion, self.religion)
        self.assertEqual(
            erziehungsberechtigter.staatsangehoerigkeit,
            self.staatsangehoerigkeit,
        )
        self.assertEqual(erziehungsberechtigter.strasse, 'Schülerstraße 2')
        self.assertEqual(erziehungsberechtigter.plz, '54321')
        self.assertEqual(erziehungsberechtigter.ort, 'Schülerort')

    def test_update_fields_speichert_auch_uebernommene_felder(self):
        erziehungsberechtigter = Erziehungsberechtigter.objects.create(
            schueler=self.schueler,
            vorname='Beate',
            nachname='Beispiel',
            beziehung='mutter',
        )
        erziehungsberechtigter.adresse_wie_schuelerin = True
        erziehungsberechtigter.save(update_fields=['adresse_wie_schuelerin'])

        erziehungsberechtigter.refresh_from_db()
        self.assertEqual(erziehungsberechtigter.strasse, 'Schülerstraße 2')
        self.assertEqual(erziehungsberechtigter.plz, '54321')
        self.assertEqual(erziehungsberechtigter.ort, 'Schülerort')

    def test_aktivierte_felder_werden_im_adminformular_angezeigt_aber_deaktiviert(self):
        erziehungsberechtigter = Erziehungsberechtigter.objects.create(
            schueler=self.schueler,
            vorname='Beate',
            nachname='Beispiel',
            beziehung='mutter',
            religion_wie_schuelerin=True,
            staatsangehoerigkeit_wie_schuelerin=True,
            adresse_wie_schuelerin=True,
        )
        formular = ErziehungsberechtigterAdminForm(instance=erziehungsberechtigter)

        for feld in ('religion', 'staatsangehoerigkeit', 'strasse', 'plz', 'ort'):
            self.assertTrue(formular.fields[feld].disabled)
        self.assertEqual(formular.initial['religion'], self.religion)
        self.assertEqual(
            formular.initial['staatsangehoerigkeit'],
            self.staatsangehoerigkeit,
        )
        self.assertEqual(formular.initial['strasse'], 'Schülerstraße 2')

    def test_beim_erstmaligen_aktivieren_werden_felder_im_formular_deaktiviert(self):
        formular = ErziehungsberechtigterAdminForm(
            data={
                'schueler': str(self.schueler.pk),
                'religion_wie_schuelerin': 'on',
            }
        )

        self.assertTrue(formular.fields['religion'].disabled)
        self.assertEqual(formular.initial['religion'], self.religion)


class SemicolonCSVTests(TestCase):
    """Prüft das CSV-Importformat für Excel-Dateien mit Semikolon."""

    def test_importiert_semikolon_csv_mit_utf8_bom(self):
        csv_daten = (
            '\ufeffvorname;nachname;geburtsdatum\n'
            'Anna;Beispiel;2015-03-14\n'
        ).encode('utf-8')

        dataset = SemicolonCSV().create_dataset(csv_daten)

        self.assertEqual(
            dataset.headers,
            ['vorname', 'nachname', 'geburtsdatum'],
        )
        self.assertEqual(
            dataset.dict[0],
            {
                'vorname': 'Anna',
                'nachname': 'Beispiel',
                'geburtsdatum': '2015-03-14',
            },
        )

    def test_schueler_admin_bietet_eindeutige_importmodi_an(self):
        schueler_admin = SchuelerAdmin(Schueler, admin.site)
        formate = schueler_admin.get_import_formats()
        titel = [formatklasse().get_title() for formatklasse in formate]

        self.assertEqual(
            titel[:2],
            [
                'CSV (Semikolon) – neue Schülerinnen ohne ID',
                'CSV (Semikolon) – Schülerinnen aktualisieren mit ID',
            ],
        )
        self.assertNotIn('csv', titel)

    def test_admin_uebergibt_den_gewaehlten_importmodus(self):
        schueler_admin = SchuelerAdmin(Schueler, admin.site)

        neue_importdaten = schueler_admin.get_import_data_kwargs(
            form=SimpleNamespace(cleaned_data={'format': '0'})
        )
        update_importdaten = schueler_admin.get_import_data_kwargs(
            form=SimpleNamespace(cleaned_data={'format': '1'})
        )

        self.assertEqual(neue_importdaten['schueler_import_mode'], 'create_only')
        self.assertEqual(update_importdaten['schueler_import_mode'], 'update_only')

    def test_erziehungsberechtigten_admin_bietet_semikolon_importformate_an(self):
        admin_eb = ErziehungsberechtigterAdmin(Erziehungsberechtigter, admin.site)
        formate = admin_eb.get_import_formats()

        self.assertEqual(
            [formatklasse().get_title() for formatklasse in formate[:2]],
            [
                'CSV (Semikolon) – neue Erziehungsberechtigte ohne ID',
                'CSV (Semikolon) – Erziehungsberechtigte aktualisieren mit ID',
            ],
        )
        self.assertNotIn('csv', [formatklasse().get_title() for formatklasse in formate])
        self.assertEqual(
            admin_eb.get_import_data_kwargs(
                form=SimpleNamespace(cleaned_data={'format': '0'})
            )['erziehungsberechtigter_import_mode'],
            'create_only',
        )
        self.assertEqual(
            admin_eb.get_import_data_kwargs(
                form=SimpleNamespace(cleaned_data={'format': '1'})
            )['erziehungsberechtigter_import_mode'],
            'update_only',
        )

    def test_erziehungsberechtigten_semikolon_format_parst_semikolon_header(self):
        dataset = NewErziehungsberechtigterSemicolonCSV().create_dataset(
            'schueler;vorname;nachname;beziehung\n1;Bea;Beispiel;mutter'
        )

        self.assertEqual(
            dataset.headers,
            ['schueler', 'vorname', 'nachname', 'beziehung'],
        )
        self.assertEqual(dataset.dict[0]['schueler'], '1')

    def test_neuanlage_erziehungsberechtigter_ohne_id(self):
        schueler = schueler_mit_pflichtangaben()
        schueler.save()
        dataset = tablib.Dataset(
            [schueler.pk, 'Bea', 'Beispiel', 'mutter'],
            headers=['schueler', 'vorname', 'nachname', 'beziehung'],
        )

        ergebnis = ErziehungsberechtigterResource().import_data(
            dataset,
            dry_run=False,
            use_transactions=True,
            erziehungsberechtigter_import_mode='create_only',
        )

        self.assertFalse(ergebnis.has_errors())
        self.assertFalse(ergebnis.has_validation_errors())
        self.assertEqual(Erziehungsberechtigter.objects.count(), 1)

    def test_neuanlage_erziehungsberechtigter_mit_id_wird_abgewiesen(self):
        schueler = schueler_mit_pflichtangaben()
        schueler.save()
        dataset = tablib.Dataset(
            [123, schueler.pk, 'Bea', 'Beispiel', 'mutter'],
            headers=['id', 'schueler', 'vorname', 'nachname', 'beziehung'],
        )

        ergebnis = ErziehungsberechtigterResource().import_data(
            dataset,
            dry_run=False,
            use_transactions=True,
            erziehungsberechtigter_import_mode='create_only',
        )

        self.assertTrue(ergebnis.has_validation_errors())
        self.assertEqual(Erziehungsberechtigter.objects.count(), 0)

    def test_update_mit_gueltiger_erziehungsberechtigten_id(self):
        schueler = schueler_mit_pflichtangaben()
        schueler.save()
        kontakt = Erziehungsberechtigter.objects.create(
            schueler=schueler,
            vorname='Bea',
            nachname='Beispiel',
            beziehung='mutter',
        )
        dataset = tablib.Dataset(
            [kontakt.pk, 'Aktualisiert'],
            headers=['id', 'nachname'],
        )

        ergebnis = ErziehungsberechtigterResource().import_data(
            dataset,
            dry_run=False,
            use_transactions=True,
            erziehungsberechtigter_import_mode='update_only',
        )

        kontakt.refresh_from_db()
        self.assertFalse(ergebnis.has_errors())
        self.assertEqual(kontakt.nachname, 'Aktualisiert')
        self.assertEqual(Erziehungsberechtigter.objects.count(), 1)

    def test_update_unbekannter_erziehungsberechtigten_id_legt_keinen_datensatz_an(self):
        dataset = tablib.Dataset(
            [987654321, 'Nicht neu anlegen'],
            headers=['id', 'nachname'],
        )

        ergebnis = ErziehungsberechtigterResource().import_data(
            dataset,
            dry_run=False,
            use_transactions=True,
            erziehungsberechtigter_import_mode='update_only',
        )

        self.assertTrue(ergebnis.has_validation_errors())
        self.assertEqual(Erziehungsberechtigter.objects.count(), 0)

    def test_importhinweis_unterscheidet_neuanlage_und_update(self):
        html = render_to_string(
            'admin/import_export/resource_fields_list.html',
            {
                'import_or_export': 'import',
                'opts': Schueler._meta,
                'fields_list': [],
            },
        )

        self.assertIn('neue Schülerinnen ohne ID', html)
        self.assertIn('aktualisieren mit ID', html)
        self.assertIn('unbekannte oder leere IDs', html)
        self.assertIn(
            'vorname;nachname;geburtsdatum;strasse;plz;ort;geburtsort',
            html,
        )
        self.assertIn('id;vorname;nachname;geburtsdatum', html)

    def test_erziehungsberechtigten_importhinweis_unterscheidet_neuanlage_und_update(self):
        html = render_to_string(
            'admin/import_export/resource_fields_list.html',
            {
                'import_or_export': 'import',
                'opts': Erziehungsberechtigter._meta,
                'fields_list': [],
            },
        )

        self.assertIn('neue Erziehungsberechtigte ohne ID', html)
        self.assertIn('aktualisieren mit ID', html)
        self.assertIn('import_erziehungsberechtigte_template.csv', html)
        self.assertIn('update_erziehungsberechtigte_template.csv', html)
        self.assertIn('schueler;vorname;nachname;geburtsdatum', html)
        self.assertIn('id;schueler;vorname;nachname;geburtsdatum', html)
        self.assertIn('die Datenbank-ID der zugehörigen Schülerin', html)

    def test_neuanlage_mit_semikolon_csv_legt_datensatz_an(self):
        dataset = tablib.Dataset(
            ['Anna', 'Beispiel', '2015-03-14', 'Weg 1', '12345', 'Ort', 'Ort'],
            headers=[
                'vorname', 'nachname', 'geburtsdatum', 'strasse',
                'plz', 'ort', 'geburtsort',
            ],
        )

        ergebnis = SchuelerResource().import_data(
            dataset,
            dry_run=False,
            use_transactions=True,
            schueler_import_mode='create_only',
        )

        self.assertFalse(ergebnis.has_errors())
        self.assertTrue(ergebnis.has_validation_errors() is False)
        self.assertEqual(Schueler.objects.count(), 1)

    def test_neuanlage_mit_id_wird_abgewiesen_und_aendert_keinen_datensatz(self):
        schueler = schueler_mit_pflichtangaben()
        schueler.save()
        dataset = tablib.Dataset(
            [schueler.pk, 'Neuer Nachname'],
            headers=['id', 'nachname'],
        )

        ergebnis = SchuelerResource().import_data(
            dataset,
            dry_run=False,
            use_transactions=True,
            schueler_import_mode='create_only',
        )

        schueler.refresh_from_db()
        self.assertTrue(ergebnis.has_validation_errors())
        self.assertEqual(schueler.nachname, 'Beispiel')
        self.assertEqual(Schueler.objects.count(), 1)

    def test_update_mit_gueltiger_id_aendert_bestehenden_datensatz(self):
        schueler = schueler_mit_pflichtangaben()
        schueler.save()
        dataset = tablib.Dataset(
            [schueler.pk, 'Aktualisiert'],
            headers=['id', 'nachname'],
        )

        ergebnis = SchuelerResource().import_data(
            dataset,
            dry_run=False,
            use_transactions=True,
            schueler_import_mode='update_only',
        )

        schueler.refresh_from_db()
        self.assertFalse(ergebnis.has_errors())
        self.assertEqual(schueler.nachname, 'Aktualisiert')
        self.assertEqual(Schueler.objects.count(), 1)

    def test_update_mit_unbekannter_id_legt_keinen_neuen_datensatz_an(self):
        dataset = tablib.Dataset(
            [987654321, 'Nicht neu anlegen'],
            headers=['id', 'nachname'],
        )

        ergebnis = SchuelerResource().import_data(
            dataset,
            dry_run=False,
            use_transactions=True,
            schueler_import_mode='update_only',
        )

        self.assertTrue(ergebnis.has_validation_errors())
        self.assertEqual(Schueler.objects.count(), 0)
