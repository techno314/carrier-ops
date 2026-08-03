"""
Carrier Ops — an EDMarketConnector plugin.

Watches the journal for fleet carrier events and pushes them to a Carrier Ops
board, so the site stays current without anyone uploading files by hand.

Only carrier events are sent. Everything else EDMC hands this plugin — where
you have been, what you scanned, who you fought, what was said in chat — is
looked at once to check the event name and then dropped.

Two kinds of thing get sent:

  * carrier events straight out of the journal (CarrierStats, CarrierJump,
    CarrierTradeOrder and friends), batched and flushed on a timer;
  * the Market.json / Shipyard.json / Outfitting.json snapshots, which the
    journal only *mentions* -- the actual item lists live in those files, so
    when the matching event arrives the file is read off disk and sent whole.
"""

from __future__ import annotations

import json
import logging
import os
import queue
import re
import threading
import time
import tkinter as tk
from tkinter import ttk
from typing import Any, Optional

import requests

import myNotebook as nb
from config import appname, appversion, config

PLUGIN_NAME = "CarrierOps"
PLUGIN_VERSION = "1.2.0"

CFG_URL = "carrierops_url"
CFG_KEY = "carrierops_apikey"
CFG_ENABLED = "carrierops_enabled"

DEFAULT_URL = "https://grayflare.space/fc"

# Events worth sending. Mirrors the server's own list; anything else is
# ignored before it reaches the queue.
CARRIER_EVENTS = frozenset({
    "CarrierStats",
    "CarrierBuy",
    "CarrierNameChanged",
    "CarrierNameChange",
    "CarrierDockingPermission",
    "CarrierDepositFuel",
    "CarrierBankTransfer",
    "CarrierFinance",
    "CarrierCrewServices",
    "CarrierShipPack",
    "CarrierModulePack",
    "CarrierTradeOrder",
    "CarrierJumpRequest",
    "CarrierJumpCancelled",
    "CarrierJump",
    "CarrierLocation",
    "CarrierDecommission",
    "CarrierCancelDecommission",
})

# Events whose payload is a file on disk rather than the event itself.
SNAPSHOT_FILES = {
    "Market": "Market.json",
    "Shipyard": "Shipyard.json",
    "Outfitting": "Outfitting.json",
}

# Sent the moment they arrive rather than waiting for the batch timer: these
# are the ones somebody might be watching the board for.
URGENT_EVENTS = frozenset({
    "CarrierStats",
    "CarrierJump",
    "CarrierJumpRequest",
    "CarrierJumpCancelled",
    "CarrierLocation",
})

FLUSH_SECONDS = 20.0
MAX_BATCH = 200

# A batch that fails is put back rather than dropped. Sending late beats not
# sending: the board is unreachable for ordinary reasons -- maintenance, a
# restart, a rate limit during a large backfill -- and each of those used to
# cost whatever was in flight. Nothing is lost for ever either way, since the
# journals stay on disk and "Upload past journals" re-reads them, but nobody
# should have to notice a gap and go and fix it by hand.
MAX_ATTEMPTS = 6
BACKOFF_SECONDS = (2, 5, 15, 30, 60)
MAX_RETRY_WAIT = 120.0

# A closed board is a different thing from a broken one. 503 means somebody
# deliberately shut it for a while, and the impatient schedule above gives up
# after about two minutes -- useless against a maintenance window measured in
# hours. So wait quietly and keep the events until it opens.
CLOSED_WAIT_SECONDS = 60.0
MAX_CLOSED_ATTEMPTS = 120

# Carrier callsigns look like V4H-84Q. No starport is named this way, which
# makes it a safe last resort for identifying a carrier's snapshot files.
CALLSIGN_RE = re.compile(r"^[A-Z0-9]{3}-[A-Z0-9]{3}$")

plugin_name = os.path.basename(os.path.dirname(__file__))
logger = logging.getLogger(f"{appname}.{plugin_name}")

