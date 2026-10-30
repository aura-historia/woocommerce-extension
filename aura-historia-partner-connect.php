<?php
/**
 * @package AuraHistoria\PartnerConnect
 *
 * @wordpress-plugin
 * Plugin Name:       Aura Historia Partner Connect
 * Description:       Synchronizes WooCommerce Products to Aura Historia to help you reach a global audience for your antiques, art and collectables.
 * Version:           0.3.2
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            Aura Historia
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aura-historia-partner-connect
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 */

if (!defined("ABSPATH")) {
    exit();
}

if (!defined("AHPC_VERSION")) {
    define("AHPC_VERSION", "0.3.2");
}

if (!defined("AHPC_PLUGIN_FILE")) {
    define("AHPC_PLUGIN_FILE", __FILE__);
}

if (!defined("AHPC_PLUGIN_DIR")) {
    define("AHPC_PLUGIN_DIR", plugin_dir_path(__FILE__));
}

if (!defined("AHPC_PLUGIN_BASENAME")) {
    define("AHPC_PLUGIN_BASENAME", plugin_basename(__FILE__));
}

if (!defined("AHPC_BACKEND_BASE_URL")) {
    $ahpc_env_backend_base_url = getenv("AHPC_BACKEND_BASE_URL");
    define(
        "AHPC_BACKEND_BASE_URL",
        $ahpc_env_backend_base_url !== false &&
        $ahpc_env_backend_base_url !== ""
            ? $ahpc_env_backend_base_url
            : "https://api.aura-historia.com",
    );
    unset($ahpc_env_backend_base_url);
}

if (!defined("AHPC_OAUTH_CLIENT_ID")) {
    $ahpc_env_oauth_client_id = getenv("AHPC_OAUTH_CLIENT_ID");
    define(
        "AHPC_OAUTH_CLIENT_ID",
        $ahpc_env_oauth_client_id !== false &&
        $ahpc_env_oauth_client_id !== ""
            ? $ahpc_env_oauth_client_id
            : "oc_01m5vyvraxecksre2zepjcza88",
    );
    unset($ahpc_env_oauth_client_id);
}

if (!defined("AHPC_OAUTH_BROKER_BASE_URL")) {
    $ahpc_env_oauth_broker_base_url = getenv("AHPC_OAUTH_BROKER_BASE_URL");
    define(
        "AHPC_OAUTH_BROKER_BASE_URL",
        $ahpc_env_oauth_broker_base_url !== false &&
        $ahpc_env_oauth_broker_base_url !== ""
            ? $ahpc_env_oauth_broker_base_url
            : "https://aura-historia.com",
    );
    unset($ahpc_env_oauth_broker_base_url);
}

$ahpc_autoload = AHPC_PLUGIN_DIR . "vendor/autoload.php";

if (file_exists($ahpc_autoload)) {
    require_once $ahpc_autoload;
}

require_once AHPC_PLUGIN_DIR . "includes/class-internal-api-autoloader.php";
\AuraHistoria\PartnerConnect\Internal_Api_Autoloader::register();
require_once AHPC_PLUGIN_DIR . "includes/class-backend-api-client.php";
require_once AHPC_PLUGIN_DIR . "includes/class-oauth-broker-client.php";
require_once AHPC_PLUGIN_DIR . "includes/class-store-locale.php";
require_once AHPC_PLUGIN_DIR . "includes/class-option-lease.php";
require_once AHPC_PLUGIN_DIR . "includes/class-webhook-manager.php";
require_once AHPC_PLUGIN_DIR . "includes/class-product-backfill.php";
require_once AHPC_PLUGIN_DIR . "includes/class-shipping-mapper.php";
require_once AHPC_PLUGIN_DIR . "includes/class-shipping-acquisition.php";
require_once AHPC_PLUGIN_DIR . "includes/class-shipping-publisher.php";
require_once AHPC_PLUGIN_DIR . "includes/class-shipping-sync.php";
require_once AHPC_PLUGIN_DIR . "includes/class-plugin.php";

/**
 * Returns the plugin singleton.
 *
 * @return \AuraHistoria\PartnerConnect\Plugin
 */
function ahpc_plugin()
{
    return \AuraHistoria\PartnerConnect\Plugin::instance();
}

register_activation_hook(AHPC_PLUGIN_FILE, [
    "\\AuraHistoria\\PartnerConnect\\Plugin",
    "activate",
]);
register_deactivation_hook(AHPC_PLUGIN_FILE, [
    "\\AuraHistoria\\PartnerConnect\\Plugin",
    "deactivate",
]);

add_action("plugins_loaded", [ahpc_plugin(), "boot"]);
