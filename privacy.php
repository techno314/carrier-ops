<?php

declare(strict_types=1);

/**
 * What the board stores and why.
 *
 * Written to be read rather than to satisfy a checklist: the useful test is
 * whether somebody could work out what is held about them without asking.
 * Kept in step with the code by hand -- if a column that identifies a person
 * is added, it belongs on this page.
 */

require_once __DIR__ . '/lib/core.php';

fc_head('Privacy');
?>
<main class="wrap mid">
  <?php fc_render_flash(); ?>

  <div class="card">
    <h1>Privacy</h1>
    <p class="muted">
      Carrier Ops is a hobby project run by one person. It stores what it needs to show you your carrier
      and nothing it has no use for. This page says what that is.
    </p>
  </div>

  <div class="card">
    <h2>What is stored</h2>

    <h3 class="small">Your account</h3>
    <p class="muted small">
      A username, a password (hashed — never the password itself), and optionally an email address and your
      commander name. The email is used for password resets and nothing else: no newsletters, and it is
      never passed to anyone.
    </p>

    <h3 class="small">Your Frontier authorisation</h3>
    <p class="muted small">
      If you connect a Frontier account, the board keeps the access and refresh tokens it was issued, both
      encrypted, along with your Frontier customer id and platform. That id is what proves a carrier is
      yours. Frontier also returns your name and email address with those tokens; neither is stored.
    </p>

    <h3 class="small">Your carriers</h3>
    <p class="muted small">
      Everything shown on a carrier page: its callsign and name, position, finances, market, cargo, crew,
      jump history and the ledger. This comes from your uploads and from Frontier's Companion API.
    </p>

    <h3 class="small">Journal uploads</h3>
    <p class="muted small">
      Only fleet carrier events are read from an uploaded journal — <code>CarrierStats</code>,
      <code>CarrierJump</code> and their siblings — plus the <code>Market.json</code>,
      <code>Shipyard.json</code> and <code>Outfitting.json</code> snapshots if you include them. Everything
      else in the file is ignored and never stored: where you have been, what you scanned, who you fought,
      what was said in chat. A record of each upload is kept — filename, size and time — so you can see
      what the board received.
    </p>

    <h3 class="small">Squadrons</h3>
    <p class="muted small">
      If your Frontier account is in a squadron, the board records the squadron, your rank in it, and your
      commander name and id. This is what decides who may see a squadron carrier.
    </p>

    <h3 class="small">Sessions</h3>
    <p class="muted small">
      A cookie holding a random token, stored hashed. It is not tied to your IP address, so changing
      network does not sign you out. The board keeps no analytics, uses no third-party trackers, and sets
      no advertising cookies.
    </p>
  </div>

  <div class="card">
    <h2>Who else sees it</h2>
    <p class="muted small">
      <strong>A carrier is public from the moment it is claimed.</strong> Its overview, crew, jump history,
      market and itinerary are visible to anyone with the link, and it is listed on the
      <a href="<?= fc_e(fc_url('search.php')) ?>">Carriers</a> page where anyone can find it. That is the
      default rather than something you switch on, and this site is indexed by search engines.
    </p>
    <p class="muted small">
      Three switches on the carrier's Manage tab change it: one takes it out of the listing entirely, and
      two others control the market and the itinerary separately. Finances and cargo are
      <em>never</em> public for a personal carrier whatever those are set to. A squadron carrier is always
      visible to its squadron, and its owner may additionally choose to publish its books.
    </p>
    <p class="muted small">
      If you point a Discord webhook at a carrier, the board sends that carrier's details to Discord. That
      is the one place data leaves this server on purpose, and only because you asked for it.
    </p>
    <p class="muted small">
      The board talks to Frontier's Companion API on your behalf, and to
      <a href="https://spansh.co.uk/">Spansh</a> and <a href="https://ardent-industry.com/">Ardent</a> to
      answer market questions. Those requests carry a system or commodity name — never anything about you.
    </p>
  </div>

  <div class="card">
    <h2>How long it is kept</h2>
    <p class="muted small">
      Account data lasts until you delete the account. Password reset requests are removed a week after
      they expire. Cached market answers last a week. Carrier data has no expiry, because a carrier's
      history is the point of the board.
    </p>
  </div>

  <div class="card">
    <h2>Deleting it</h2>
    <p class="muted small">
      <a href="<?= fc_e(fc_url('settings.php')) ?>">Settings</a> has a button to delete your account. It is
      scheduled <?= FC_DELETE_GRACE_DAYS ?> days ahead so a change of mind is possible; until then nothing
      is removed and you can cancel from the same page.
    </p>
    <p class="muted small">
      When it happens, everything above goes: sign-in details, email, Frontier authorisations and customer
      id, upload history, webhooks and squadron membership. Your carriers are released rather than deleted
      — their history belongs to the carrier, and one can be claimed again by connecting the Frontier
      account that owns it — and nothing identifying
      you is left on them.
    </p>
    <p class="muted small">
      You can also disconnect a Frontier account at any time without deleting anything else, from the
      Frontier section of Settings.
    </p>
  </div>

  <div class="card">
    <h2>Asking about it</h2>
    <p class="muted small">
      If you want a copy of what is held about you, want something corrected, or would rather not use the
      button above, ask the admin — the same person who gave you the link to this board. There is no
      support desk; it is one person and a database.
    </p>
  </div>
</main>
<?php fc_foot();
