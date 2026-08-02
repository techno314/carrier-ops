# Carrier Ops — EDMC plugin

Pushes fleet carrier events from your journal to a [Carrier Ops](https://grayflare.space/fc/) board
as they happen, so you never upload a file by hand.

## Install

1. In EDMarketConnector: **File → Settings → Plugins → Open** (this opens your plugins folder).
2. Copy the `CarrierOps` folder into it.
3. Restart EDMC.
4. **File → Settings → Carrier Ops**, paste the API key from the board's
   [settings page](https://grayflare.space/fc/settings.php), and press **Test connection**.

The plugins folder is normally:

```
%LOCALAPPDATA%\EDMarketConnector\plugins
```

## Using it

Once a key is set it runs by itself. The main EDMC window shows a one-line status — `sent 4`,
`up to date`, `offline`, `bad API key`.

- Carrier events are batched and sent every 20 seconds. `CarrierStats`, `CarrierJump`,
  `CarrierJumpRequest`, `CarrierJumpCancelled` and `CarrierLocation` go immediately, because those
  are the ones somebody may be watching the board for.
- `Market.json`, `Shipyard.json` and `Outfitting.json` are read off disk and sent whole whenever the
  game writes one for a carrier — the journal event itself only names the station, the item list
  lives in the file.
- **Upload past journals** in the settings tab scans every journal you still have and sends the
  carrier events out of them. Worth pressing once, after connecting; it will populate years of jump
  history and finances in one go.

Opening the **Carrier Management** screen in game writes a `CarrierStats` event, which carries crew,
finances, fuel, space usage and docking access all at once. It is the single most useful thing to
trigger if the board looks out of date.

## Exact upkeep and the cargo hold

Two things are simply not in the journal: what the game actually charges for upkeep, and what is in
the carrier's hold. Both are in Frontier's Companion API — and EDMC already holds an approved client
id and queries it for you.

Tick **Enable Fleet Carrier CAPI Queries** in EDMC's *Configuration* tab. The plugin then forwards
each `/fleetcarrier` payload EDMC receives, and the board's upkeep panel switches from *estimated*
to the game's own figures, with a Cargo tab alongside.

EDMC asks Frontier at most every 15 minutes, and only after events like `CarrierStats`,
`CarrierLocation` or `CargoTransfer`, so this supplements the journal feed rather than replacing it.
The plugin's settings tab reports whether the option is currently on.

This needs nothing from Frontier. Registering your own Companion API client is possible — apply at
[user.frontierstore.net](https://user.frontierstore.net/) — but its refresh tokens expire 25 days
after authorising, so every user would have to log in again roughly monthly. Going through EDMC has
no such expiry.

## What it sends

Only fleet carrier events, listed explicitly in `CARRIER_EVENTS` at the top of `load.py`. Every
other journal entry EDMC hands the plugin is checked for its event name and then dropped — nothing
about where you have been, what you scanned, who you fought or what was said in chat leaves your
machine.

Snapshot files are only sent when the station is a carrier. `Market.json` says so directly;
`Shipyard.json` and `Outfitting.json` do not carry a station type at all, so the plugin matches the
market id against a carrier it has already seen this session, falling back to the shape of the
station name — carrier callsigns look like `V4H-84Q`, which no starport does. The board re-checks
that you own the carrier regardless, so a wrong guess is a rejected upload rather than bad data.

## Settings

| Setting | Meaning |
| --- | --- |
| Send carrier events | Master switch. Nothing is sent without an API key either way |
| Board URL | Defaults to `https://grayflare.space/fc` |
| API key | From the board's settings page. Stored in EDMC's own config |

## Troubleshooting

**`bad API key`** — the key was revoked or mistyped. Generate a new one on the settings page.

**`already claimed by another account`** — someone else's account claimed that carrier first. The
current owner can release it from the carrier's Manage tab.

**Nothing happens** — carrier events only appear in the journal when you interact with the carrier.
Dock at it and open Carrier Management.

EDMC's log (**File → Settings → Configuration → Open Log Folder**) has the detail; the plugin logs
under `EDMarketConnector.CarrierOps`.
