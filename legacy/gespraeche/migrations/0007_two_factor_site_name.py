from django.db import migrations


def set_two_factor_site_name(apps, schema_editor):
    Site = apps.get_model('sites', 'Site')
    site, _ = Site.objects.get_or_create(
        pk=1,
        defaults={
            'domain': 'localhost',
            'name': 'MSO-Anmeldeverwaltung',
        },
    )
    if site.name != 'MSO-Anmeldeverwaltung':
        site.name = 'MSO-Anmeldeverwaltung'
        site.save(update_fields=['name'])


class Migration(migrations.Migration):

    dependencies = [
        ('gespraeche', '0006_alter_erziehungsberechtigter_email'),
        ('sites', '0002_alter_domain_unique'),
    ]

    operations = [
        migrations.RunPython(set_two_factor_site_name, migrations.RunPython.noop),
    ]
