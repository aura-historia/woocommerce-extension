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
     * Optional upload directory override injected through the `upload_dir` filter.
     *
     * @var array<string,string>|null
     */
    protected $upload_dir_override = null;

    /**
     * Test teardown.
     *
     * @return void
     */
    public function tearDown(): void
    {
        remove_filter("upload_dir", [$this, "filter_upload_dir"]);
        $this->upload_dir_override = null;

        parent::tearDown();
    }

    /**
     * Applies an upload directory override when a test requests one.
     *
     * @param array<string,string> $uploads Current uploads data.
     * @return array<string,string>
     */
    public function filter_upload_dir($uploads)
    {
        if (!is_array($this->upload_dir_override)) {
            return $uploads;
        }

        return array_merge($uploads, $this->upload_dir_override);
    }

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

    /**
     * Verifies no hardcoded uploads path is derived when WordPress reports an error.
     *
     * @return void
     */
    public function test_temp_folder_is_empty_when_upload_directory_is_unavailable()
    {
        $this->upload_dir_override = [
            "path" => "",
            "basedir" => "",
            "url" => "",
            "baseurl" => "",
            "subdir" => "",
            "error" => "Uploads unavailable",
        ];
        add_filter("upload_dir", [$this, "filter_upload_dir"]);

        $configuration = new Configuration();
        $configuration->setDebugFile("../../custom.log");

        $this->assertSame("", $configuration->getTempFolderPath());
        $this->assertSame("php://output", $configuration->getDebugFile());
    }
}
