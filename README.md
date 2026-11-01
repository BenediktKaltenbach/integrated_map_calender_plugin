# Calendar Plugin

WordPress plugin that brings together what [DTRV-Termine](https://github.com/BenediktKaltenbach/plugin_calenders) and [My Map Plugin](https://github.com/BenediktKaltenbach/map_plugin) do on dtrv.net, made configurable so it can serve other sites too. First used on [iksa-footbike.com](https://iksa-footbike.com).

- **Events** from any number of Google Calendars (one per category, read-only via the secret iCal address) plus events entered on the website.
- **Map** (Leaflet) with permanent places in colour-coded categories and upcoming events.
- **Map and calendar in tabs** (`[calp_explorer]`), each tab with its own address (`#map`, `#calendar`).
- **Member countries** with round flags and contact persons, shown separately (`[calp_countries]`).
- **Articles** (news, blog, history …) with a public submission form.
- **Review by mail** for event suggestions, place suggestions and articles: every reviewer of the area gets personal *Approve / Edit / Reject* links that work without logging in.

The UI is English and translatable (text domain `calendar-plugin`).

## Installation

1. Download `calendar-plugin.zip` from Releases (not "Source code", it lacks `vendor/`).
2. WordPress › Plugins › Add New › Upload Plugin › activate.
3. Settings › Calendar Plugin.

Requires PHP 8.1+ and WordPress 6.5+.

## Settings (Settings › Calendar Plugin)

| Tab | What |
| --- | --- |
| Events | Categories: name, slug, colour, Google Calendar iCal address (optional), shown on map. Event suggestions on/off. |
| Map | Centre, zoom, height, place suggestions on/off, events on the map on/off. |
| Review | Reviewer addresses per area (one per line; empty = site admin), sender name. |
| Countries & articles | Article categories, public contact address and text for countries without a representative. |
| Shortcodes & tools | Sync now, cron URL for the host, shortcode overview, import from the old "IKSA Site Kit". |

Let the host call the cron URL hourly (All-Inkl: KAS › Tools › Cronjobs). WP-Cron is only a fallback.

## Shortcodes

| Shortcode | Purpose |
| --- | --- |
| `[calp_explorer tabs="map,calendar" default="map" category="" months="0"]` | Map and calendar with tabs |
| `[calp_map height="550"]` | Map alone |
| `[calp_events category="eurocup,championship" count="10" months="12" past="0"]` | Event list grouped by month |
| `[calp_event_form]` | Suggest an event |
| `[calp_countries contacts="1"]` | Countries as round flags, optionally with contacts |
| `[calp_articles category="news" count="6"]` | Article cards |
| `[calp_article_form]` | Send in an article |

## Entering events in Google Calendar

Title, date and location as usual. In the description write free text, then a line `---`, then key lines (English or German keys):

```
Two-day Eurocup with sprint and long race.
---
Info: https://example.org/eurocup
Registration: https://my.raceresult.com/123/
Country: CZ
Status: provisional
Coordinates: 49.52, 17.58
```

| Key | Values | Effect |
| --- | --- | --- |
| `Info:` / `Infos:` | URL | "Info" button |
| `Registration:` / `Anmeldung:` | URL | "Register" button |
| `Country:` / `Land:` | ISO code | flag in front of the title |
| `Status:` | `cancelled` / `postponed` / `provisional` (or German) | note; cancelled events are struck through and hidden on the map |
| `Coordinates:` / `Koordinaten:` | `50.71, 7.13` or a Google Maps / OpenStreetMap link | exact map position |
| `Display:` / `Anzeige:` | free text | shown instead of the date |

Without coordinates the location text is looked up at OpenStreetMap (Nominatim: max. 15 lookups per sync, 1 per second, cached 30 days). Events deleted in Google are only flagged; an empty feed is only trusted the second time in a row.

## Review by mail

A submission is saved as *pending* and mailed to all reviewers of its area. Links are signed per reviewer with a per-submission secret; a GET only shows a confirmation page (mail scanners open links), the button decides. **Edit** opens a small form to fix title and text, optionally approving in the same step. After the first decision the secret is deleted, so all links stop working, the other reviewers get a short note, and the submitter gets a thank-you mail – after which their address is deleted. Pending posts can also be approved in the admin list (row action *Approve*).

## Development

```
composer install
php tests/run.php
```

The "Check" action lints PHP (8.1 and 8.5) and JavaScript (no regex lookbehind – breaks older Safari) and runs the tests. Tagging `vX.Y.Z` builds the release ZIP; plugin header version and `VERSION` must match the tag.

## Not yet ported from DTRV-Termine

Automatic detail pages/posts per event, featured images from calendar attachments, writing accepted suggestions to Google Calendar, the difficulty class (Art | Bergfaktor), Gutenberg blocks (use the shortcodes in a Shortcode block for now), German translation file.