if not logger.hasHandlers():
    logger.setLevel(logging.INFO)
    _channel = logging.StreamHandler()
    _channel.setFormatter(logging.Formatter(
        "%(asctime)s - %(name)s - %(levelname)s - %(module)s:%(lineno)d:%(funcName)s: %(message)s"
    ))
    logger.addHandler(_channel)


class CarrierOps:
    """Queue, worker thread and UI state for the plugin."""

    def __init__(self) -> None:
        self.queue: queue.Queue = queue.Queue()
        self.thread: Optional[threading.Thread] = None
        self.stopping = threading.Event()

        # Events waiting for the batch timer, and the lock guarding them.
        self.pending: list[str] = []
        self.pending_lock = threading.Lock()
        self.last_flush = time.monotonic()

        # Market ids seen on carrier events this session; see is_carrier_station.
        self.known_carriers: set[int] = set()

        self.status_var: Optional[tk.StringVar] = None
        self.status_widget: Optional[tk.Widget] = None

        # Bound to the preference widgets while the dialog is open.
        self.pref_url: Optional[tk.StringVar] = None
        self.pref_key: Optional[tk.StringVar] = None
        self.pref_enabled: Optional[tk.IntVar] = None
        self.pref_status: Optional[tk.StringVar] = None

    # -- configuration ----------------------------------------------------

    @property
    def url(self) -> str:
        return config.get_str(CFG_URL) or DEFAULT_URL

    @property
    def api_key(self) -> str:
        return config.get_str(CFG_KEY) or ""

    @property
    def enabled(self) -> bool:
        # Default on: somebody who installed this plugin wants it running.
        # It still does nothing at all without an API key.
        return config.get_bool(CFG_ENABLED, default=True)

    @property
    def configured(self) -> bool:
        return self.enabled and bool(self.api_key)

    def endpoint(self, filename: str) -> str:
        return f"{self.url.rstrip('/')}/api.php?action=ingest&filename={requests.utils.quote(filename)}"

    # -- worker -----------------------------------------------------------

    def start(self) -> None:
        self.stopping.clear()
        self.thread = threading.Thread(target=self._run, name="CarrierOps worker", daemon=True)
        self.thread.start()

    def stop(self) -> None:
        self.flush(force=True)
        self.stopping.set()
        self.queue.put(None)
        if self.thread is not None:
            self.thread.join(timeout=10)
            self.thread = None

    def _run(self) -> None:
        while True:
            try:
                # A short block doubles as the batch timer: if nothing arrives
                # we still wake up often enough to flush what is waiting.
                item = self.queue.get(timeout=1.0)
            except queue.Empty:
                self.flush()
                if self.stopping.is_set():
                    return
                continue

            if item is None:
                return

            # Older items were two-tuples; accept both so an upgrade in flight
            # does not throw.
            label, body, attempt = item if len(item) == 3 else (*item, 0)
            self._send(label, body, attempt)
            self.queue.task_done()

    def _retry(
        self,
        label: str,
        body: str,
        attempt: int,
        why: str,
        wait: Optional[float] = None,
        patient: bool = False,
    ) -> None:
        """Put a batch back, or give up on it and say so."""
        limit = MAX_CLOSED_ATTEMPTS if patient else MAX_ATTEMPTS
        if attempt + 1 >= limit:
            self.set_status(f"gave up: {why}")
            logger.error("Carrier Ops gave up on %s after %s attempts: %s", label, limit, why)
            return

        if wait is not None:
            delay = float(wait)
        elif patient:
            delay = CLOSED_WAIT_SECONDS
        else:
            delay = BACKOFF_SECONDS[min(attempt, len(BACKOFF_SECONDS) - 1)]
        delay = max(0.0, min(delay, MAX_RETRY_WAIT))

        self.set_status(f"{why}, retrying")
        logger.info("Carrier Ops retrying %s in %.0fs (%s)", label, delay, why)

        # This is the plugin's own worker thread and the queue is drained in
        # order, so waiting here delays the next batch rather than racing it --
        # which is what we want when the far end has asked us to slow down.
        if self.stopping.wait(delay):
            return
        self.queue.put((label, body, attempt + 1))

    def _send(self, label: str, body: str, attempt: int = 0) -> None:
        if not self.configured:
            return
        try:
            response = requests.post(
                self.endpoint(label),
                data=body.encode("utf-8"),
                headers={
                    "X-API-Key": self.api_key,
                    "Content-Type": "text/plain; charset=utf-8",
                    "User-Agent": f"{PLUGIN_NAME}/{PLUGIN_VERSION} EDMC/{appversion()}",
                },
                timeout=30,
            )
        except requests.RequestException as err:
            logger.warning("Carrier Ops upload failed: %s", err)
            self._retry(label, body, attempt, "offline")
            return

        if response.status_code == 401:
            # A wrong key will still be wrong in thirty seconds.
            self.set_status("bad API key")
            logger.warning("Carrier Ops rejected the API key")
            return

        if response.status_code == 429:
            # The board says slow down and usually says for how long.
            try:
                wait = float(response.headers.get("Retry-After", ""))
            except ValueError:
                wait = None
            self._retry(label, body, attempt, "rate limited", wait)
            return

        if response.status_code == 503:
            # Maintenance. It ends, and the journal events are still wanted --
            # so wait it out rather than giving up in the first two minutes.
            self._retry(label, body, attempt, "board closed", patient=True)
            return

        if response.status_code >= 500:
            self._retry(label, body, attempt, f"error {response.status_code}")
            return

        if not response.ok:
            # 4xx other than the above is this plugin sending something the
            # board will never accept; repeating it would not help.
            self.set_status(f"error {response.status_code}")
            logger.warning("Carrier Ops returned %s: %s", response.status_code, response.text[:200])
            return

        try:
            result = response.json()
        except ValueError:
            self.set_status("bad response")
            return

        applied = result.get("eventsApplied", 0)
        notes = result.get("notes") or []
        if notes:
            # The usual one is "already claimed by another account", which is
            # worth seeing rather than silently doing nothing forever.
            logger.warning("Carrier Ops: %s", "; ".join(notes))
            self.set_status(notes[0][:40])
            return

        self.set_status(f"sent {applied}" if applied else "up to date")
        logger.debug("Carrier Ops applied %s events from %s", applied, label)

    # -- batching ---------------------------------------------------------

    def enqueue_event(self, entry: dict[str, Any]) -> None:
        line = json.dumps(entry, separators=(",", ":"))
        with self.pending_lock:
            self.pending.append(line)
            overflowing = len(self.pending) >= MAX_BATCH

        if overflowing or entry.get("event") in URGENT_EVENTS:
            self.flush(force=True)

    def flush(self, force: bool = False) -> None:
        if not force and (time.monotonic() - self.last_flush) < FLUSH_SECONDS:
            return

        with self.pending_lock:
            if not self.pending:
                self.last_flush = time.monotonic()
                return
            batch = self.pending
            self.pending = []
            self.last_flush = time.monotonic()

        self.queue.put(("live.log", "\n".join(batch), 0))

    # -- telling a carrier from a starport ---------------------------------

    def remember_carrier(self, entry: dict[str, Any]) -> None:
        """Note a carrier's market id, so its snapshots can be recognised."""
        for key in ("CarrierID", "MarketID"):
            value = entry.get(key)
            if isinstance(value, int):
                self.known_carriers.add(value)

    def is_carrier_station(self, entry: dict[str, Any]) -> bool:
        """
        Whether a Market/Shipyard/Outfitting event belongs to a fleet carrier.

        Only the Market event carries StationType; Shipyard and Outfitting give
        the station name and nothing else. So fall back to a market id already
        seen on a carrier event this session, and finally to the shape of the
        name -- a carrier is always called something like V4H-84Q, which no
        starport is. The board re-checks ownership regardless, so the cost of
        being wrong is a rejected upload rather than bad data.
        """
        if entry.get("StationType") == "FleetCarrier":
            return True
        market_id = entry.get("MarketID")
        if isinstance(market_id, int) and market_id in self.known_carriers:
            return True
        name = entry.get("StationName")
        return isinstance(name, str) and CALLSIGN_RE.match(name) is not None

    def enqueue_file(self, path: str) -> None:
        try:
            with open(path, "r", encoding="utf-8") as handle:
                body = handle.read()
        except OSError as err:
            logger.warning("Could not read %s: %s", path, err)
            return
        if body.strip():
            self.queue.put((os.path.basename(path), body, 0))

    # -- status -----------------------------------------------------------

    def set_status(self, text: str) -> None:
        """Update the main-window label from the worker thread, safely."""
        widget, var = self.status_widget, self.status_var
        if widget is None or var is None:
            return
        try:
            widget.after(0, var.set, f"Carrier Ops: {text}")
        except tk.TclError:
            # Window is going away mid-update; nothing worth reporting.
            pass


