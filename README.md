# Carrier Ops

A fleet carrier management board for Elite Dangerous: finances and upkeep, the commodity market,
cargo, jump schedule, crew, shipyard and outfitting — read from the journal files the game already
writes on your own machine.

Live at `https://grayflare.space/fc/` — unlisted, and served `noindex, nofollow`.

## Where the data comes from

The player journal, in

```
%USERPROFILE%\Saved Games\Frontier Developments\Elite Dangerous
```

These files belong to you, need no API key or linked account, and are written the instant something
happens.

| Source | Feeds |
| --- | --- |
| `CarrierStats` | Everything at once — crew roster, finance block, space usage, fuel, jump range, docking access. Written when you open the Carrier Management screen |
| `CarrierJump` | Arrivals, plus `StarPos` for galactic co-ordinates |
| `CarrierJumpRequest` / `CarrierJumpCancelled` | The pending jump and its departure time |
| `CarrierTradeOrder` | Buy and sell orders as they are placed |
| `CarrierFinance` | Per-service tariffs and balances |
| `CarrierBankTransfer`, `CarrierDepositFuel`, `Carrier{Ship,Module}Pack` | The ledger |
| `CarrierCrewServices` | Individual service activate / suspend / resume |
| `CarrierDockingPermission`, `CarrierNameChanged`, `CarrierDecommission` | Access, identity, decommission state |
| `Market.json`, `Shipyard.json`, `Outfitting.json` | Commodity, ship and module stock |

Only carrier events are read. The rest of an uploaded journal — where the commander has been, what
they scanned, who they fought, what was said in chat — is parsed, ignored and never stored.

### Companion API, optionally

Two things are not in the journal at all: what the game actually charges for upkeep, and what is in
the carrier's hold. Both are in Frontier's `/fleetcarrier` response.

EDMarketConnector already queries that endpoint and hands the payload to plugins, so the CarrierOps
plugin forwards it and [`lib/capi.php`](lib/capi.php) parses it. Tick **Enable Fleet Carrier CAPI Queries**
in EDMC's Configuration tab and the upkeep panel switches from *estimated* to the game's own figures,
the Cargo tab fills in, and the standing orders become the live book rather than a list of
placements. Nothing needs registering with Frontier.

A payload is recognised by having no `event` key, so the same ingest endpoint takes journals and
`/fleetcarrier` responses without the caller saying which is which.

### Where the numbers come from when a source disagrees

- **Upkeep** is the game's `coreCost + servicesCost` once a Companion API payload has been through.
  Before that it is reconstructed from the published service cost table in [`lib/costs.php`](lib/costs.php)
  and labelled *estimated*. A suspended service still costs its retainer; only selling it removes
  the charge.
- **Standing orders** are only authoritative once the Companion API has read them. The journal
  records an order being *placed* and being *cancelled*, but never being *filled*, so on its own it
  accumulates trades that completed long ago. The page says which it is showing.
- **Arrivals** come from both the journal and the Companion API, which timestamp the same jump about
  a minute apart and disagree on whether a body is named. Arrivals for one system within ten minutes
  are folded together, whichever source is second filling in what the first lacked.
- **Cargo value** is Frontier's valuation of the whole stack, near the galactic average — not a unit
  price, and not what a good market would pay.

## Features

- **Overview** — fuel, jump range, cargo space, balance, docking access, decommission state, and a
  banner for a scheduled jump
- **Upkeep and solvency** — weekly cost per service, jump fees since the last tick, and how long the
  balance lasts in weeks and years. Upkeep is charged 07:00 UTC every Thursday
- **Cargo** — the hold with stolen goods flagged, and a **"Sell where?"** lookup per commodity
- **Market, orders, shipyard, outfitting** — stock, demand and prices
- **Itinerary** — every arrival with how long the carrier stayed, and a jump log
- **Crew** — the roster with install and suspend state per service
- **Finance** — tariffs, reserve, and a deduplicated ledger
- **Public carrier pages and search**, with per-carrier privacy switches. Finances and cargo are
  never public
