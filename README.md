# Carrier Ops

A fleet carrier management board for Elite Dangerous, in the spirit of
[FCMS](https://github.com/FuelRats/FCMS) / fleetcarrier.space, but built to sidestep the
thing that makes that site stop working: the Frontier Companion API login.

Live at `https://grayflare.space/fc/` — unlisted, and served `noindex, nofollow`.

## Why this exists rather than just running FCMS

FCMS is a Python/Pyramid app whose entire data path is Frontier's Companion API (`FCMS/utils/capi.py`).
Every field it shows — balances, upkeep, market, itinerary, crew — comes from one authenticated
`/fleetcarrier` call. That needs an OAuth client registered with Frontier, which cannot be
self-issued, and when that flow breaks the site logs you in and then has nothing to show you.

This reads the **player journal** instead: the files the game already writes to

```
%USERPROFILE%\Saved Games\Frontier Developments\Elite Dangerous
```

They belong to the player, need no API key, no linked account and no approval from anyone, and
they are written the instant something happens rather than whenever a cache expires.

## What the journal gives us

| Source | Feeds |
| --- | --- |
| `CarrierStats` | Everything at once — crew roster, finance block, space usage, fuel, jump range, docking access. Written when you open the Carrier Management screen. |
| `CarrierJump` | Arrivals, plus `StarPos` for galactic co-ordinates |
| `CarrierJumpRequest` / `CarrierJumpCancelled` | The pending jump and its departure time |
| `CarrierTradeOrder` | Standing buy and sell orders |
| `CarrierFinance` | Per-service tariffs and balances |
| `CarrierBankTransfer`, `CarrierDepositFuel`, `Carrier{Ship,Module}Pack` | The ledger |
| `CarrierCrewServices` | Individual service activate / suspend / resume |
| `CarrierDockingPermission`, `CarrierNameChanged`, `CarrierDecommission` | Access, identity, decommission state |
| `Market.json`, `Shipyard.json`, `Outfitting.json` | Commodity, ship and module stock |

Two things the Companion API has that the journal does not:

- **The exact upkeep breakdown.** cAPI returns `finance.coreCost` and `servicesCost` ready-made.
  The journal only says which crew are aboard and whether each service is running, so
  [`_costs.php`](_costs.php) reconstructs the number from the published cost table. A suspended
  service still costs its retainer; only selling it removes the charge.
- **The carrier cargo manifest.** Nothing in the journal reports a carrier's hold.

Both are now available anyway, without any Frontier registration — see below.

## Companion API, without registering with Frontier

EDMarketConnector already holds an approved cAPI client id, already queries `/fleetcarrier`, and
hands the payload to plugins through `capi_fleetcarrier`. The CarrierOps plugin forwards it here and
[`_capi.php`](_capi.php) parses it. Upkeep then shows the game's own figures instead of the
reconstruction, and the Cargo tab fills in.

The alternative — registering at [user.frontierstore.net](https://user.frontierstore.net/) and
running the PKCE flow ourselves — was rejected because cAPI refresh tokens expire **25 days** after
a user authorises. Every owner would have to log back in to Frontier roughly monthly or their data
would silently stop updating. That is a strong candidate for what actually goes wrong with
fleetcarrier.space after login.

A cAPI payload is recognised by having no `event` key, so the same ingest endpoint takes journals
and `/fleetcarrier` responses without the caller saying which is which.

Only carrier events are read. The rest of an uploaded journal — where the commander has been, what
they scanned, who they fought, what was said in chat — is parsed, ignored and never stored.

## Features

- **Overview** — fuel, jump range, cargo space, balance, docking access, decommission state, and a
  banner for a scheduled jump
- **Upkeep and solvency** — weekly cost per service, jump fees accrued since the last tick, and how
  many weeks the balance lasts. Upkeep is charged 07:00 UTC every Thursday; the forecast counts from
  the next one
- **Market, orders, shipyard, outfitting** — stock, demand and prices, plus standing buy/sell orders
- **Itinerary** — every arrival with how long the carrier stayed, and a jump log
- **Crew** — the roster with install and suspend state per service
- **Finance** — tariffs, reserve, and a deduplicated ledger of transfers, fuel deposits and pack
  purchases
- **Public carrier pages and search**, with per-carrier privacy switches. Finances are never public
- **JSON API** for reading and for automated ingestion
- **EDMC plugin** that pushes events as they happen, so nobody has to upload a file

## Keeping it current

Three ways in, in increasing order of laziness:

1. **Drag journals onto `/fc/upload.php`.** Works with no setup at all.
2. **POST to the API** with a key from the settings page.
3. **Install the [EDMC plugin](edmc-plugin/CarrierOps/README.md)** (`/fc/plugin.php`), which watches the
   journal and sends carrier events as the game writes them, and has a one-press backfill for
   everything already on disk.

Opening the **Carrier Management** screen in game writes a `CarrierStats` event carrying crew,
finances, fuel, space usage and docking access in one go — it is the single most useful thing to
trigger if the board looks stale.

The downloadable plugin zip is built from the source folder:

```powershell
Compress-Archive -Path edmc-plugin\CarrierOps -DestinationPath assets\CarrierOps-edmc.zip -Force
```

## Ownership

A carrier is claimed by the first account to upload a journal containing one of its **owner-only**
events (`CarrierStats`, `CarrierFinance`, `CarrierTradeOrder`, …) — events that can only ever appear
in the owner's own journal. Once claimed, another account uploading the same journal is refused, and
told so. `CarrierJump` is public, so a visitor's journal can still keep a carrier's location current.

Owners can release a carrier from its Manage tab, which unlinks it without deleting anything.

## Running it

PHP 8 with `pdo_mysql`, behind nginx. No build step, no Composer, no dependencies.

Configuration comes from the environment (the same variables the rest of the host already sets):

| Variable | Required | Purpose |
| --- | --- | --- |
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | yes | MySQL. Tables are namespaced `fc_` and share the database |
| `DB_PORT` | no | Defaults to 3306 |
| `PUBLIC_BASE_URL` | no | Defaults to `https://grayflare.space` |
| `FC_INVITE_CODE` | no | Set it and registration requires the code. Unset means open registration |
| `FC_ADMIN_CODE` | no | Overrides the `.htadmin-code` file below |
| `FC_DISCORD_LOGIN` | no | `1` enables the Discord sign-in button — see below |

Tables are created on first request. A `.schema-version` sentinel next to the code keeps the check
to one `stat()` per request rather than a database round trip; delete it to force a re-run.

### Becoming an admin

Admins can see and take over any carrier on the board, so the role is granted by proving you have
access to the deployment, not by being the first to reach the registration form. Put a secret in
`.htadmin-code` next to `_lib.php` and enter it on the settings page.

The `.ht` prefix is load-bearing: nginx on this host denies any path containing `/\.ht`, so the file
cannot be fetched over HTTP. A plain `.admin-code` would be served as a static file and hand the
code to anyone who guessed the name. Delete the file to close promotion entirely.

### Discord sign-in

The host already has a Discord OAuth app configured (`DISCORD_CLIENT_ID` / `DISCORD_CLIENT_SECRET`,
used by `/go`), but Discord matches redirect URIs exactly and only `/go/auth.php` is registered.
Until `https://grayflare.space/fc/auth.php` is added in the Discord developer portal the button
would dead-end, so it stays hidden — which is the exact failure mode this app was written to avoid.
Add the URI, set `FC_DISCORD_LOGIN=1`, and it appears.

## API

Authenticate with an `X-API-Key` header (generate one on the settings page), or use the site session.

```bash
# push journal data
curl -X POST https://grayflare.space/fc/api.php?action=ingest \
  -H "X-API-Key: $KEY" \
  --data-binary @Journal.2026-08-02T120000.01.log

# read a carrier back
curl -H "X-API-Key: $KEY" "https://grayflare.space/fc/api.php?action=carrier&id=K7G-52T"

# public carriers
curl "https://grayflare.space/fc/api.php?action=carriers&q=colonia"
```

`action=carrier` takes either a CarrierID or a callsign. Finance and upkeep appear only when the key
owns the carrier.

Re-posting the same file is harmless: state updates are guarded by the event timestamps, so older
data never overwrites newer, and ledger rows are deduplicated on a content hash.

## Layout

```
_lib.php        config, database, sessions, CSRF, formatting, page chrome
_schema.php     table definitions and the migration runner
_costs.php      service cost table, upkeep and solvency maths
_ingest.php     journal parsing and the per-event handlers
_capi.php       Companion API /fleetcarrier parsing
_render.php     shared page fragments
index.php       dashboard (signed in) or the pitch (signed out)
carrier.php     carrier view, tabbed, plus owner controls
search.php      public carrier list and search
upload.php      drag-and-drop journal upload
plugin.php      EDMC plugin download and install notes
settings.php    profile, password, API key, admin promotion
api.php         JSON API
login.php  register.php  logout.php
assets/style.css
assets/CarrierOps-edmc.zip   built from edmc-plugin/
edmc-plugin/CarrierOps/      the plugin source
```

Files starting with `_` 404 if requested directly, matching the convention used by `/go`.

## Notes

Not affiliated with Frontier Developments. Elite Dangerous is a trademark of Frontier Developments plc.