ops = CarrierOps()


# -- journal directory ------------------------------------------------------

def journal_dir() -> str:
    """Where the game writes its journal, as EDMC itself resolves it."""
    configured = config.get_str("journaldir")
    return configured if configured else config.default_journal_dir


# -- EDMC entry points ------------------------------------------------------

def plugin_start3(plugin_dir: str) -> str:
    ops.start()
    logger.info("%s %s loaded", PLUGIN_NAME, PLUGIN_VERSION)
    return PLUGIN_NAME


def plugin_stop() -> None:
    ops.stop()
    logger.info("%s stopped", PLUGIN_NAME)


def plugin_app(parent: tk.Frame) -> tuple[tk.Widget, tk.Widget]:
    label = tk.Label(parent, text="Carrier Ops:")
    ops.status_var = tk.StringVar(value="Carrier Ops: idle")
    value = tk.Label(parent, textvariable=ops.status_var)
    ops.status_widget = value

    if not ops.api_key:
        ops.status_var.set("Carrier Ops: no API key")

    # The label column is redundant with the text in the StringVar; hand back
    # an empty spacer so the row reads as one line.
    label["text"] = ""
    return label, value


def plugin_prefs(parent: nb.Notebook, cmdr: str, is_beta: bool) -> Optional[tk.Frame]:
    frame = nb.Frame(parent)
    frame.columnconfigure(1, weight=1)

    ops.pref_enabled = tk.IntVar(value=1 if ops.enabled else 0)
    ops.pref_url = tk.StringVar(value=ops.url)
    ops.pref_key = tk.StringVar(value=ops.api_key)
    ops.pref_status = tk.StringVar(value="")

    row = 0
    nb.Label(
        frame,
        text="Sends fleet carrier events to a Carrier Ops board.\n"
             "Nothing else from your journal is uploaded.",
        justify=tk.LEFT,
    ).grid(row=row, column=0, columnspan=3, padx=10, pady=(10, 4), sticky=tk.W)

    row += 1
    nb.Checkbutton(frame, text="Send carrier events", variable=ops.pref_enabled) \
        .grid(row=row, column=0, columnspan=3, padx=10, pady=4, sticky=tk.W)

    row += 1
    nb.Label(frame, text="Board URL").grid(row=row, column=0, padx=10, pady=4, sticky=tk.W)
    _entry(frame, ops.pref_url).grid(row=row, column=1, columnspan=2, padx=10, pady=4, sticky=tk.EW)

    row += 1
    nb.Label(frame, text="API key").grid(row=row, column=0, padx=10, pady=4, sticky=tk.W)
    _entry(frame, ops.pref_key, show="*").grid(row=row, column=1, columnspan=2, padx=10, pady=4, sticky=tk.EW)

    row += 1
    nb.Label(
        frame,
        text="Create a key on the board's settings page.",
        justify=tk.LEFT,
    ).grid(row=row, column=0, columnspan=3, padx=10, pady=(0, 8), sticky=tk.W)

    # EDMC's own Companion API fleet carrier query is what feeds
    # capi_fleetcarrier(). Report it rather than switching it on behind their
    # back -- it is EDMC's setting, not ours.
    row += 1
    if config.get_bool("capi_fleetcarrier", default=False):
        capi_note = ("Companion API: on. Exact upkeep and the cargo hold will be sent "
                     "when EDMC queries Frontier.")
    else:
        capi_note = ("Companion API: off. Tick 'Enable Fleet Carrier CAPI Queries' in EDMC's\n"
                     "Configuration tab to also send exact upkeep and the cargo hold.")
    nb.Label(frame, text=capi_note, justify=tk.LEFT) \
        .grid(row=row, column=0, columnspan=3, padx=10, pady=(4, 8), sticky=tk.W)

    row += 1
    ttk.Button(frame, text="Test connection", command=_test_connection) \
        .grid(row=row, column=0, padx=10, pady=4, sticky=tk.W)
    ttk.Button(frame, text="Upload past journals", command=_backfill) \
        .grid(row=row, column=1, padx=10, pady=4, sticky=tk.W)

    row += 1
    nb.Label(frame, textvariable=ops.pref_status, justify=tk.LEFT) \
        .grid(row=row, column=0, columnspan=3, padx=10, pady=(4, 10), sticky=tk.W)

    row += 1
    nb.Label(frame, text=f"{PLUGIN_NAME} {PLUGIN_VERSION}") \
        .grid(row=row, column=0, columnspan=3, padx=10, pady=(8, 10), sticky=tk.W)

    return frame


