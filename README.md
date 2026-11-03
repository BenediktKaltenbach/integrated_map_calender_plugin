# Calendar Plugin

WordPress plugin that brings together what [DTRV-Termine](https://github.com/BenediktKaltenbach/plugin_calenders) and [My Map Plugin](https://github.com/BenediktKaltenbach/map_plugin) do on dtrv.net, made configurable so it can serve other sites too. First used on [iksa-footbike.com](https://iksa-footbike.com).

- **Events** from any number of Google Calendars (one per category, read-only via the secret iCal address) plus events entered on the website.
- **Map** (Leaflet) with permanent places in colour-coded categories and upcoming events.
- **Map and calendar in tabs** (`[calp_explorer]`), each tab with its own address (`#map`, `#calendar`).
- **Member countries** with round flags and contact persons, shown separately (`[calp_countries]`).
- **Articles** (news, blog, history …) with a public submission form.
- **A page per event**: every upcoming event gets a normal WordPress page – info box (date, place, picture, buttons) on top, the description below, editable like any page. *Info* leads to the external info link if there is one, otherwise to this page.
- **Review by mail** for event suggestions, place suggestions and articles: every reviewer of the area gets personal *Approve / Edit / Reject* links that work without logging in.
- **Contact form** mailed to a configurable list of addresses.

The UI is English and translatable (text domain `calendar-plugin`).

## Installation

1. Download `calendar-plugin.zip` from Releases (not "Source code", it lacks `vendor/`).
2. WordPress › Plugins › Add New › Upload Plugin › activate.
3. Settings › Calendar Plugin.

Requires PHP 8.1+ and WordPress 6.5+.

## Settings (Settings › Calendar Plugin)

| Tab | What |
| --- | --- |
| Events | Categories: name, slug, colour, Google Calendar iCal address (optional), shown on map. Event suggestions on/off. Event pages on/off and their parent page. |
| Map | Centre, zoom, height, place suggestions on/off, events on the map on/off. |
| Review | Reviewer addresses per area and contact form recipients (one per line; empty = site admin), sender name. |
| Countries & articles | Article categories, public contact address and text for countries without a representative. |
| Shortcodes & tools | Sync now, cron URL for the host, shortcode overview, import from the old "IKSA Site Kit". |

Let the host call the cron URL hourly (All-Inkl: KAS › Tools › Cronjobs). WP-Cron is only a fallback.

## Shortcodes

| Shortcode | Purpose |
| --- | --- |
| `[calp_explorer tabs="map,calendar" default="map" category="" months="0"]` | Map and calendar with tabs |
| `[calp_map height="550"]` | Map alone |
| `[calp_events category="eurocup,championship" count="10" months="12" past="0" filter="1"]` | Event list grouped by month, with category buttons |
| `[calp_event_form]` | Suggest an event |
| `[calp_countries contacts="1"]` | Countries as round flags, optionally with contacts |
| `[calp_articles category="news" count="6"]` | Article cards |
| `[calp_article_form]` | Send in an article |
| `[calp_contact_form]` | General contact form |
| `[calp_event_info id="123"]` | Info box of an event (placed on event pages automatically) |

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
| `Category:` / `Kategorie:` / `Type:` | slug or name of a category of the site | puts the event into that category (one calendar can feed several categories) |

Without coordinates the location text is looked up at OpenStreetMap (Nominatim: max. 15 lookups per sync, 1 per second, cached 30 days). Events deleted in Google are only flagged; an empty feed is only trusted the second time in a row.

## Legacy calendar (two-way copy)

For a Google Calendar that other websites have embedded for years and that should keep working
next to the new category calendars (Settings › Calendar Plugin › Legacy calendar):

- **Legacy → categories:** every event entered in the legacy calendar is copied into one category
  calendar, chosen by rules on its title (`eurocup = eurocup`, `* = other`).
- **Categories → legacy:** every event entered in a category calendar is copied into the legacy
  calendar (needs write access to it; without, only the first direction runs).
- Copies remember their original (private extended property `calp_src`, kept by Google when an
  event is moved). Moving a copy to another category calendar in Google changes its category.
- Changes at the original update the copy; deleting the original deletes the copy. Edit events
  where they were entered. An event with the same title on the same day already in the target
  is not copied again.
- "Preview" lists what would happen without changing anything; "Automatic" runs it with every
  hourly sync, before the category calendars are read.

Needs a Google service account (Calendar API on) with every calendar shared to it
("Make changes to events"); key in `wp-config.php`:

```php
define( 'CALP_GOOGLE_KEY_FILE', '/path/outside/the/webroot/calendar-google.json' );
```

## Filtered links

The category buttons above the list put the choice into the address, e.g. `/calendar/?calp_cat=eurocup#calendar`. Use such links on other pages to open the calendar already filtered.

## Event pages

After each sync (and when a suggestion is approved) every upcoming, not cancelled event without a page gets one – published, below the parent page set under *Events* (pick the calendar page, so new pages do not land in menus that add top-level pages automatically). Each event gets its page only once: a deleted page does not come back. In the event list use *Create page* for older events.

The page starts with the block `[calp_event_info]`, which always shows the current date, place, category, status and the *Register* / *Event website* buttons, followed by the description as normal paragraphs. The picture comes from the suggestion form or the `Image:` line (direct image address or a Google Drive link shared with "anyone with the link"); it becomes the featured image unless the editors chose another one.

## Review by mail

A submission is saved as *pending* and mailed to all reviewers of its area. Links are signed per reviewer with a per-submission secret; a GET only shows a confirmation page (mail scanners open links), the button decides. **Edit** opens a small form to fix title and text, optionally approving in the same step. After the first decision the secret is deleted, so all links stop working, the other reviewers get a short note, and the submitter gets a thank-you mail – after which their address is deleted. Pending posts can also be approved in the admin list (row action *Approve*).

## Development

```
composer install
php tests/run.php
```

The "Check" action lints PHP (8.1 and 8.5) and JavaScript (no regex lookbehind – breaks older Safari) and runs the tests. Tagging `vX.Y.Z` builds the release ZIP; plugin header version and `VERSION` must match the tag.

## Not yet ported from DTRV-Termine

Posts for the start page per event, featured images from calendar *attachments* (the `Image:` line works), writing accepted suggestions to Google Calendar, the difficulty class (Art | Bergfaktor), Gutenberg blocks (use the shortcodes in a Shortcode block for now), German translation file.
