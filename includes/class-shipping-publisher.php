<?php
/**
 * Source-ordered, resumable typed shipping publication.
 *
 * @package AuraHistoria\PartnerConnect
 */

namespace AuraHistoria\PartnerConnect;

if (!defined("ABSPATH")) {
    exit();
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared atomic comparisons affect only plugin-owned options; their caches are invalidated explicitly. The option APIs cannot fence expired workers.

/**
 * All shipping paths share this source queue/lock and acquisition sequence. One action
 * performs at most one write. A persisted request is immutable until confirmed
 * or rejected; credentials are always resolved at execution time.
 */
class Shipping_Publisher
{
    // Atomic comparisons below touch only this plugin's non-autoloaded state
    // options. They prevent an expired worker from overwriting its successor;
    // WooCommerce configuration is acquired exclusively through supported APIs.
    const ACTION_HOOK = "ahpc_publish_shipping";
    const ACTION_GROUP = "aura-historia-shipping";
    const OPTION_SOURCES = "ahpc_shipping_sources";
    const OPTION_CLEANUP = "ahpc_shipping_cleanup";
    const MAX_ATTEMPTS = 5;
    const MAX_DEFERRED = 100;
    const RETRY_DELAY = 60;
    const LOCK_SECONDS = 120;

    private $acquisition;
    private $mapper;
    private $client;
    private $lock = null;

    public function __construct()
    {
        $this->acquisition = new Shipping_Acquisition();
        $this->mapper = new Shipping_Mapper();
        $this->client = new Backend_Api_Client();
    }

    public static function state_option($source)
    {
        return "ahpc_shipping_state_" . $source;
    }

    public static function order_option($source)
    {
        return "ahpc_shipping_order_" . $source;
    }

    private static function lock_option($source)
    {
        return "ahpc_shipping_lock_" . $source;
    }

    private static function clear_option_cache($name)
    {
        wp_cache_delete($name, "options");
        wp_cache_delete("notoptions", "options");
    }

    /** add_option() can overwrite a concurrent insert after a cached miss. */
    private static function insert_option($name, $value)
    {
        global $wpdb;
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $name, maybe_serialize($value)
        ));
        wp_cache_delete($name, "options");
        wp_cache_delete("notoptions", "options");
        return 1 === $inserted;
    }

    /** Shared coordination boundary for all shipping operations. */
    public function acquire_source_lock($source)
    {
        if (!Webhook_Manager::is_valid_listing_source_id($source)) {
            return false;
        }
        global $wpdb;
        $name = self::lock_option($source);
        $value = wp_json_encode(["token" => wp_generate_uuid4(), "until" => time() + self::LOCK_SECONDS]);
        if (!self::insert_option($name, $value)) {
            $old = get_option($name, "");
            $decoded = json_decode($old, true);
            if (!is_array($decoded) || $decoded["until"] >= time()) {
                return false;
            }
            $changed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $old));
            wp_cache_delete($name, "options");
            if (1 !== $changed) {
                return false;
            }
        }
        $this->lock = ["source" => $source, "name" => $name, "value" => $value, "generation" => Shipping_Sync::generation()];
        self::clear_option_cache(self::state_option($source));
        return true;
    }

    public function release_source_lock()
    {
        if (!$this->lock) {
            return;
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $this->lock["name"], $this->lock["value"]));
        wp_cache_delete($this->lock["name"], "options");
        $this->lock = null;
    }

    private function assert_lock($source)
    {
        if (!$this->lock || $this->lock["source"] !== $source) {
            throw new \RuntimeException("shipping_lock_missing");
        }
        wp_cache_delete($this->lock["name"], "options");
        $current = get_option($this->lock["name"], "");
        $decoded = json_decode($current, true);
        if ($current !== $this->lock["value"] || !is_array($decoded) || $decoded["until"] <= time()
            || $this->lock["generation"] !== Shipping_Sync::generation()) {
            throw new \RuntimeException("shipping_lock_lost");
        }
    }

    /**
     * Queues an initial run once, resumes exact pending work, or starts a manual
     * refresh after completion. Automatic changes use the same method.
     *
     * @return true|\WP_Error
     */
    public function queue($source, $refresh = true)
    {
        if (!Webhook_Manager::is_valid_listing_source_id($source) || !function_exists("as_enqueue_async_action")) {
            return Shipping_Mapper::error("scheduler_unavailable");
        }
        if (!did_action("action_scheduler_init")) {
            return Shipping_Mapper::error("scheduler_not_ready");
        }
        if (!$this->acquire_source_lock($source)) {
            // The current worker owns scheduling its continuation.
            return Shipping_Mapper::error("source_busy");
        }
        try {
            $binding = Shipping_Sync::binding();
            if (isset($binding["enabled"]) && (!$binding["enabled"] || ($binding["source"] ?? "") !== $source)) {
                return Shipping_Mapper::error("connection_changed");
            }
            $state = $this->get_status($source);
            if (($state["generation"] ?? "") !== Shipping_Sync::generation()) {
                // Reconnection cannot relabel a queued capture from an earlier
                // binding. Preserve the monotonic order, acquire new facts.
                $state = [];
            }
            if (!$refresh && (in_array($state["status"] ?? "", ["complete", "partial", "needs_review"], true) || ($state["attempt"] ?? 0) >= self::MAX_ATTEMPTS)) {
                return true;
            }
            if (isset($state["wake"]) && as_has_scheduled_action(self::ACTION_HOOK, [$source, $state["wake"]], self::ACTION_GROUP)) {
                if ($refresh) {
                    $state["refresh_requested"] = true;
                    $this->save($state);
                }
                return true;
            }
            if (!$state || ("complete" === ($state["status"] ?? "") && $refresh)) {
                $upper_id = $this->acquisition->upper_product_id();
                if (is_wp_error($upper_id)) {
                    return $upper_id;
                }
                $state = ["source" => $source, "status" => "queued", "phase" => "profile", "cursor" => 0, "upper_id" => $upper_id,
                    "generation" => Shipping_Sync::generation(), "queued_at" => time(),
                    "item_id" => 0, "component" => "selection", "pending" => null, "deferred" => [], "traversed" => false,
                    "processed" => 0, "protected" => 0, "failed" => 0, "attempt" => 0, "conflicts" => 0, "limitations" => [], "last_error" => "", "retry_remaining" => 0];
            } elseif ($refresh && !empty($state["deferred"])) {
                $state["deferred"] = self::compact_deferred($state["deferred"]);
                // An interrupted replay already owns the head entry; resend it first.
                if (!empty($state["pending"]) && empty($state["replaying"]) && count($state["deferred"]) >= self::MAX_DEFERRED) {
                    $state["held_pending"] = $state["pending"];
                    $state["pending"] = null;
                }
                $state["retry_remaining"] = count($state["deferred"]);
                $state["attempt"] = 0;
            } elseif ($refresh && "needs_review" === ($state["status"] ?? "")) {
                $state["pending"] = null;
                $state["phase"] = "profile";
                $state["conflicts"] = 0;
            }
            $this->remember_source($source);
            $state["refresh_requested"] = $refresh || !empty($state["refresh_requested"]);
            $state["status"] = "queued";
            $state["attempt"] = 0;
            return $this->schedule($state) ? true : Shipping_Mapper::error("scheduling_failed");
        } catch (\Throwable $exception) {
            return Shipping_Mapper::error("checkpoint_failed");
        } finally {
            $this->release_source_lock();
        }
    }

    public function maybe_queue_initial()
    {
        $binding = Shipping_Sync::binding();
        if (isset($binding["enabled"]) && !$binding["enabled"]) {
            return;
        }
        $settings = Shipping_Sync::settings();
        if (Webhook_Manager::is_valid_listing_source_id($settings["listing_source_id"]) && Webhook_Manager::is_valid_access_token($settings["access_token"])
            && "no" === get_option(Webhook_Manager::OPTION_NEEDS_SYNC, "yes")) {
            $this->queue($settings["listing_source_id"], false);
        }
    }

    /**
     * Enqueue an explicit owned removal after local trash/deletion.
     * Omission from a page, an unsupported product and a failed read never call
     * this operation. Recheck actual absence again immediately before writing.
     */
    public function queue_item_removal($source, $item_id)
    {
        if (!$this->confirmed_removed($item_id)) {
            return Shipping_Mapper::error("removal_not_confirmed");
        }
        $queued = $this->queue($source, false);
        if (is_wp_error($queued)) {
            return $queued;
        }
        if (!$this->acquire_source_lock($source)) {
            return Shipping_Mapper::error("source_busy");
        }
        try {
            $state = $this->get_status($source);
            $removals = $state["removals"] ?? [];
            if (count($removals) >= self::MAX_DEFERRED) {
                return Shipping_Mapper::error("removal_queue_full");
            }
            if (!in_array((int) $item_id, array_column($removals, "id"), true)) {
                $state["removals"][] = ["id" => (int) $item_id, "component" => "selection"];
            }
            if (!empty($state["wake"]) && as_has_scheduled_action(self::ACTION_HOOK, [$source, $state["wake"]], self::ACTION_GROUP)) {
                $this->save($state);
            } else {
                if (!$this->schedule($state)) {
                    return Shipping_Mapper::error("scheduling_failed");
                }
            }
            return true;
        } finally {
            $this->release_source_lock();
        }
    }

    public static function confirmed_removed($item_id)
    {
        global $wpdb;
        if (!is_int($item_id) || $item_id <= 0) {
            return false;
        }
        wp_cache_delete($item_id, "posts");
        $post = get_post($item_id);
        return !$wpdb->last_error && (!$post || ("product" === $post->post_type && in_array($post->post_status, ["trash", "auto-draft", "draft", "private", "pending", "future"], true)));
    }

    /** Scoped membership refresh; facts are acquired inside the same worker. */
    public function queue_items($source, array $item_ids)
    {
        if (!$item_ids || count($item_ids) > self::MAX_DEFERRED) {
            return Shipping_Mapper::error("item_scope_too_large");
        }
        foreach ($item_ids as $id) {
            if (!is_int($id) || $id <= 0) {
                return Shipping_Mapper::error("invalid_item_identity");
            }
        }
        $queued = $this->queue($source, false);
        if (is_wp_error($queued)) {
            return $queued;
        }
        if (!$this->acquire_source_lock($source)) {
            return Shipping_Mapper::error("source_busy");
        }
        try {
            $state = $this->get_status($source);
            $targets = $state["targets"] ?? [];
            foreach (array_unique($item_ids) as $id) {
                if (!in_array($id, array_column($targets, "id"), true)) {
                    $targets[] = ["id" => $id, "component" => "selection"];
                } else {
                    foreach ($targets as &$target) {
                        if ($target["id"] === $id && ("inputs" === $target["component"] || ($state["pending"]["item_id"] ?? 0) === $id)) {
                            $target["refresh"] = true;
                        }
                    }
                    unset($target);
                }
            }
            if (count($targets) > self::MAX_DEFERRED) {
                return Shipping_Mapper::error("item_scope_too_large");
            }
            $state["targets"] = $targets;
            if (!empty($state["wake"]) && as_has_scheduled_action(self::ACTION_HOOK, [$source, $state["wake"]], self::ACTION_GROUP)) {
                $this->save($state);
                return true;
            }
            return $this->schedule($state) ? true : Shipping_Mapper::error("scheduling_failed");
        } finally {
            $this->release_source_lock();
        }
    }

    public function get_status($source)
    {
        $state = get_option(self::state_option($source), []);
        return is_array($state) ? $state : [];
    }

    /** Action Scheduler entry point; arguments contain identifiers only. */
    public function process($source, $wake)
    {
        $binding = Shipping_Sync::binding();
        if (isset($binding["enabled"]) && (!$binding["enabled"] || ($binding["source"] ?? "") !== $source)) {
            return;
        }
        if (!$this->acquire_source_lock($source)) {
            // Do not lose a wake-up when another source operation holds the lock.
            if (($binding["generation"] ?? "") === Shipping_Sync::generation()) {
                as_schedule_single_action(time() + self::RETRY_DELAY, self::ACTION_HOOK, [$source, $wake], self::ACTION_GROUP);
            }
            return;
        }
        try {
            $state = $this->get_status($source);
            if (!$state || ($state["wake"] ?? "") !== $wake) {
                return;
            }
            $settings = Shipping_Sync::settings();
            $binding = Shipping_Sync::binding();
            if ($source !== $settings["listing_source_id"] || !Webhook_Manager::is_valid_access_token($settings["access_token"])
                || ($state["generation"] ?? "") !== Shipping_Sync::generation() || (isset($binding["enabled"]) && !$binding["enabled"])) {
                $state["status"] = "failed";
                $state["last_error"] = "connection_changed";
                $this->save($state);
                return;
            }
            if (isset($binding["enabled"]) && "no" !== get_option(Webhook_Manager::OPTION_NEEDS_SYNC, "yes")) {
                $state["last_error"] = "configuration_not_ready";
                $this->schedule($state, self::RETRY_DELAY);
                return;
            }
            $state["status"] = "running";
            $state["attempted_at"] = time();
            if (empty($state["pending"])) {
                $request = $this->prepare($state);
                if (is_wp_error($request)) {
                    $this->failure($state, $request);
                    return;
                }
                if (null === $request) {
                    return;
                }
                $state["pending"] = $request;
                $state["attempt"] = 0;
                // Persist the exact request before a potentially committed write.
                $this->save($state);
            }
            $this->assert_lock($source);
            $pending = $state["pending"];
            if (str_starts_with($pending["kind"], "remove_") && !self::confirmed_removed($pending["item_id"])) {
                $result = $this->reconcile_restored_item($state, $pending["item_id"]);
                if (is_wp_error($result)) {
                    // An inconclusive read must retain the immutable request
                    // and a retry; only confirmed restoration cancels it.
                    $this->failure($state, $result);
                }
                return;
            }
            $result = $this->client->shipping_request($pending["operation"], $source, $pending["arguments"]);
            $this->assert_lock($source);
            if (is_wp_error($result)) {
                $this->failure($state, $result);
                return;
            }
            if (in_array($pending["kind"], ["profile", "restore"], true)) {
                if (empty($result["shippingProfileId"]) || empty($result["managementRevision"])) {
                    $this->failure($state, Shipping_Mapper::error("invalid_profile_response"));
                    return;
                }
                $state["profile_id"] = $result["shippingProfileId"];
            }
            $state["committed_at"] = time();
            if (in_array($pending["kind"], ["selection", "inputs"], true)) {
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- The dynamic option and negative option caches are cleared by clear_option_cache() immediately below.
                $indexed = $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s, %s, 'no' FROM {$wpdb->options} AS lease WHERE lease.option_name = %s AND lease.option_value = %s",
                    Shipping_Sync::MEMBER_PREFIX . $source . "_" . $pending["item_id"], (string) $pending["item_id"], $this->lock["name"], $this->lock["value"]
                ));
                self::clear_option_cache(Shipping_Sync::MEMBER_PREFIX . $source . "_" . $pending["item_id"]);
                $this->assert_lock($source);
                if (false === $indexed) {
                    throw new \RuntimeException("shipping_membership_checkpoint_failed");
                }
            } elseif ("remove_inputs" === $pending["kind"]) {
                delete_option(Shipping_Sync::MEMBER_PREFIX . $source . "_" . $pending["item_id"]);
            }
            $this->advance($state, $pending["kind"]);
            $state["pending"] = null;
            $state["last_error"] = "";
            $state["attempt"] = 0;
            $this->schedule($state);
        } catch (\Throwable $exception) {
            // No exception text, payload, URL, settings or credentials in logs.
            // The prior immutable checkpoint remains available for manual resume.
            if (isset($state) && is_array($state)) {
                try {
                    $state["status"] = "failed";
                    $state["last_error"] = "checkpoint_or_lock_failed";
                    $this->save($state);
                } catch (\Throwable $ignored) {
                    // A different worker may now own the source lock.
                }
            }
        } finally {
            $this->release_source_lock();
        }
    }

    private function prepare(array &$state)
    {
        $state["replaying"] = false;
        $state["removing"] = false;
        $state["targeting"] = false;
        if (!empty($state["removals"])) {
            $state["removing"] = true;
            return $this->prepare_removal($state);
        }
        if (!empty($state["refresh_requested"])) {
            $state["phase"] = "profile";
            $upper_id = $this->acquisition->upper_product_id();
            if (is_wp_error($upper_id)) {
                return $upper_id;
            }
            $state["cursor"] = 0;
            $state["item_id"] = 0;
            $state["component"] = "selection";
            $state["upper_id"] = $upper_id;
            $state["traversed"] = false;
            $state["refresh_requested"] = false;
            $state["processed"] = 0;
            $state["protected"] = 0;
            $state["configuration_restarts"] = 0;
        }
        if ("profile" === $state["phase"]) {
            return $this->prepare_profile($state);
        }
        if ("default" === $state["phase"]) {
            return $this->prepare_default($state);
        }
        if ($state["retry_remaining"] > 0 && $state["deferred"]) {
            $entry = $state["deferred"][0];
            $state["replaying"] = true;
            if (!empty($entry["pending"])) {
                return $entry["pending"];
            }
            return $this->prepare_item($state, $entry["item_id"], $entry["component"]);
        }
        if (!empty($state["held_pending"])) {
            $request = $state["held_pending"];
            $state["held_pending"] = null;
            return $request;
        }
        if (!empty($state["targets"])) {
            $state["targeting"] = true;
            return $this->prepare_unretained($state, $state["targets"][0]["id"], $state["targets"][0]["component"]);
        }
        if ($state["traversed"]) {
            $state["status"] = $state["deferred"] ? "partial" : "complete";
            $state["completed_at"] = time();
            $this->save($state);
            return null;
        }
        if (!$state["item_id"]) {
            $ids = $this->acquisition->product_ids($state["cursor"], 1, $state["upper_id"]);
            if (is_wp_error($ids)) {
                return $ids;
            }
            if (!$ids) {
                $state["traversed"] = true;
                $state["status"] = $state["deferred"] ? "partial" : "complete";
                $state["completed_at"] = time();
                $this->save($state);
                return null;
            }
            $state["item_id"] = $ids[0];
            $state["component"] = "selection";
        }
        return $this->prepare_unretained($state, $state["item_id"], $state["component"]);
    }

    /**
     * While an exact request for this component remains unconfirmed, it is the
     * component's only retained work: replay resolves it before a refresh
     * reaches this point again and reacquires current facts.
     */
    private function prepare_unretained(array &$state, $item_id, $component)
    {
        foreach ($state["deferred"] as $entry) {
            if (!empty($entry["pending"]) && (int) $entry["item_id"] === (int) $item_id && $entry["component"] === $component) {
                $this->advance($state, $component);
                $this->schedule($state);
                return null;
            }
        }
        return $this->prepare_item($state, $item_id, $component);
    }

    private function prepare_default(array &$state)
    {
        $capture = $this->current_configuration($state);
        if (is_wp_error($capture) || null === $capture) {
            return $capture;
        }
        $current = $this->client->shipping_request("getShippingDefault", $state["source"]);
        if (is_wp_error($current)) {
            return $current;
        }
        if (!$this->positive_revision($current["revision"] ?? null)) {
            return Shipping_Mapper::error("invalid_default_response");
        }
        $check = $this->current_configuration($state);
        if (is_wp_error($check) || null === $check) {
            return $check;
        }
        return ["kind" => "default", "operation" => "assignShippingDefault", "arguments" => [[
            "expectedRevision" => $current["revision"], "shippingProfileId" => $state["profile_id"],
            "import" => $this->metadata($state["source"], $capture["acquired_at"]),
        ]]];
    }

    private function prepare_removal(array &$state)
    {
        $entry = $state["removals"][0];
        if (!$this->confirmed_removed($entry["id"])) {
            return $this->reconcile_restored_item($state, $entry["id"]);
        }
        $current = $this->client->shipping_request("getProductListingDelivery", $state["source"], [(string) $entry["id"]]);
        if (is_wp_error($current)) {
            $data = $current->get_error_data();
            if (is_array($data) && 404 === ($data["response_code"] ?? 0)) {
                // A nonexistent catalog listing has no dependent assertion. Do
                // not create/restore it merely to clear shipping membership.
                array_shift($state["removals"]);
                $this->schedule($state);
                return null;
            }
            return $current;
        }
        if (!$this->positive_revision($current["expectedVersion"] ?? null)) {
            return Shipping_Mapper::error("invalid_listing_response");
        }
        if (!$this->confirmed_removed($entry["id"])) {
            return $this->reconcile_restored_item($state, $entry["id"]);
        }
        $patch = ["integration" => Shipping_Mapper::INTEGRATION, "selection" => ["state" => "UNCHANGED"], "rateInputs" => ["state" => "UNCHANGED"], "shipping" => ["state" => "UNCHANGED"], "collection" => ["state" => "UNCHANGED"]];
        $patch["selection" === $entry["component"] ? "selection" : "rateInputs"] = ["state" => "CLEAR"];
        return ["kind" => "remove_" . $entry["component"], "item_id" => $entry["id"], "operation" => "changeProductListingDelivery", "arguments" => [(string) $entry["id"], [
            "expectedVersion" => $current["expectedVersion"], "delivery" => ["state" => "SET", "value" => $patch],
            "import" => $this->metadata($state["source"], Shipping_Acquisition::acquired_at(), false),
        ]]];
    }

    /** A restored item cancels an unsent removal and reacquires both components. */
    private function reconcile_restored_item(array &$state, $item_id)
    {
        global $wpdb;
        $post = get_post($item_id);
        if ($wpdb->last_error || !$post || "product" !== $post->post_type || "publish" !== $post->post_status) {
            return Shipping_Mapper::error("removal_not_confirmed");
        }
        $targets = $state["targets"] ?? [];
        $index = array_search($item_id, array_column($targets, "id"), true);
        if (false === $index) {
            if (count($targets) >= self::MAX_DEFERRED) {
                return Shipping_Mapper::error("item_scope_too_large");
            }
            $targets[] = ["id" => $item_id, "component" => "selection"];
        } else {
            $targets[$index] = ["id" => $item_id, "component" => "selection"];
        }
        $state["targets"] = $targets;
        $state["pending"] = null;
        array_shift($state["removals"]);
        $state["removing"] = false;
        $state["attempt"] = 0;
        $state["last_error"] = "";
        $this->schedule($state);
        return null;
    }

    private function prepare_profile(array &$state)
    {
        $capture = $this->acquisition->configuration();
        if (is_wp_error($capture)) {
            return $capture;
        }
        $mapped = $this->mapper->profile($capture["configuration"]);
        if (is_wp_error($mapped)) {
            return $mapped;
        }
        $profiles = $this->client->shipping_request("listShippingProfiles", $state["source"], [1, null, Shipping_Mapper::OBJECT_KEY, "WOOCOMMERCE"]);
        if (is_wp_error($profiles)) {
            return $profiles;
        }
        if (count($profiles) > 1 || ($profiles && (empty($profiles[0]["shippingProfileId"]) || !$this->positive_revision($profiles[0]["managementRevision"] ?? null)
            || !in_array($profiles[0]["lifecycle"] ?? "", ["ACTIVE", "RETIRED"], true)))) {
            return Shipping_Mapper::error("invalid_profile_response");
        }
        $check = $this->acquisition->configuration();
        if (is_wp_error($check) || $check["fingerprint"] !== $capture["fingerprint"]) {
            return Shipping_Mapper::error("configuration_changed");
        }
        $state["fingerprint"] = $capture["fingerprint"];
        $state["limitations"] = $mapped["limitations"];
        if ($profiles) {
            $profile = $profiles[0];
            if ("RETIRED" === $profile["lifecycle"]) {
                return ["kind" => "restore", "operation" => "restoreShippingProfile", "arguments" => [$profile["shippingProfileId"], [
                    "expectedRevision" => $profile["managementRevision"], "import" => $this->metadata($state["source"], $capture["acquired_at"], false),
                ]]];
            }
            return ["kind" => "profile", "operation" => "replaceShippingProfile", "arguments" => [$profile["shippingProfileId"], [
                "expectedRevision" => $profile["managementRevision"], "content" => $mapped["content"], "evidence" => [],
                "import" => $this->metadata($state["source"], $capture["acquired_at"]),
            ]]];
        }
        $metadata = $this->metadata($state["source"], $capture["acquired_at"]);
        $metadata["objectKey"] = Shipping_Mapper::OBJECT_KEY;
        return ["kind" => "profile", "operation" => "createShippingProfile", "arguments" => [[
            "creationKey" => "wc-shipping-" . hash("sha256", $state["source"] . ":" . Shipping_Mapper::OBJECT_KEY),
            "content" => $mapped["content"], "evidence" => [], "import" => $metadata,
        ]]];
    }

    /** A change during traversal triggers fresh policy acquisition before items. */
    private function current_configuration(array &$state)
    {
        $capture = $this->acquisition->configuration();
        if (is_wp_error($capture)) {
            $state["phase"] = "profile";
            return $capture;
        }
        if ($capture["fingerprint"] !== ($state["fingerprint"] ?? "")) {
            $state["phase"] = "profile";
            $state["replaying"] = false;
            $state["configuration_restarts"] = ($state["configuration_restarts"] ?? 0) + 1;
            if ($state["configuration_restarts"] >= self::MAX_ATTEMPTS) {
                $state["status"] = "needs_review";
                $state["last_error"] = "configuration_keeps_changing";
                $this->save($state);
                return null;
            }
            // Configuration edits can affect products already traversed (for
            // example a deleted shipping class). Revisit the bounded scope.
            $state["cursor"] = 0;
            $state["item_id"] = 0;
            $state["component"] = "selection";
            $state["processed"] = 0;
            $state["traversed"] = false;
            $this->schedule($state);
            return null;
        }
        return $capture;
    }

    private function prepare_item(array &$state, $item_id, $component)
    {
        $capture = $this->current_configuration($state);
        if (is_wp_error($capture) || null === $capture) {
            return $capture;
        }
        $item = $this->acquisition->item($item_id);
        if (is_wp_error($item)) {
            return $item;
        }
        $mapped = $this->mapper->item($item);
        $current = $this->client->shipping_request("getProductListingDelivery", $state["source"], [$mapped["source_listing_id"]]);
        if (is_wp_error($current)) {
            return $current;
        }
        if (!$this->positive_revision($current["expectedVersion"] ?? null)) {
            return Shipping_Mapper::error("invalid_listing_response");
        }
        // A second successful local read detects item changes while the backend
        // revision was read. Never attach that revision to a stale item capture.
        $again = $this->acquisition->item($item_id);
        if (is_wp_error($again) || Shipping_Acquisition::fingerprint($item) !== Shipping_Acquisition::fingerprint($again)) {
            return Shipping_Mapper::error("item_changed");
        }
        $after = $this->current_configuration($state);
        if (is_wp_error($after) || null === $after) {
            return $after;
        }
        $patch = ["integration" => Shipping_Mapper::INTEGRATION, "selection" => ["state" => "UNCHANGED"], "rateInputs" => ["state" => "UNCHANGED"], "shipping" => ["state" => "UNCHANGED"], "collection" => ["state" => "UNCHANGED"]];
        $selection = "SUPPRESS_INHERITANCE" === $mapped["selection"]["kind"] ? $mapped["selection"] : ["kind" => "EXPLICIT", "profile_id" => $state["profile_id"]];
        $patch["selection" === $component ? "selection" : "rateInputs"] = ["state" => "SET", "value" => "selection" === $component ? $selection : $mapped["rate_inputs"]];
        return ["kind" => $component, "item_id" => $item_id, "operation" => "changeProductListingDelivery", "arguments" => [$mapped["source_listing_id"], [
            "expectedVersion" => $current["expectedVersion"], "delivery" => ["state" => "SET", "value" => $patch],
            "import" => $this->metadata($state["source"], Shipping_Acquisition::acquired_at()),
        ]]];
    }

    /** Allocate order only while holding the shared source lock. */
    private function metadata($source, $acquired_at, $observation = true)
    {
        $this->assert_lock($source);
        global $wpdb;
        $name = self::order_option($source);
        wp_cache_delete($name, "options");
        $previous = get_option($name, false);
        $sequence = (int) $previous;
        if ($sequence >= PHP_INT_MAX) {
            throw new \RuntimeException("shipping_sequence_exhausted");
        }
        $sequence++;
        // Compare the previous value. A suspended, expired worker must never
        // decrement a sequence allocated by the next holder of the source lock.
        $saved = false === $previous ? self::insert_option($name, $sequence)
            : 1 === $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $sequence, $name, (string) $previous));
        wp_cache_delete($name, "options");
        if (!$saved) {
            throw new \RuntimeException("shipping_sequence_not_saved");
        }
        $metadata = ["integration" => Shipping_Mapper::INTEGRATION, "sequence" => $sequence, "acquiredAt" => $acquired_at, "requestKey" => "wc-shipping-" . wp_generate_uuid4()];
        if ($observation) {
            $metadata["observation"] = (object) [];
        }
        return $metadata;
    }

    private function advance(array &$state, $kind)
    {
        if (in_array($kind, ["remove_selection", "remove_inputs"], true)) {
            if ("remove_selection" === $kind) {
                $state["removals"][0]["component"] = "inputs";
            } else {
                array_shift($state["removals"]);
                $state["conflicts"] = 0;
            }
            $state["removing"] = false;
            return;
        }
        if (!empty($state["replaying"])) {
            array_shift($state["deferred"]);
            $state["retry_remaining"]--;
            $state["replaying"] = false;
            $state["conflicts"] = 0;
            return;
        }
        if (!empty($state["targeting"]) && in_array($kind, ["selection", "inputs"], true)) {
            if ("selection" === $kind) {
                $state["targets"][0]["component"] = "inputs";
            } elseif (!empty($state["targets"][0]["refresh"])) {
                $state["targets"][0]["component"] = "selection";
                unset($state["targets"][0]["refresh"]);
                $state["conflicts"] = 0;
            } else {
                array_shift($state["targets"]);
                $state["processed"]++;
                $state["conflicts"] = 0;
            }
            $state["targeting"] = false;
            return;
        }
        if ("restore" === $kind) {
            $state["phase"] = "profile";
        } elseif ("profile" === $kind) {
            $state["phase"] = "default";
        } elseif ("default" === $kind) {
            $state["phase"] = "items";
        } elseif ("selection" === $kind) {
            $state["component"] = "inputs";
        } else {
            $state["cursor"] = $state["item_id"];
            $state["item_id"] = 0;
            $state["component"] = "selection";
            $state["processed"]++;
            $state["conflicts"] = 0;
        }
    }

    private function failure(array &$state, \WP_Error $error)
    {
        $data = $error->get_error_data();
        $data = is_array($data) ? $data : [];
        $code = $data["backend_error"] ?? $error->get_error_code();
        $state["last_error"] = $code;
        if (in_array($data["response_code"] ?? 0, [401, 403], true)) {
            $state["status"] = "failed";
            $state["last_error"] = "authorization_changed";
            $this->save($state);
            Shipping_Sync::stop();
            (new Webhook_Manager())->mark_sync_required();
            update_option(self::OPTION_CLEANUP, ["source" => $state["source"], "at" => time(), "result" => "unconfirmed_authorization_change"], false);
            return;
        }
        $pending = $state["pending"];
        $active_item = !empty($state["replaying"]) ? $state["deferred"][0] : (!empty($state["targeting"]) ? $state["targets"][0] : null);
        $kind = $pending["kind"] ?? ($active_item["component"] ?? ("items" === $state["phase"] ? $state["component"] : $state["phase"]));
        if ("SHIPPING_IMPORT_OWNERSHIP_CONFLICT" === $code) {
            if (in_array($kind, ["profile", "restore"], true)) {
                $state["status"] = "needs_review";
                $state["pending"] = null;
                $this->save($state);
                return;
            }
            $state["protected"]++;
            $state["pending"] = null;
            $this->advance($state, $kind);
            $this->schedule($state);
            return;
        }
        if (409 === ($data["response_code"] ?? 0)) {
            // A definite rejection is not an uncertain response. Discard the old
            // payload; the next attempt reacquires local facts and remote state.
            $state["pending"] = null;
            if (!empty($state["replaying"])) {
                $state["deferred"][0]["pending"] = null;
            }
            if (!str_starts_with($kind, "remove_")) {
                // The profile may have retired or lost producer authority even
                // when local settings are unchanged. Reconcile the dependency
                // from a new acquisition before another default/item command.
                $state["phase"] = "profile";
            }
            $state["conflicts"]++;
            if ($state["conflicts"] >= self::MAX_ATTEMPTS) {
                $state["status"] = "needs_review";
                $this->save($state);
                return;
            }
            $this->schedule($state, self::RETRY_DELAY);
            return;
        }
        $state["attempt"]++;
        if ($state["attempt"] < self::MAX_ATTEMPTS) {
            $this->schedule($state, self::RETRY_DELAY * $state["attempt"]);
            return;
        }
        if (in_array($kind, ["selection", "inputs"], true)) {
            // A request that was never sent or was definitely rejected cannot
            // have committed. Keep the component, not the payload, so replay
            // reacquires current facts instead of resending a refused body.
            $rejected = "ahpc_backend_invalid_request" === $error->get_error_code()
                || (($data["response_code"] ?? 0) >= 400 && ($data["response_code"] ?? 0) < 500 && empty($data["retryable"]));
            $entry = ["item_id" => (int) ($pending["item_id"] ?? ($active_item["item_id"] ?? ($active_item["id"] ?? $state["item_id"]))), "component" => $kind, "pending" => $rejected ? null : $pending];
            if (!empty($state["replaying"])) {
                // The replayed entry already owned a slot; never drop its request.
                array_shift($state["deferred"]);
                self::defer($state["deferred"], $entry, false);
                $state["retry_remaining"]--;
                $state["replaying"] = false;
            } elseif (self::defer($state["deferred"], $entry)) {
                $this->advance($state, $kind);
            } else {
                // Stop at the exact checkpoint instead of dropping failed work.
                $state["status"] = "partial";
                $this->save($state);
                return;
            }
            $state["failed"]++;
            $state["pending"] = null;
            $state["attempt"] = 0;
            $this->schedule($state);
            return;
        }
        $state["status"] = "failed";
        $this->save($state);
    }

    /**
     * Retains unresolved work per logical component. Every distinct exact
     * request keeps its own unchanged entry. A failed acquisition sent nothing,
     * so it needs no slot beside an existing entry for the same component. A new
     * request fills a request-less entry instead of taking another slot.
     *
     * @return bool False only when a distinct entry would exceed the bound.
     */
    private static function defer(array &$deferred, array $entry, $bounded = true)
    {
        foreach ($deferred as $index => $existing) {
            if ((int) $existing["item_id"] !== $entry["item_id"] || $existing["component"] !== $entry["component"]) {
                continue;
            }
            if (empty($entry["pending"]) || (!empty($existing["pending"])
                && $existing["pending"]["arguments"][1]["import"]["requestKey"] === $entry["pending"]["arguments"][1]["import"]["requestKey"])) {
                return true;
            }
            if (empty($existing["pending"])) {
                $deferred[$index] = $entry;
                return true;
            }
        }
        if ($bounded && count($deferred) >= self::MAX_DEFERRED) {
            return false;
        }
        $deferred[] = $entry;
        return true;
    }

    /**
     * Drops request-less copies that earlier releases appended for an already
     * retained component. The head entry and every exact request stay in order,
     * so a persisted in-flight replay still resolves its own entry.
     */
    private static function compact_deferred(array $deferred)
    {
        $seen = [];
        $kept = [];
        foreach ($deferred as $entry) {
            $component = (int) $entry["item_id"] . ":" . $entry["component"];
            if (empty($entry["pending"]) && isset($seen[$component])) {
                continue;
            }
            $seen[$component] = true;
            $kept[] = $entry;
        }
        return $kept;
    }

    private function save(array $state)
    {
        $this->assert_lock($state["source"]);
        global $wpdb;
        $name = self::state_option($state["source"]);
        wp_cache_delete($name, "options");
        if (false === get_option($name, false)) {
            $inserted = $this->checkpoint_query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s, %s, 'no' FROM {$wpdb->options} AS lease WHERE lease.option_name = %s AND lease.option_value = %s",
                $name, maybe_serialize($state), $this->lock["name"], $this->lock["value"]
            ));
            self::clear_option_cache($name);
            $this->assert_lock($state["source"]);
            if (1 !== $inserted) {
                throw new \RuntimeException("shipping_checkpoint_failed");
            }
            return;
        }
        // Check ownership in the write statement itself, not only in PHP before
        // it: an expired worker can be suspended between that check and the SQL.
        $changed = $this->checkpoint_query($wpdb->prepare(
            "UPDATE {$wpdb->options} AS checkpoint INNER JOIN {$wpdb->options} AS lease ON lease.option_name = %s SET checkpoint.option_value = %s WHERE checkpoint.option_name = %s AND lease.option_value = %s",
            $this->lock["name"], maybe_serialize($state), $name, $this->lock["value"]
        ));
        wp_cache_delete($name, "options");
        $this->assert_lock($state["source"]);
        if (false === $changed || (0 === $changed && get_option($name) != $state)) {
            throw new \RuntimeException("shipping_checkpoint_failed");
        }
    }

    /** Database diagnostics must not print serialized pending request bodies. */
    private function checkpoint_query($query)
    {
        global $wpdb;
        $suppressed = $wpdb->suppress_errors(true);
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- This private helper receives only queries prepared by save(), which explicitly invalidates its checkpoint option cache.
            return $wpdb->query($query);
        } finally {
            $wpdb->suppress_errors($suppressed);
        }
    }

    private function save_cleanup(array $report)
    {
        global $wpdb;
        $suppressed = $wpdb->suppress_errors(true);
        try {
            update_option(self::OPTION_CLEANUP, $report, false);
        } finally {
            $wpdb->suppress_errors($suppressed);
        }
    }

    private function remember_source($source)
    {
        global $wpdb;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            wp_cache_delete(self::OPTION_SOURCES, "options");
            $old = get_option(self::OPTION_SOURCES, false);
            $sources = is_array($old) ? $old : [];
            if (isset($sources[$source])) {
                return;
            }
            $sources[$source] = true;
            $saved = false === $old ? self::insert_option(self::OPTION_SOURCES, $sources)
                : 1 === $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", maybe_serialize($sources), self::OPTION_SOURCES, maybe_serialize($old)));
            wp_cache_delete(self::OPTION_SOURCES, "options");
            if ($saved) {
                return;
            }
        }
        throw new \RuntimeException("shipping_source_index_failed");
    }

    private function positive_revision($value)
    {
        return is_int($value) && $value > 0;
    }

    private function schedule(array &$state, $delay = 0)
    {
        if (in_array($state["status"] ?? "", ["complete", "partial", "failed", "needs_review"], true)) {
            $state["queued_at"] = time();
        }
        $state["wake"] = wp_generate_uuid4();
        $state["status"] = "queued";
        $this->save($state);
        $id = $delay ? as_schedule_single_action(time() + $delay, self::ACTION_HOOK, [$state["source"], $state["wake"]], self::ACTION_GROUP)
            : as_enqueue_async_action(self::ACTION_HOOK, [$state["source"], $state["wake"]], self::ACTION_GROUP);
        if (!$id) {
            $state["status"] = "failed";
            $state["last_error"] = "scheduling_failed";
            $this->save($state);
        }
        return (bool) $id;
    }

    /**
     * Best effort shutdown of the owned shared profile/default using existing
     * protected commands. This is not source authorization revocation, and
     * cannot promise withdrawal of independently owned/manual components.
     */
    public function withdraw()
    {
        $settings = Shipping_Sync::settings();
        $source = $settings["listing_source_id"];
        $report = ["source" => $source, "at" => time(), "result" => "unconfirmed", "profile" => "unconfirmed", "default" => "unconfirmed"];
        $this->save_cleanup($report);
        $state = $this->get_status($source);
        if (empty($state["profile_id"]) && empty($state["pending"]) && !(int) get_option(self::order_option($source), 0)) {
            // A merely queued initial run has never attempted a remote write.
            $report["result"] = "not_published";
            $report["profile"] = "not_published";
            $report["default"] = "not_owned";
            $this->save_cleanup($report);
            return true;
        }
        if (!Webhook_Manager::is_valid_listing_source_id($source) || !Webhook_Manager::is_valid_access_token($settings["access_token"])
            || !$this->acquire_source_lock($source)) {
            return false;
        }
        try {
            $profiles = $this->client->shipping_request("listShippingProfiles", $source, [1, null, Shipping_Mapper::OBJECT_KEY, "WOOCOMMERCE"]);
            if (is_wp_error($profiles) || count($profiles) > 1) {
                return false;
            }
            if (!$profiles) {
                $report["profile"] = "absent";
                $report["default"] = "not_owned";
            } else {
                $profile = $profiles[0];
                if (empty($profile["shippingProfileId"]) || !$this->positive_revision($profile["managementRevision"] ?? null)) {
                    return false;
                }
                $current = $this->client->shipping_request("getShippingDefault", $source);
                if (!is_wp_error($current) && $this->positive_revision($current["revision"] ?? null)) {
                    if (($current["shippingProfileId"] ?? null) !== $profile["shippingProfileId"]) {
                        $report["default"] = "not_owned";
                    } else {
                        $pending = ["operation" => "clearShippingDefault", "arguments" => [["expectedRevision" => $current["revision"], "import" => $this->metadata($source, Shipping_Acquisition::acquired_at(), false)]]];
                        $report["pending"] = $pending;
                        $this->save_cleanup($report);
                        $this->assert_lock($source);
                        $result = $this->client->shipping_request($pending["operation"], $source, $pending["arguments"]);
                        $report["default"] = $this->cleanup_result($result);
                        if ("unconfirmed" === $report["default"]) {
                            $report["unconfirmed_requests"][] = $pending;
                        }
                        unset($report["pending"]);
                    }
                }
                if ("RETIRED" === ($profile["lifecycle"] ?? "")) {
                    $report["profile"] = "retired";
                } else {
                    $pending = ["operation" => "retireShippingProfile", "arguments" => [$profile["shippingProfileId"], ["expectedRevision" => $profile["managementRevision"], "import" => $this->metadata($source, Shipping_Acquisition::acquired_at(), false)]]];
                    $report["pending"] = $pending;
                    $this->save_cleanup($report);
                    $this->assert_lock($source);
                    $result = $this->client->shipping_request($pending["operation"], $source, $pending["arguments"]);
                    $report["profile"] = !is_wp_error($result) && "RETIRED" === ($result["lifecycle"] ?? "") ? "retired" : $this->cleanup_result($result, false);
                    if ("unconfirmed" === $report["profile"]) {
                        $report["unconfirmed_requests"][] = $pending;
                    }
                    unset($report["pending"]);
                }
            }
            $report["result"] = in_array($report["profile"], ["retired", "absent"], true) && in_array($report["default"], ["committed", "not_owned"], true) ? "committed" : "unconfirmed";
            return "committed" === $report["result"];
        } catch (\Throwable $exception) {
            return false;
        } finally {
            $this->save_cleanup($report);
            $this->release_source_lock();
        }
    }

    private function cleanup_result($result, $valid = true)
    {
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            return is_array($data) && "SHIPPING_IMPORT_OWNERSHIP_CONFLICT" === ($data["backend_error"] ?? "") ? "protected" : "unconfirmed";
        }
        return $valid ? "committed" : "unconfirmed";
    }

    public static function cancel()
    {
        // Invalidating the lease also fences a worker suspended between its
        // PHP ownership check and the atomic checkpoint SQL statement.
        self::clear_option_cache(self::OPTION_SOURCES);
        foreach (array_keys((array) get_option(self::OPTION_SOURCES, [])) as $source) {
            if (Webhook_Manager::is_valid_listing_source_id($source)) {
                delete_option(self::lock_option($source));
            }
        }
        if (function_exists("as_unschedule_all_actions")) {
            as_unschedule_all_actions(self::ACTION_HOOK, null, self::ACTION_GROUP);
        }
    }

    public static function uninstall()
    {
        Shipping_Sync::uninstall();
        self::cancel();
        foreach (array_keys((array) get_option(self::OPTION_SOURCES, [])) as $source) {
            if (Webhook_Manager::is_valid_listing_source_id($source)) {
                delete_option(self::state_option($source));
                delete_option(self::order_option($source));
                delete_option(self::lock_option($source));
            }
        }
        delete_option(self::OPTION_SOURCES);
        delete_option(self::OPTION_CLEANUP);
    }
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