def _entry(frame: tk.Frame, variable: tk.StringVar, show: str = "") -> tk.Widget:
    """myNotebook's entry class was renamed; support both spellings."""
    entry_class = getattr(nb, "EntryMenu", None) or getattr(nb, "Entry")
    widget = entry_class(frame, textvariable=variable)
    if show:
        widget.configure(show=show)
    return widget


def prefs_changed(cmdr: str, is_beta: bool) -> None:
    if ops.pref_url is not None:
        config.set(CFG_URL, ops.pref_url.get().strip() or DEFAULT_URL)
    if ops.pref_key is not None:
        config.set(CFG_KEY, ops.pref_key.get().strip())
    if ops.pref_enabled is not None:
        config.set(CFG_ENABLED, bool(ops.pref_enabled.get()))

    ops.set_status("ready" if ops.configured else "no API key")


def journal_entry(
    cmdr: str,
    is_beta: bool,
    system: Optional[str],
    station: Optional[str],
    entry: dict[str, Any],
    state: dict[str, Any],
) -> Optional[str]:
    if is_beta or not ops.configured:
        return None

    event = entry.get("event")

    if event in CARRIER_EVENTS:
        ops.remember_carrier(entry)
        ops.enqueue_event(entry)
        return None

    # Market/Shipyard/Outfitting name the station but carry no item list; the
    # list is in the sibling file, and only worth sending for a carrier.
    filename = SNAPSHOT_FILES.get(str(event))
    if filename and ops.is_carrier_station(entry):
        ops.enqueue_file(os.path.join(journal_dir(), filename))

    return None


