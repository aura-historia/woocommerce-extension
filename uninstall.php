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

$ahpc_manager = new \AuraHistoria\PartnerConnect\Webhook_Manager();
$ahpc_manager->delete_webhooks();
