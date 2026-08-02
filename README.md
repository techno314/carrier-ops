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
  service still costs its retainer; only selling it removes the charge. The UI labels this
  *estimated* because it is.
- **The carrier cargo manifest.** The market snapshot's stock figures stand in.

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
| `FC_DISCORD_LOGIN` | no | `1` enables the Discord sign-in button — see below |

Tables are created on first request. A `.schema-version` sentinel next to the code keeps the check
to one `stat()` per request rather than a database round trip; delete it to force a re-run.

**The first account to register becomes the admin.**

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
_render.php     shared page fragments
index.php       dashboard (signed in) or the pitch (signed out)
carrier.php     carrier view, tabbed, plus owner controls
search.php      public carrier list and search
upload.php      drag-and-drop journal upload
settings.php    profile, password, API key
api.php         JSON API
login.php  register.php  logout.php
assets/style.css
```

Files starting with `_` 404 if requested directly, matching the convention used by `/go`.

## Notes

Not affiliated with Frontier Developments. Elite Dangerous is a trademark of Frontier Developments plc.
