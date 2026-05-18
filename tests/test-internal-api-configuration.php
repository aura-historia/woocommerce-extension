<?php
/**
 * Integration tests for the generated internal API configuration.
 *
 * @package AuraHistoria\PartnerConnect
 */

use AuraHistoria\PartnerConnect\InternalApi\Configuration;

/**
 * Tests WordPress-safe file path handling in the generated internal API client.
 */
class Test_AHPC_Internal_Api_Configuration extends WP_UnitTestCase
{
    /**
     * Verifies the generated client uses a plugin-managed uploads directory by default.
     *
     * @return void
     */
    public function test_temp_folder_defaults_to_plugin_uploads_directory()
    {
        $configuration = new Configuration();
        $uploads = wp_upload_dir();
        $expected_base = wp_normalize_path(
            trailingslashit($uploads["basedir"]) .
                "aura-historia-partner-connect/internal-api",
        );

        $this->assertEmpty($uploads["error"]);
        $this->assertStringStartsWith(
            $expected_base,
            wp_normalize_path($configuration->getTempFolderPath()),
        );
        $this->assertDirectoryExists($configuration->getTempFolderPath());
    }

    /**
     * Verifies requested temp folders are still constrained to the plugin uploads area.
     *
     * @return void
     */
    public function test_temp_folder_path_is_scoped_to_plugin_uploads_directory()
    {
        $configuration = new Configuration();
        $configuration->setTempFolderPath("../../outside/location");

        $uploads = wp_upload_dir();
        $expected_base = wp_normalize_path(
            trailingslashit($uploads["basedir"]) .
                "aura-historia-partner-connect/internal-api",
        );

        $this->assertEmpty($uploads["error"]);
        $this->assertStringStartsWith(
            $expected_base,
            wp_normalize_path($configuration->getTempFolderPath()),
        );
        $this->assertDirectoryExists($configuration->getTempFolderPath());
    }

    /**
     * Verifies debug output paths are scoped to the plugin uploads area.
     *
     * @return void
     */
    public function test_debug_file_path_is_scoped_to_plugin_uploads_directory()
    {
        $configuration = new Configuration();
        $configuration->setDebugFile("../../custom.log");

        $uploads = wp_upload_dir();
        $expected_prefix = wp_normalize_path(
            trailingslashit($uploads["basedir"]) .
                "aura-historia-partner-connect/internal-api/",
        );
        $actual = wp_normalize_path($configuration->getDebugFile());

        $this->assertEmpty($uploads["error"]);
        $this->assertStringStartsWith($expected_prefix, $actual);
        $this->assertStringEndsWith("/custom.log", $actual);
    }
}
