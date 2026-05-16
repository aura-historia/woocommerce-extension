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

$manager = new \AuraHistoria\PartnerConnect\Webhook_Manager();
$manager->delete_webhooks();
