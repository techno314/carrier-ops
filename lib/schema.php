<?php

declare(strict_types=1);

/**
 * Schema creation and migration.
 *
 * The database is shared with the other apps on this host, so every table is
 * namespaced `fc_`. Statements are written to be safely re-runnable.
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

const FC_SCHEMA_VERSION = 9;

/**
 * Ensure the schema is current, cheaply.
 *
 * A sentinel file records the version we last migrated to, so the common case
 * costs one filesystem stat rather than a database round trip. If the app
 * directory is read-only we fall back to the system temp dir; if that fails
 * too, we still work, just with the version check hitting the database.
 */
function fc_ensure_schema(): void
{
    $version = (string) FC_SCHEMA_VERSION;

    foreach (fc_sentinel_paths() as $path) {
        if (@file_get_contents($path) === $version) {
            return;
        }
    }

    fc_migrate();

    foreach (fc_sentinel_paths() as $path) {
        if (@file_put_contents($path, $version) !== false) {
            return;
        }
    }
}

/** @return string[] preferred first */
function fc_sentinel_paths(): array
{
    return [
        FC_ROOT . '/.schema-version',
        sys_get_temp_dir() . '/fc-schema-version',
    ];
}

function fc_migrate(): void
{
    $db = fc_db();
    foreach (fc_schema_statements() as $sql) {
        $db->exec($sql);
    }
    fc_ensure_columns();
    fc_drop_columns();
    fc_drop_tables();
    fc_backfill();

    // Record it in the database too, so a wiped sentinel does not hide which
    // version the live tables are actually at.
    $db->exec(
        "INSERT INTO fc_meta (k, v) VALUES ('schema_version', '" . FC_SCHEMA_VERSION . "')
         ON DUPLICATE KEY UPDATE v = VALUES(v)"
    );
}

/**
 * Add columns that later versions introduced.
 *
 * `CREATE TABLE IF NOT EXISTS` covers new tables but says nothing about new
 * columns on old ones, and MySQL has no portable `ADD COLUMN IF NOT EXISTS`.
 * Ask the catalogue what is already there instead of relying on the version
 * number, so a half-applied migration still converges.
 */
