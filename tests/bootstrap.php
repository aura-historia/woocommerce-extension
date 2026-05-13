<?php
/**
 * PHPUnit bootstrap for WordPress integration tests.
 */

$_tests_dir = getenv("WP_TESTS_DIR");

if (!$_tests_dir) {
    $_tests_dir = "/tmp/wordpress-tests-lib";
}

$_tests_dir = rtrim((string) $_tests_dir, "/\\");

$plugin_dir = dirname(__DIR__);
$autoload = $plugin_dir . "/vendor/autoload.php";
$polyfills = $plugin_dir . "/vendor/yoast/phpunit-polyfills";

if (file_exists($autoload)) {
    require_once $autoload;
}

if (!defined("WP_TESTS_PHPUNIT_POLYFILLS_PATH") && is_dir($polyfills)) {
    define("WP_TESTS_PHPUNIT_POLYFILLS_PATH", $polyfills);
}

require_once $_tests_dir . "/includes/functions.php";

tests_add_filter("muplugins_loaded", static function () {
    $woocommerce_files = glob(WP_PLUGIN_DIR . "/*/woocommerce.php");

    if (empty($woocommerce_files)) {
        throw new RuntimeException(
            "WooCommerce bootstrap file was not found in the test environment.",
        );
    }

    require_once $woocommerce_files[0];
    require_once dirname(__DIR__) . "/aura-historia-partner-connect.php";
});

require $_tests_dir . "/includes/bootstrap.php";