def capi_fleetcarrier(data) -> None:
    """
    Frontier's own `/fleetcarrier` payload, by way of EDMC.

    EDMC holds an approved Companion API client id and queries this endpoint
    itself, then hands the result to plugins. That is worth forwarding, because
    it carries the two things the journal simply does not have: what the game
    actually charges for upkeep, and what is in the carrier's hold.

    It is not a replacement for the journal path -- EDMC only asks Frontier at
    most every 15 minutes, and only when you have ticked the fleet carrier CAPI
    option in its settings.
    """
    if not ops.configured:
        return

    try:
        # CAPIData is a dict subclass, but it carries extra attributes that do
        # not survive json.dumps; take a plain copy first.
        body = json.dumps(dict(data), separators=(",", ":"))
    except (TypeError, ValueError) as err:
        logger.warning("Could not serialise the fleetcarrier payload: %s", err)
        return

    ops.remember_carrier(dict(data).get("market") or {})
    ops.queue.put(("fleetcarrier.json", body, 0))
    logger.debug("Queued a Companion API fleetcarrier payload (%s bytes)", len(body))


# -- buttons ----------------------------------------------------------------

def _set_pref_status(text: str) -> None:
    if ops.pref_status is not None:
        try:
            ops.pref_status.set(text)
        except tk.TclError:
            pass


