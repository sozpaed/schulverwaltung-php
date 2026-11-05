"""
Django-Einstellungen für das Projekt "Schulverwaltung".

Alle Werte, die sich zwischen Entwicklungsrechner und Server unterscheiden
oder geheim sind, stehen in der Datei .env (wird NICHT versioniert).
Vorlage: .env.example

Urheber: Christian Leeser mit KI Claude, Gemini und Copilot
Version: 0.1.0
Datei: settings.py
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

from pathlib import Path

from decouple import Csv, config

# Absoluter Projektpfad; wird unter anderem für Templates, SQLite und statische
# Dateien verwendet und ist unabhängig vom aktuellen Startverzeichnis.
BASE_DIR = Path(__file__).resolve().parent.parent


# --- Sicherheit / Umgebung (aus der .env) ---

# Pflichtwert ohne unsicheren Standard: Fehlt SECRET_KEY in der Umgebung oder
# .env-Datei, bricht der Start hier bewusst mit einem Konfigurationsfehler ab.
SECRET_KEY = config('SECRET_KEY')

# Auf dem Server False lassen, damit Django keine detaillierten Fehlermeldungen
# oder Debug-Informationen an Besucher ausliefert.
DEBUG = config('DEBUG', default=False, cast=bool)

# Csv zerlegt kommagetrennte Umgebungsvariablen in Listen für Django.
ALLOWED_HOSTS = config('ALLOWED_HOSTS', default='127.0.0.1,localhost', cast=Csv())

# Bei HTTPS: Adressen mit Schema, z. B. https://schule.example.de
# Diese Ursprünge dürfen Django-Formulare mit CSRF-Schutz absenden.
CSRF_TRUSTED_ORIGINS = config('CSRF_TRUSTED_ORIGINS', default='', cast=Csv())

# Erst auf True setzen, wenn Nginx mit HTTPS eingerichtet ist.
# Der Schalter aktiviert weiter unten die HTTPS-Weiterleitung und sichere Cookies.
USE_HTTPS = config('USE_HTTPS', default=False, cast=bool)


# --- Anwendungen ---

# Neben Djangos Admin-, Authentifizierungs-, Sitzungs-, Nachrichten- und
# Staticfiles-Anwendungen sind Import/Export und die fachliche App installiert.
INSTALLED_APPS = [
    'django.contrib.admin',
    'django.contrib.auth',
    'django.contrib.contenttypes',
    'django.contrib.sessions',
    'django.contrib.messages',
    'django.contrib.sites',
    'django.contrib.staticfiles',
    'django_otp',
    'django_otp.plugins.otp_static',
    'django_otp.plugins.otp_totp',
    'two_factor',
    'import_export',
    'gespraeche',
]

# Reihenfolge der HTTP-Verarbeitung: Sicherheits- und Staticfiles-Middleware
# stehen vor den Sitzungs-, CSRF-, Authentifizierungs- und Nachrichtenkomponenten.
MIDDLEWARE = [
    'django.middleware.security.SecurityMiddleware',
    'whitenoise.middleware.WhiteNoiseMiddleware',
    'django.contrib.sessions.middleware.SessionMiddleware',
    'django.middleware.common.CommonMiddleware',
    'django.middleware.csrf.CsrfViewMiddleware',
    'django.contrib.auth.middleware.AuthenticationMiddleware',
    'django_otp.middleware.OTPMiddleware',
    'django.contrib.messages.middleware.MessageMiddleware',
    'django.middleware.clickjacking.XFrameOptionsMiddleware',
]

ROOT_URLCONF = 'schulverwaltung.urls'

# Anzeigename, den TOTP-Apps beim Einrichten des Kontos anzeigen.
SITE_ID = 1

# Django-Admin-Anmeldungen laufen über den Zwei-Faktor-Login.
LOGIN_URL = 'two_factor:login'
LOGIN_REDIRECT_URL = 'two_factor:profile'
TWO_FACTOR_PATCH_ADMIN = True

# Django sucht sowohl unter dem Projektverzeichnis ``templates`` als auch
# innerhalb installierter Apps nach Templates. Die Context-Prozessoren stellen
# Request, angemeldeten Benutzer und Nachrichten in Templates bereit.
TEMPLATES = [
    {
        'BACKEND': 'django.template.backends.django.DjangoTemplates',
        'DIRS': [BASE_DIR / 'templates'],
        'APP_DIRS': True,
        'OPTIONS': {
            'context_processors': [
                'django.template.context_processors.request',
                'django.contrib.auth.context_processors.auth',
                'django.contrib.messages.context_processors.messages',
            ],
        },
    },
]

WSGI_APPLICATION = 'schulverwaltung.wsgi.application'


# --- Datenbank ---
# Ist DB_NAME in der .env gesetzt, wird PostgreSQL verwendet (Server).
# Ohne DB_NAME wird SQLite genutzt (einfacher Test auf dem Entwicklungsrechner).

# Schon das Setzen eines DB_NAME wählt PostgreSQL; in diesem Zweig sind Name,
# Benutzer und Passwort Pflichtangaben, während Host und Port Defaults haben.
if config('DB_NAME', default=''):
    DATABASES = {
        'default': {
            'ENGINE': 'django.db.backends.postgresql',
            'NAME': config('DB_NAME'),
            'USER': config('DB_USER'),
            'PASSWORD': config('DB_PASSWORD'),
            'HOST': config('DB_HOST', default='localhost'),
            'PORT': config('DB_PORT', default='5432'),
        }
    }
else:
    # Die SQLite-Datei liegt im Projektverzeichnis und benötigt keine separaten
    # Server-Zugangsdaten.
    DATABASES = {
        'default': {
            'ENGINE': 'django.db.backends.sqlite3',
            'NAME': BASE_DIR / 'db.sqlite3',
        }
    }


# --- Passwörter ---

# Diese Validatoren werden bei Django-Passwortformularen angewendet. Zusätzlich
# zur Ähnlichkeits-, Häufigkeits- und Ziffernprüfung gilt hier eine Mindestlänge
# von zwölf Zeichen.
AUTH_PASSWORD_VALIDATORS = [
    {'NAME': 'django.contrib.auth.password_validation.UserAttributeSimilarityValidator'},
    {'NAME': 'django.contrib.auth.password_validation.MinimumLengthValidator',
     'OPTIONS': {'min_length': 12}},
    {'NAME': 'django.contrib.auth.password_validation.CommonPasswordValidator'},
    {'NAME': 'django.contrib.auth.password_validation.NumericPasswordValidator'},
]


# --- Sprache und Zeit ---

# Deutsche Übersetzungen sowie Berliner Ortszeit; USE_TZ speichert Zeitpunkte
# intern zeitzonenbewusst und konvertiert sie für die Anzeige.
LANGUAGE_CODE = 'de'
TIME_ZONE = 'Europe/Berlin'
USE_I18N = True
USE_TZ = True


# --- Statische Dateien (WhiteNoise) ---

# STATIC_URL ist die URL-Basis. collectstatic sammelt Dateien unter STATIC_ROOT;
# WhiteNoise liefert sie anschließend mit komprimiertem Manifest aus.
STATIC_URL = 'static/'
STATIC_ROOT = BASE_DIR / 'staticfiles'

STORAGES = {
    'default': {'BACKEND': 'django.core.files.storage.FileSystemStorage'},
    'staticfiles': {'BACKEND': 'whitenoise.storage.CompressedManifestStaticFilesStorage'},
}


# --- HTTPS-Absicherung (greift erst mit USE_HTTPS=True) ---

if USE_HTTPS:
    # Nginx terminiert TLS und signalisiert das ursprüngliche Protokoll per Header.
    # Django vertraut diesem Header nur in der dafür vorgesehenen Proxy-Umgebung.
    SECURE_PROXY_SSL_HEADER = ('HTTP_X_FORWARDED_PROTO', 'https')
    SECURE_SSL_REDIRECT = True
    SESSION_COOKIE_SECURE = True
    CSRF_COOKIE_SECURE = True
    # HSTS wird zunächst eine Stunde gesetzt; erst nach geprüfter HTTPS-
    # Konfiguration sollte die Laufzeit wie oben beschrieben erhöht werden.
    SECURE_HSTS_SECONDS = 3600  # später auf 31536000 (1 Jahr) erhöhen

# Verhindert MIME-Typ-Raten durch Browser und das Einbetten der Seite in Frames.
SECURE_CONTENT_TYPE_NOSNIFF = True
X_FRAME_OPTIONS = 'DENY'

# Primärschlüssel für Modelle ohne ausdrücklich angegebenen Primärschlüsseltyp.
DEFAULT_AUTO_FIELD = 'django.db.models.BigAutoField'


# --- Protokollierung ---
# Exporte werden mit Benutzer, Bereich und Anzahl der Datensätze protokolliert.
# Die Meldungen erscheinen im Journal: journalctl -u schulverwaltung | grep EXPORT
# Dieser Logger wird von den Exportfunktionen angesprochen; er schreibt auf
# stdout und gibt Meldungen nicht zusätzlich an den Root-Logger weiter.
LOGGING = {
    'version': 1,
    'disable_existing_loggers': False,
    'handlers': {
        'console': {'class': 'logging.StreamHandler'},
    },
    'loggers': {
        'export': {
            'handlers': ['console'],
            'level': 'INFO',
            'propagate': False,
        },
    },
}