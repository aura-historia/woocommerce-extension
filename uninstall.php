<?php
/**
 * Plugin uninstall routine.
 *
 * @package AuraHistoria\PartnerConnect
 */

if (!defined("WP_UNINSTALL_PLUGIN")) {
    exit();
}

require_once __DIR__ . "/includes/class-webhook-manager.php";
require_once __DIR__ . "/includes/class-product-backfill.php";
require_once __DIR__ . "/includes/class-shipping-publisher.php";
require_once __DIR__ . "/includes/class-shipping-sync.php";

if (file_exists(__DIR__ . "/vendor/autoload.php")) {
    require_once __DIR__ . "/vendor/autoload.php";
}
if (!defined("AHPC_PLUGIN_DIR")) {
    define("AHPC_PLUGIN_DIR", plugin_dir_path(__FILE__));
}
if (!defined("AHPC_BACKEND_BASE_URL")) {
    define("AHPC_BACKEND_BASE_URL", getenv("AHPC_BACKEND_BASE_URL") ?: "https://api.aura-historia.com");
}
require_once __DIR__ . "/includes/class-internal-api-autoloader.php";
\AuraHistoria\PartnerConnect\Internal_Api_Autoloader::register();
require_once __DIR__ . "/includes/class-backend-api-client.php";
require_once __DIR__ . "/includes/class-store-locale.php";
require_once __DIR__ . "/includes/class-shipping-acquisition.php";
require_once __DIR__ . "/includes/class-shipping-mapper.php";

\AuraHistoria\PartnerConnect\Shipping_Sync::stop();
// Dependencies are normally loaded by WordPress. Uninstall must also work when
// the plugin was inactive and its Composer/API runtime is unavailable.
if (!(new \AuraHistoria\PartnerConnect\Shipping_Publisher())->withdraw()) {
    // No credentials, payloads or private exception text in uninstall logs.
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Uninstall removes local status; this fixed operational diagnostic makes unconfirmed remote cleanup actionable.
    error_log("Aura Historia: shipping cleanup was not confirmed. Review retained shipping assertions in Aura Historia.");
}

$ahpc_manager = new \AuraHistoria\PartnerConnect\Webhook_Manager();
$ahpc_manager->delete_webhooks();