- **JSON API** for reading and for automated ingestion
- **EDMC plugin** that pushes events as they happen

An empty list distinguishes "never read" from "read, and empty" — being told to upload
`Shipyard.json` when the shipyard is simply bare is a wasted trip.

## Finding somewhere to sell

Each cargo row links to stations within 500 / 1000 / 2000 ly that want that commodity, with demand,
price, pad size and how recently the price was seen. Data comes from
[Ardent](https://ardent-insight.com/) — a public API with no key.

The lookup asks for demand of at least the whole stack and, if nobody will take that much, drops the
floor and says so. Fleet carriers are excluded: their owners set arbitrary prices and they move.
The result is shown against the in-game valuation, and is a ceiling rather than a quote — a market's
price falls as you sell into it.

Ardent enforces no rate limits and asks only that use be respectful, so the restraint lives here:

- answers cached six hours, failures cached ten minutes
- cache keys are bounded — a commodity, one of three radii, and a stack size rounded down to two
  significant figures, so a hold that drains slightly does not miss the cache
- 20 live lookups a minute across the whole site; over that a request is **deferred, never dropped**,
  and the page retries itself once a slot frees
- requests identify themselves with a User-Agent pointing back at the site

The waiting deliberately happens in the browser. This host runs `pm.max_children = 5`, so sleeping
inside a request to pace an outbound call would hold one of five workers and stall the rest of the
domain.

## Keeping it current

Three ways in, in increasing order of laziness:

1. **Drag journals onto `/fc/upload.php`.** No setup at all.
2. **POST to the API** with a key from the settings page.
3. **Install the [EDMC plugin](edmc-plugin/CarrierOps/README.md)** (`/fc/plugin.php`), which sends
   carrier events as the game writes them and has a one-press backfill for everything on disk.

Opening the **Carrier Management** screen in game writes a `CarrierStats` event carrying crew,
finances, fuel, space usage and docking access in one go — the single most useful thing to trigger
if the board looks stale.

Re-posting the same file is harmless: state updates are guarded by event timestamps, so older data
never overwrites newer, and ledger rows are deduplicated on a content hash.

The downloadable plugin zip is built from the source folder:

```powershell
Compress-Archive -Path edmc-plugin\CarrierOps -DestinationPath assets\CarrierOps-edmc.zip -Force
```

Asset URLs carry an mtime query string, because Cloudflare caches by URL and will otherwise keep
serving a replaced file.

## Ownership

A carrier is claimed by the first account to upload a journal containing one of its **owner-only**
events (`CarrierStats`, `CarrierFinance`, `CarrierTradeOrder`, …) — events that can only appear in
the owner's own journal. Once claimed, another account uploading the same journal is refused and
told so. `CarrierJump` is public, so a visitor's journal can still keep a carrier's location current,
though a public event that cannot name the carrier will not create one.

Owners can release a carrier from its Manage tab, which unlinks it without deleting anything.

## Running it

PHP 8 with `pdo_mysql`, behind nginx. No build step, no Composer, no dependencies.

| Variable | Required | Purpose |
| --- | --- | --- |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | yes | MySQL. Tables are namespaced `fc_` and share the database |
| `DB_PORT` | no | Defaults to 3306 |
| `PUBLIC_BASE_URL` | no | Defaults to `https://grayflare.space` |
| `FC_INVITE_CODE` | no | Set it and registration requires the code. Unset means open registration |
| `FC_ADMIN_CODE` | no | Overrides the `.htadmin-code` file below |
| `FC_SMTP_HOST`, `FC_SMTP_PORT`, `FC_SMTP_SECURE`, `FC_SMTP_USER`, `FC_SMTP_PASSWORD` | no | SMTP relay. Defaults match the host's Nextcloud instance; the password otherwise comes from `.htsmtp-password` |
| `FC_MAIL_FROM`, `FC_MAIL_FROM_NAME` | no | Envelope sender. Defaults to `noreply@grayflare.space` |

Tables are created and migrated on first request. A `.schema-version` sentinel next to the code keeps
the check to one `stat()` per request rather than a database round trip; delete it to force a re-run.

### Becoming an admin

Admins can see and take over any carrier, so the role is granted by proving you have access to the
deployment rather than by being first to the registration form. Put a secret in `.htadmin-code` in
the app root and enter it on the settings page.

The `.ht` prefix is load-bearing: nginx here denies any path containing `/\.ht`, so the file cannot be
fetched over HTTP. A plain `.admin-code` would be served as a static file. Delete it to close
promotion entirely.

### Email

Password reset needs a working relay. The defaults are the settings the host's Nextcloud instance
already uses, so on this deployment it works without configuration; the names map one to one:

| Nextcloud | Here |
| --- | --- |
| `mail_smtphost` | `FC_SMTP_HOST` |
| `mail_smtpport` | `FC_SMTP_PORT` |
| `mail_smtpsecure` | `FC_SMTP_SECURE` — `tls` (STARTTLS), `ssl` or `none` |
| `mail_smtpname` | `FC_SMTP_USER` |
| `mail_smtppassword` | `FC_SMTP_PASSWORD`, or `.htsmtp-password` in the app root |
| `mail_from_address` + `mail_domain` | `FC_MAIL_FROM` |

[`lib/mail.php`](lib/mail.php) speaks SMTP directly rather than calling `mail()`: this container has
no real sendmail, and anything it did emit would be filed as spam. The password sits behind the same
`.ht` naming as the admin code, since nginx refuses to serve those and there is no writable
environment here.

A reset link lasts an hour, works once, and using it ends every signed-in session for that account.
Requests are capped at three an hour per account, and the form answers the same way whether or not
the address is registered — otherwise it would be a way to find out who has an account.

## API

Authenticate with an `X-API-Key` header (generate one on the settings page), or use the site session.

```bash
# push journal data or a /fleetcarrier payload
curl -X POST https://grayflare.space/fc/api.php?action=ingest \
  -H "X-API-Key: $KEY" \
  --data-binary @Journal.2026-08-02T120000.01.log

# read a carrier back
curl -H "X-API-Key: $KEY" "https://grayflare.space/fc/api.php?action=carrier&id=K7G-52T"

# public carriers
curl "https://grayflare.space/fc/api.php?action=carriers&q=colonia"
```

`action=carrier` takes either a CarrierID or a callsign. Finance, upkeep and cargo appear only when
the key owns the carrier.

## Layout

Every `.php` in the root is a URL; nothing else is.

```
index.php       dashboard (signed in) or the pitch (signed out)
carrier.php     carrier view, tabbed, plus owner controls
search.php      public carrier list and search
upload.php      drag-and-drop journal upload
plugin.php      EDMC plugin download and install notes
settings.php    profile, password, API key, admin promotion
api.php         JSON API
login.php  register.php  logout.php
forgot.php reset.php   password reset by email

lib/core.php    config, database, sessions, CSRF, formatting, page chrome
lib/schema.php  table definitions and the migration runner
lib/costs.php   service cost table, upkeep and solvency maths
lib/ingest.php  journal parsing and the per-event handlers
lib/capi.php    Companion API /fleetcarrier parsing
lib/market.php  Ardent lookups, caching and rate limiting
lib/render.php  shared page fragments
lib/mail.php    SMTP client

assets/style.css  assets/icon.svg  assets/carrier-*.jpg
assets/CarrierOps-edmc.zip   built from edmc-plugin/
edmc-plugin/CarrierOps/      the plugin source
```

Nothing under `lib/` is ever requested directly, and each file 404s if it is — belt as well as
braces, since a PHP misconfiguration would otherwise serve source. `lib/core.php` defines `FC_ROOT`
for the handful of paths that belong to the deployment rather than the library: `.htadmin-code`,
`.schema-version` and `assets/`.

## Notes

Not affiliated with Frontier Developments. Elite Dangerous is a trademark of Frontier Developments plc.
