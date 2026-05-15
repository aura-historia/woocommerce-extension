<?php
/**
 * Internal OpenAPI class autoloader.
 *
 * @package AuraHistoria\PartnerConnect
 */

namespace AuraHistoria\PartnerConnect;

if (!defined("ABSPATH")) {
    exit();
}

/**
 * Loads generated internal API classes on demand.
 */
class Internal_Api_Autoloader
{
    /**
     * Internal API namespace prefix.
     */
    const NAMESPACE_PREFIX = __NAMESPACE__ . "\\InternalApi\\";

    /**
     * Whether the autoloader has already been registered.
     *
     * @var bool
     */
    private static $registered = false;

    /**
     * Registers the autoloader once.
     *
     * @return void
     */
    public static function register()
    {
        if (self::$registered) {
            return;
        }

        spl_autoload_register([__CLASS__, "autoload"]);
        self::$registered = true;
    }

    /**
     * Loads an internal API class file if it belongs to the generated namespace.
     *
     * @param string $class Fully-qualified class name.
     * @return void
     */
    public static function autoload($class)
    {
        if (0 !== strpos($class, self::NAMESPACE_PREFIX)) {
            return;
        }

        $relative_class = substr($class, strlen(self::NAMESPACE_PREFIX));
        $relative_path = str_replace("\\", "/", $relative_class) . ".php";
        $file = AHPC_PLUGIN_DIR . "includes/internal-api/" . $relative_path;

        if (file_exists($file)) {
            require_once $file;
        }
    }
}
