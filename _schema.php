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

const FC_SCHEMA_VERSION = 1;

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
        __DIR__ . '/.schema-version',
        sys_get_temp_dir() . '/fc-schema-version',
    ];
}

function fc_migrate(): void
{
    $db = fc_db();
    foreach (fc_schema_statements() as $sql) {
        $db->exec($sql);
    }

    // Record it in the database too, so a wiped sentinel does not hide which
    // version the live tables are actually at.
    $db->exec(
        "INSERT INTO fc_meta (k, v) VALUES ('schema_version', '" . FC_SCHEMA_VERSION . "')
         ON DUPLICATE KEY UPDATE v = VALUES(v)"
    );
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
            discord_id VARCHAR(32) NULL,
            is_admin TINYINT(1) NOT NULL DEFAULT 0,
            is_banned TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            last_login DATETIME NULL,
            UNIQUE KEY fc_users_username (username),
            UNIQUE KEY fc_users_email (email),
            UNIQUE KEY fc_users_api_key (api_key_hash),
            UNIQUE KEY fc_users_discord (discord_id)
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
