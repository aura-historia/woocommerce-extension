<?php
/**
 * Expiring ownership of a plugin option row.
 *
 * @package AuraHistoria\PartnerConnect
 */

namespace AuraHistoria\PartnerConnect;

if (!defined("ABSPATH")) {
    exit();
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic comparisons touch only plugin-owned, non-autoloaded rows; option caches are invalidated explicitly. The option APIs cannot fence an expired holder.

/**
 * Serializes one kind of work across PHP requests. A lease is a single option
 * row holding a random token and an expiry time; only one request can insert
 * it or take over an expired one. Checkpoints written through the lease commit
 * in the same SQL statement that verifies ownership, so a holder suspended past
 * its expiry, or fenced by seize(), can never overwrite its successor's state.
 *
 * Catalog backfill and webhook synchronization each use their own lease row.
 * Shipping keeps its separate per-source lock.
 */
class Option_Lease
{
    /** @var string */
    private $name;

    /** @var string|null Lease row value while this instance believes it owns it. */
    private $value = null;

    /**
     * @param string $name Lease option name.
     */
    public function __construct($name)
    {
        $this->name = $name;
    }

    /**
     * Takes the lease when it is free or expired, optionally waiting briefly
     * for the current holder to finish.
     *
     * @param int       $seconds Lease lifetime; longer than any work done under it.
     * @param int|float $wait    Seconds to keep trying while another holder's lease is unexpired.
     * @return bool False while another holder's lease is unexpired.
     */
    public function acquire($seconds, $wait = 0)
    {
        $deadline = microtime(true) + max(0, $wait);

        while (!$this->try_acquire($seconds)) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(250000);
        }

        return true;
    }

    /**
     * @param int $seconds Lease lifetime.
     * @return bool
     */
    private function try_acquire($seconds)
    {
        global $wpdb;
        $value = self::new_value($seconds);
        // add_option() could upsert a concurrent insert after a cached miss.
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $this->name, $value
        ));

        if (1 !== $inserted) {
            $current = $this->read_value();
            $decoded = is_string($current) ? json_decode($current, true) : null;
            if (null === $current || (is_array($decoded) && isset($decoded["until"]) && (int) $decoded["until"] >= time())) {
                self::clear_cache($this->name);
                return false;
            }
            // Take over an expired or unreadable lease only if nobody else did first.
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $value, $this->name, $current
            ));
            if (1 !== $changed) {
                self::clear_cache($this->name);
                return false;
            }
        }

        self::clear_cache($this->name);
        $this->value = $value;
        return true;
    }

    /**
     * Takes the lease regardless of its holder. Use only for an authoritative
     * reset such as cancellation: the previous holder's later checkpoints fail.
     *
     * @param int $seconds Lease lifetime.
     * @return bool
     */
    public function seize($seconds)
    {
        global $wpdb;
        $value = self::new_value($seconds);
        $changed = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = %s",
            $this->name, $value, $value
        ));
        self::clear_cache($this->name);
        $this->value = $changed ? $value : null;
        return null !== $this->value;
    }

    /**
     * Frees the lease if this instance still owns it.
     *
     * @return void
     */
    public function release()
    {
        if (null === $this->value) {
            return;
        }

        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $this->name, $this->value
        ));
        self::clear_cache($this->name);
        $this->value = null;
    }

    /**
     * Whether this instance acquired the lease and has not released it. Use
     * held() before work that must not happen after ownership was lost.
     *
     * @return bool
     */
    public function owned()
    {
        return null !== $this->value;
    }

    /**
     * Rereads the lease row: still ours and unexpired.
     *
     * @return bool
     */
    public function held()
    {
        if (null === $this->value || $this->read_value() !== $this->value) {
            return false;
        }

        $decoded = json_decode($this->value, true);
        return is_array($decoded) && isset($decoded["until"]) && (int) $decoded["until"] > time();
    }

    /**
     * Saves a non-autoloaded plugin option only while this lease is held.
     *
     * @param string $option Option name.
     * @param mixed  $value  New value.
     * @return bool
     */
    public function write($option, $value)
    {
        if (null === $this->value) {
            return false;
        }

        global $wpdb;
        $serialized = maybe_serialize($value);
        // Ownership is verified by the write statement itself: a holder can be
        // suspended between a PHP check and the SQL that follows it.
        $changed = self::quiet_query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s, %s, 'no' FROM {$wpdb->options} AS lease WHERE lease.option_name = %s AND lease.option_value = %s ON DUPLICATE KEY UPDATE option_value = %s",
            $option, $serialized, $this->name, $this->value, $serialized
        ));
        self::clear_cache($option);

        // Zero rows means either an unchanged value or a lost lease.
        return false !== $changed &&
            (0 !== $changed || (self::read_raw($option) === $serialized && $this->held()));
    }

    /**
     * Deletes a plugin option only while this lease is held.
     *
     * @param string $option Option name.
     * @return bool Whether the option is absent afterwards under this lease.
     */
    public function delete($option)
    {
        if (null === $this->value) {
            return false;
        }

        global $wpdb;
        $deleted = self::quiet_query($wpdb->prepare(
            "DELETE target FROM {$wpdb->options} AS target INNER JOIN {$wpdb->options} AS lease ON lease.option_name = %s AND lease.option_value = %s WHERE target.option_name = %s",
            $this->name, $this->value, $option
        ));
        self::clear_cache($option);

        return false !== $deleted &&
            (0 !== $deleted || (null === self::read_raw($option) && $this->held()));
    }

    /**
     * Reads a plugin option from the database, bypassing caches that another
     * request may have made stale.
     *
     * @param string $option        Option name.
     * @param mixed  $default_value Value when the option does not exist.
     * @return mixed
     */
    public static function read($option, $default_value = false)
    {
        $raw = self::read_raw($option);
        return null === $raw ? $default_value : maybe_unserialize($raw);
    }

    /**
     * @param string $option Option name.
     * @return string|null
     */
    private static function read_raw($option)
    {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $option
        ));
        return is_string($raw) ? $raw : null;
    }

    /**
     * @return string|null
     */
    private function read_value()
    {
        return self::read_raw($this->name);
    }

    /**
     * @param int $seconds Lease lifetime.
     * @return string
     */
    private static function new_value($seconds)
    {
        return wp_json_encode(["token" => wp_generate_uuid4(), "until" => time() + max(1, (int) $seconds)]);
    }

    /**
     * Database diagnostics must not print serialized request bodies.
     *
     * @param string $query Prepared query.
     * @return int|bool
     */
    private static function quiet_query($query)
    {
        global $wpdb;
        $suppressed = $wpdb->suppress_errors(true);
        try {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Receives only statements prepared by this class.
            return $wpdb->query($query);
        } finally {
            $wpdb->suppress_errors($suppressed);
        }
    }

    /**
     * @param string $option Option name.
     * @return void
     */
    private static function clear_cache($option)
    {
        wp_cache_delete($option, "options");
        wp_cache_delete("notoptions", "options");
        $alloptions = wp_cache_get("alloptions", "options");
        if (is_array($alloptions) && array_key_exists($option, $alloptions)) {
            wp_cache_delete("alloptions", "options");
        }
    }
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