function fc_ensure_columns(): void
{
    $wanted = [
        'fc_carriers' => [
            // The Frontier customer_id that proved ownership. Set only by the
            // Companion API path, which is the only one that can prove it.
            'owner_customer_id' => 'VARCHAR(64) NULL',
            // From the Companion API, which reports what the game actually
            // charges rather than what _costs.php reconstructs.
            'state' => 'VARCHAR(32) NULL',
            'theme' => 'VARCHAR(64) NULL',
            'taxation' => 'INT NULL',
            'core_cost' => 'BIGINT NULL',
            'services_cost' => 'BIGINT NULL',
            'jumps_cost' => 'BIGINT NULL',
            'num_jumps' => 'INT NULL',
            'total_distance_jumped' => 'DOUBLE NULL',
            'capi_at' => 'DATETIME NULL',
            'cargo_at' => 'DATETIME NULL',
            // When the order book was last confirmed against the game. Until
            // it has been, the orders table is only what the journal saw
            // created and never saw filled.
            'orders_at' => 'DATETIME NULL',
        ],
    ];

    $db = fc_db();
    foreach ($wanted as $table => $columns) {
        $existing = [];
        $stmt = $db->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute(['t' => $table]);
        foreach ($stmt->fetchAll() as $row) {
            $existing[strtolower((string) $row['COLUMN_NAME'])] = true;
        }
        if ($existing === []) {
            continue;   // table not created yet; the CREATE above will have it
        }

        foreach ($columns as $column => $definition) {
            if (isset($existing[strtolower($column)])) {
                continue;
            }
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }
}

/**
 * One-off data fixes that a CREATE or an ALTER cannot express.
 *
 * Empty at present -- the email-verification grandfathering that lived here
 * went when verification did. Kept because the shape is the useful part: each
 * fix guards itself with its own key in fc_meta rather than trusting the
 * schema version, since fc_migrate re-runs every statement whenever the
 * sentinel is missing and a one-off fix must happen exactly once.
 *
 *     if (fc_one("SELECT v FROM fc_meta WHERE k = 'thing_done'") === null) {
 *         ... do the thing ...
 *         fc_exec("INSERT INTO fc_meta (k, v) VALUES ('thing_done', '1')
 *                  ON DUPLICATE KEY UPDATE v = v");
 *     }
 */
function fc_backfill(): void
{
}

/**
 * Remove columns a later version stopped using.
 *
 * Same catalogue lookup as fc_ensure_columns, in reverse. Dropping is more
 * dangerous than adding, so this list stays short and explicit.
 */
function fc_drop_columns(): void
{
    // Discord sign-in was never enabled and has been removed. Email
    // verification was replaced by Frontier authorisation, which proves
    // something the board actually cares about rather than only that somebody
    // read a mailbox.
    $unwanted = [
        'fc_users' => ['discord_id', 'email_verified_at'],
    ];

    $db = fc_db();
    foreach ($unwanted as $table => $columns) {
        foreach ($columns as $column) {
            $stmt = $db->prepare(
                'SELECT COUNT(*) AS n FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
            );
            $stmt->execute(['t' => $table, 'c' => $column]);
            if ((int) ($stmt->fetch()['n'] ?? 0) === 0) {
                continue;
            }
            // discord_id carried a unique index, which has to go first.
            if ($column === 'discord_id') {
                $db->exec("ALTER TABLE `{$table}` DROP INDEX `fc_users_discord`");
            }
            $db->exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
        }
    }
}

/**
 * Tables a later version stopped using.
 *
 * Separate from fc_drop_columns because dropping a table is the more final of
 * the two and deserves to be read on its own.
 */
function fc_drop_tables(): void
{
    // Email verification was replaced by Frontier authorisation.
    foreach (['fc_email_tokens'] as $table) {
        fc_db()->exec("DROP TABLE IF EXISTS `{$table}`");
    }
}

/** @return string[] */
function fc_schema_statements(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS fc_meta (
            k VARCHAR(64) NOT NULL PRIMARY KEY,
            v VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS fc_users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(32) NOT NULL,
            email VARCHAR(190) NULL,
            password_hash VARCHAR(255) NOT NULL,
            api_key_hash CHAR(64) NULL,
            cmdr_name VARCHAR(64) NULL,
            is_admin TINYINT(1) NOT NULL DEFAULT 0,
            is_banned TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            last_login DATETIME NULL,
            UNIQUE KEY fc_users_username (username),
            UNIQUE KEY fc_users_email (email),
            UNIQUE KEY fc_users_api_key (api_key_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS fc_sessions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY fc_sessions_token (token_hash),
            KEY fc_sessions_user (user_id),
            KEY fc_sessions_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // CarrierID is the primary key: it is Frontier's own stable identifier
        // and survives renames, callsign changes and ownership transfers.
        "CREATE TABLE IF NOT EXISTS fc_carriers (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            owner_user_id INT UNSIGNED NULL,
            callsign VARCHAR(16) NULL,
            name VARCHAR(128) NULL,
            system VARCHAR(128) NULL,
            body VARCHAR(128) NULL,
            body_id INT NULL,
            system_address BIGINT UNSIGNED NULL,
            x DOUBLE NULL, y DOUBLE NULL, z DOUBLE NULL,
            docking_access VARCHAR(16) NULL,
            allow_notorious TINYINT(1) NULL,
            fuel_level INT NULL,
            jump_range_curr DOUBLE NULL,
            jump_range_max DOUBLE NULL,
            pending_decommission TINYINT(1) NOT NULL DEFAULT 0,
            capacity INT NULL,
            space_crew INT NULL,
            space_cargo INT NULL,
            space_reserved INT NULL,
            space_shippacks INT NULL,
            space_modulepacks INT NULL,
            space_free INT NULL,
            balance BIGINT NULL,
            reserve_balance BIGINT NULL,
            available_balance BIGINT NULL,
            reserve_percent INT NULL,
            tax_rate INT NULL,
            tax_refuel INT NULL,
            tax_repair INT NULL,
            tax_rearm INT NULL,
            tax_shipyard INT NULL,
            tax_outfitting INT NULL,
            market_id BIGINT UNSIGNED NULL,
            is_public TINYINT(1) NOT NULL DEFAULT 1,
            show_market TINYINT(1) NOT NULL DEFAULT 1,
            show_itinerary TINYINT(1) NOT NULL DEFAULT 1,
            motd VARCHAR(500) NULL,
            stats_at DATETIME NULL,
            finance_at DATETIME NULL,
            location_at DATETIME NULL,
            docking_at DATETIME NULL,
            fuel_at DATETIME NULL,
            name_at DATETIME NULL,
            market_at DATETIME NULL,
            shipyard_at DATETIME NULL,
            outfitting_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY fc_carriers_callsign (callsign),
            KEY fc_carriers_owner (owner_user_id),
            KEY fc_carriers_system (system),
            KEY fc_carriers_market (market_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS fc_crew (
            carrier_id BIGINT UNSIGNED NOT NULL,
            crew_role VARCHAR(32) NOT NULL,
            activated TINYINT(1) NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            crew_name VARCHAR(64) NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (carrier_id, crew_role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS fc_market (
            carrier_id BIGINT UNSIGNED NOT NULL,
            commodity VARCHAR(64) NOT NULL,
            loc_name VARCHAR(96) NULL,
            category VARCHAR(64) NULL,
            stock INT NOT NULL DEFAULT 0,
            demand INT NOT NULL DEFAULT 0,
            buy_price INT NOT NULL DEFAULT 0,
            sell_price INT NOT NULL DEFAULT 0,
            mean_price INT NOT NULL DEFAULT 0,
            PRIMARY KEY (carrier_id, commodity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Buy and sell orders are set independently of the market snapshot, and
        // survive between visits to the commodity screen.
        "CREATE TABLE IF NOT EXISTS fc_orders (
            carrier_id BIGINT UNSIGNED NOT NULL,
            commodity VARCHAR(64) NOT NULL,
            black_market TINYINT(1) NOT NULL DEFAULT 0,
            loc_name VARCHAR(96) NULL,
            kind VARCHAR(8) NOT NULL,
            amount INT NOT NULL DEFAULT 0,
            price INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (carrier_id, commodity, black_market)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS fc_itinerary (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            carrier_id BIGINT UNSIGNED NOT NULL,
            system VARCHAR(128) NOT NULL,
            body VARCHAR(128) NULL,
            system_address BIGINT UNSIGNED NULL,
            x DOUBLE NULL, y DOUBLE NULL, z DOUBLE NULL,
            arrival_time DATETIME NOT NULL,
            departure_time DATETIME NULL,
            UNIQUE KEY fc_itinerary_arrival (carrier_id, arrival_time),
            KEY fc_itinerary_carrier (carrier_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS fc_jumps (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            carrier_id BIGINT UNSIGNED NOT NULL,
            system VARCHAR(128) NULL,
            body VARCHAR(128) NULL,
            departure_time DATETIME NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'scheduled',
            created_at DATETIME NOT NULL,
            UNIQUE KEY fc_jumps_departure (carrier_id, departure_time),
            KEY fc_jumps_carrier (carrier_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // dedupe_hash makes re-uploading the same journal a no-op instead of
        // duplicating every transaction in the ledger.
        "CREATE TABLE IF NOT EXISTS fc_ledger (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            carrier_id BIGINT UNSIGNED NOT NULL,
            ts DATETIME NOT NULL,
            kind VARCHAR(32) NOT NULL,
            detail VARCHAR(190) NULL,
            amount BIGINT NULL,
            unit VARCHAR(8) NOT NULL DEFAULT 'cr',
            balance BIGINT NULL,
            dedupe_hash CHAR(40) NOT NULL,
            UNIQUE KEY fc_ledger_dedupe (dedupe_hash),
            KEY fc_ledger_carrier (carrier_id, ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS fc_shipyard (
            carrier_id BIGINT UNSIGNED NOT NULL,
            ship VARCHAR(64) NOT NULL,
            loc_name VARCHAR(96) NULL,
            base_value BIGINT NOT NULL DEFAULT 0,
            stock INT NOT NULL DEFAULT 0,
            PRIMARY KEY (carrier_id, ship)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS fc_outfitting (
            carrier_id BIGINT UNSIGNED NOT NULL,
            module VARCHAR(96) NOT NULL,
            loc_name VARCHAR(128) NULL,
            category VARCHAR(64) NULL,
            cost BIGINT NOT NULL DEFAULT 0,
            stock INT NOT NULL DEFAULT 0,
            PRIMARY KEY (carrier_id, module)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Only the Companion API knows what is actually in the hold; the
        // journal never reports a carrier's cargo. Stolen goods sit in their
        // own row because the game tracks them as a separate stack.
        "CREATE TABLE IF NOT EXISTS fc_cargo (
            carrier_id BIGINT UNSIGNED NOT NULL,
            commodity VARCHAR(64) NOT NULL,
            stolen TINYINT(1) NOT NULL DEFAULT 0,
            loc_name VARCHAR(96) NULL,
            qty INT NOT NULL DEFAULT 0,
            value BIGINT NOT NULL DEFAULT 0,
            PRIMARY KEY (carrier_id, commodity, stolen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Answers from Ardent, kept so that reloading the page, or two people
        // asking the same question, does not mean asking a volunteer-run API
        // again. Keyed by a hash of the query rather than its parts, because
        // the parts are only ever compared as a whole.
        "CREATE TABLE IF NOT EXISTS fc_buyers (
            query_hash CHAR(40) NOT NULL PRIMARY KEY,
            commodity VARCHAR(64) NOT NULL,
            system VARCHAR(128) NOT NULL,
            min_demand INT NOT NULL DEFAULT 0,
            max_distance INT NOT NULL DEFAULT 0,
            payload MEDIUMTEXT NULL,
            fetched_at DATETIME NOT NULL,
            KEY fc_buyers_fetched (fetched_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // One Frontier account per Carrier Ops account, so user_id is the key.
        //
        // Both tokens are stored encrypted rather than as written: this database
        // is shared with the other apps on this host, and a refresh token is a
        // standing key to somebody's Frontier account. The plaintext only ever
        // exists in memory, and the encryption key lives on the filesystem, so
        // a dump of the database on its own yields nothing usable.
        //
        // Only customer_id and platform are kept from /me. The name and email
        // it also returns are not needed for anything here, and the least
        // pleasant way to handle personal data is to store it because it
        // happened to be in the response.
        "CREATE TABLE IF NOT EXISTS fc_capi_tokens (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            customer_id VARCHAR(64) NULL,
            platform VARCHAR(32) NULL,
            access_token BLOB NULL,
            refresh_token BLOB NULL,
            scope VARCHAR(64) NULL,
            expires_at DATETIME NULL,
            needs_reauth TINYINT(1) NOT NULL DEFAULT 0,
            last_error VARCHAR(255) NULL,
            last_fetch_at DATETIME NULL,
            refreshed_at DATETIME NULL,
            linked_at DATETIME NOT NULL,
            KEY fc_capi_tokens_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // An authorisation in flight. The PKCE verifier has to survive the round
        // trip to Frontier and back, and it cannot go in the URL or a cookie
        // without defeating the point of PKCE, so it waits here. Rows are
        // single-use and short-lived; `state` is what ties the callback to the
        // browser that started it.
        "CREATE TABLE IF NOT EXISTS fc_capi_pending (
            state_hash CHAR(64) NOT NULL PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            verifier VARCHAR(128) NOT NULL,
            created_at DATETIME NOT NULL,
            KEY fc_capi_pending_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Reset tokens are stored hashed, like session tokens: the mail is the
        // only place the usable value ever exists.
        "CREATE TABLE IF NOT EXISTS fc_password_resets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            requested_ip VARBINARY(16) NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY fc_resets_token (token_hash),
            KEY fc_resets_user (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // A Discord webhook the owner has pointed at one of their carriers.
        // board_message_id is the message the status board keeps editing; it is
        // only ever obtained by posting with ?wait=true, since a plain webhook
        // POST answers 204 with an empty body and no id to keep.
        "CREATE TABLE IF NOT EXISTS fc_webhooks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            carrier_id BIGINT UNSIGNED NOT NULL,
            created_by INT UNSIGNED NULL,
            label VARCHAR(64) NULL,
            url VARCHAR(255) NOT NULL,
            events VARCHAR(255) NOT NULL DEFAULT '',
            show_finance TINYINT(1) NOT NULL DEFAULT 0,
            board_enabled TINYINT(1) NOT NULL DEFAULT 0,
            board_message_id VARCHAR(32) NULL,
            board_hash CHAR(40) NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            fail_count INT NOT NULL DEFAULT 0,
            last_error VARCHAR(255) NULL,
            last_sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            KEY fc_webhooks_carrier (carrier_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Outbound posts wait here rather than going out inside the upload that
        // produced them: Discord is somebody else's server and this host has
        // five PHP workers for the whole domain, so a slow round trip must not
        // be one the uploader is made to sit through.
        //
        // dedupe_hash is what stops a re-uploaded journal reposting a jump that
        // was announced weeks ago -- the same protection fc_ledger needs, for
        // the same reason.
        "CREATE TABLE IF NOT EXISTS fc_webhook_queue (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            webhook_id INT UNSIGNED NOT NULL,
            kind VARCHAR(32) NOT NULL,
            payload MEDIUMTEXT NOT NULL,
            dedupe_hash CHAR(40) NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NOT NULL,
            last_error VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY fc_webhook_queue_dedupe (dedupe_hash),
            KEY fc_webhook_queue_due (next_attempt_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS fc_uploads (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            source VARCHAR(16) NOT NULL DEFAULT 'web',
            filename VARCHAR(190) NULL,
            bytes INT NOT NULL DEFAULT 0,
            events_seen INT NOT NULL DEFAULT 0,
            events_applied INT NOT NULL DEFAULT 0,
            carriers_touched VARCHAR(190) NULL,
            ts DATETIME NOT NULL,
            KEY fc_uploads_user (user_id, ts)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}
