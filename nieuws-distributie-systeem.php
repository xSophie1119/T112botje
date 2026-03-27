# Nieuws Distributie Systeem v24.0.0

WordPress-plugin voor Tilburg112 (en vergelijkbare nieuwssites) voor P2000-monitoring en persberichtdistributie.

## Vereisten

| Software    | Minimale versie |
|-------------|----------------|
| WordPress   | 6.4            |
| PHP         | 8.1            |
| PHP ZipArchive | Aanbevolen  |

---

## Installatie

1. Pak de plugin-map uit in `/wp-content/plugins/nieuws-distributie-systeem/`
2. Activeer de plugin via **Plugins → Geïnstalleerde plugins**
3. Ga naar **Distributie → Instellingen** en configureer:
   - P2000 RSS-feed URLs (Brandweer / Politie / MMT)
   - Te monitoren steden
   - E-mail instellingen (afzendernaam + e-mailadres)
4. Voeg media-outlets toe via het **Outlets**-tabblad
5. Maak een WordPress-pagina aan met de slug `persportaal` *(de plugin onderschept dit URL automatisch)*

---

## Migratie van v23 → v24

De plugin slaat outlets nu op onder de optie-sleutel `snd_media_outlets_v4` (was `media_outlets_list_v3`).

**Voer éénmalig dit migratiescript uit** via de WP-CLI of een tijdelijk snippet:

```php
// Éénmalig uitvoeren, daarna verwijderen
$old = get_option('media_outlets_list_v3', []);
if (!empty($old) && empty(get_option('snd_media_outlets_v4'))) {
    update_option('snd_media_outlets_v4', $old);
}
```

Alle andere opties (`snd_p2000_*`, `snd_email_*`, etc.) en post-meta zijn ongewijzigd.

---

## Functionaliteiten

### P2000 Integratie
- Haalt elke **15 minuten** automatisch meldingen op via RSS (WP-Cron)
- Filtert op stad(en) en optioneel op urgentie (A0/A1/A2/B1/B2)
- Geocodeert locaties via Nominatim/OpenStreetMap (gecached)
- Eén klik om een WordPress-bericht aan te maken vanuit een melding

### Verzend-Dashboard
- Verstuurt persberichten per e-mail naar geselecteerde outlets
- Beheer van watermerkvrije persfoto's per bericht
- Verzendgeschiedenis per bericht
- Testmodus (geen logging)

### Persportaal
- Beveiligd via unieke toegangscode per outlet (32 tekens, URL-based)
- Toont artikeltekst, locatiekaart en persfoto's
- Download individuele foto's of alles als ZIP
- Live-verhaal indicator

### Rapportages & Statistieken
- Per bericht: wie heeft geopend, wanneer, welke foto's gedownload
- Totaaloverzicht meest actieve outlets

### Shortcode
```
[incidenten_lijst]
```
Toont een realtime lijst van de laatste P2000-meldingen op elke pagina of post.

---

## Bestandsstructuur

```
nieuws-distributie-systeem/
├── nieuws-distributie-systeem.php   # Plugin entry point
├── css/
│   ├── admin.css                    # Admin stylesheet
│   └── public.css                  # Frontend stylesheet
├── includes/
│   ├── class-snd-activator.php      # Activatie / deactivatie
│   ├── class-snd-admin.php          # Volledige admin interface
│   ├── class-snd-p2000.php          # P2000 feed fetcher
│   └── class-snd-portal.php         # Persportaal frontend
├── js/
│   ├── admin-dispatch.js            # Verzend-dashboard JS
│   ├── admin-location-modal.js      # Kaart meta-box JS
│   └── admin-reports.js             # Rapportages JS
└── templates/
    ├── email-template.php           # HTML e-mail
    └── press-portal.php             # Persportaal template
```

---

## Wijzigingen t.o.v. v23

- **PHP 8.1+** syntax: named arguments, arrow functions, enums, readonly properties
- **Autoloader** via `spl_autoload_register` (geen handmatige `require_once` meer)
- `SND_P2000` is een aparte class (single responsibility)
- `SND_Activator` class voor nette activatie/deactivatie
- Alle AJAX handlers gebruiken nu `wp_unslash()` + typed sanitization
- Nonce-namen gestandaardiseerd (`snd_nonce`, `snd_location_nonce`, `snd_portal_nonce`)
- JavaScript: moderne ES6+ (`async/await`, `Promise.all`, `const/let`, template literals)
- Instellingen-pagina: tab-navigatie via URL-param (bookmarkbaar)
- Portaal: toegangscode nu 32 tekens, `hash_equals()` vergelijking behouden
- E-mail template: modern design, geen inline font-size herhaling
- Volledig i18n-gereed (`__()`, `esc_html_e()`)