def _test_connection() -> None:
    """Check the URL and key without sending anything."""
    url = (ops.pref_url.get().strip() if ops.pref_url else ops.url) or DEFAULT_URL
    key = (ops.pref_key.get().strip() if ops.pref_key else ops.api_key)

    if not key:
        _set_pref_status("Enter an API key first.")
        return

    def worker() -> None:
        try:
            response = requests.get(
                f"{url.rstrip('/')}/api.php?action=me",
                headers={"X-API-Key": key},
                timeout=20,
            )
        except requests.RequestException as err:
            _set_pref_status(f"Could not reach the board: {err}")
            return

        if response.status_code == 401:
            _set_pref_status("The board did not accept that key.")
            return
        if not response.ok:
            _set_pref_status(f"The board returned {response.status_code}.")
            return

        try:
            data = response.json()
        except ValueError:
            _set_pref_status("That URL did not answer with a Carrier Ops API.")
            return

        carriers = data.get("carriers") or []
        if carriers:
            names = ", ".join(c.get("callsign") or c.get("id", "?") for c in carriers)
            _set_pref_status(f"Connected as {data.get('username')} — {names}")
        else:
            _set_pref_status(f"Connected as {data.get('username')} — no carrier claimed yet")

    threading.Thread(target=worker, name="CarrierOps test", daemon=True).start()


def _backfill() -> None:
    """Scan every journal on disk and send the carrier events out of them."""
    if not ops.pref_key or not ops.pref_key.get().strip():
        _set_pref_status("Enter an API key first.")
        return

    # The buttons edit the same config the worker reads, so commit them before
    # a long job rather than uploading with a stale key.
    prefs_changed("", False)

    def worker() -> None:
        directory = journal_dir()
        try:
            names = sorted(
                name for name in os.listdir(directory)
                if name.startswith("Journal.") and name.endswith(".log")
            )
        except OSError as err:
            _set_pref_status(f"Could not read the journal folder: {err}")
            return

        if not names:
            _set_pref_status(f"No journals found in {directory}")
            return

        _set_pref_status(f"Scanning {len(names)} journals…")
        batch: list[str] = []
        found = 0
        sent_files = 0

        for index, name in enumerate(names, start=1):
            try:
                with open(os.path.join(directory, name), "r", encoding="utf-8") as handle:
                    for line in handle:
                        line = line.strip()
                        if not line or "Carrier" not in line:
                            # Cheap reject: every event we want has "Carrier"
                            # in its name, so most lines never get parsed.
                            continue
                        try:
                            entry = json.loads(line)
                        except ValueError:
                            continue
                        if entry.get("event") in CARRIER_EVENTS:
                            ops.remember_carrier(entry)
                            batch.append(line)
                            found += 1
            except OSError:
                continue

            if len(batch) >= MAX_BATCH:
                ops.queue.put((f"backfill-{sent_files}.log", "\n".join(batch), 0))
                sent_files += 1
                batch = []

            if index % 10 == 0:
                _set_pref_status(f"Scanned {index}/{len(names)} journals, {found} carrier events…")

        if batch:
            ops.queue.put((f"backfill-{sent_files}.log", "\n".join(batch), 0))
            sent_files += 1

        # The current snapshots are worth having too, if they are a carrier's.
        for filename in SNAPSHOT_FILES.values():
            path = os.path.join(directory, filename)
            if not os.path.exists(path):
                continue
            try:
                with open(path, "r", encoding="utf-8") as handle:
                    snapshot = json.load(handle)
            except (OSError, ValueError):
                continue
            # By now known_carriers is populated from the scan above, so a
            # snapshot left over from an ordinary starport is skipped.
            if ops.is_carrier_station(snapshot):
                ops.enqueue_file(path)

        if found:
            _set_pref_status(
                f"Queued {found} carrier events from {len(names)} journals. "
                "Uploading in the background."
            )
        else:
            _set_pref_status(f"No carrier events in {len(names)} journals.")

    threading.Thread(target=worker, name="CarrierOps backfill", daemon=True).start()
