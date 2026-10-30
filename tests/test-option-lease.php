<?php
/**
 * Integration tests for lease-fenced option writes.
 *
 * @package AuraHistoria\PartnerConnect
 */

use AuraHistoria\PartnerConnect\Option_Lease;

class Test_AHPC_Option_Lease extends WP_UnitTestCase
{
    const LEASE = "ahpc_test_lease";
    const OPTION = "ahpc_test_leased_option";

    public function tearDown(): void
    {
        delete_option(self::LEASE);
        delete_option(self::OPTION);
        parent::tearDown();
    }

    private function expire()
    {
        global $wpdb;
        $lease = json_decode((string) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::LEASE,
        )), true);
        $lease["until"] = time() - 1;
        $wpdb->update($wpdb->options, ["option_value" => wp_json_encode($lease)], ["option_name" => self::LEASE]);
    }

    public function test_only_one_holder_until_release_or_expiry()
    {
        $first = new Option_Lease(self::LEASE);
        $second = new Option_Lease(self::LEASE);

        $this->assertTrue($first->acquire(60));
        $this->assertTrue($first->held());
        $this->assertFalse($second->acquire(60));
        $first->release();
        $this->assertFalse($first->held());
        $this->assertTrue($second->acquire(60));

        $this->expire();
        $this->assertFalse($second->held());
        $this->assertTrue($first->acquire(60));
        $this->assertFalse($second->write(self::OPTION, "stale"));
        $second->release();
        $this->assertTrue($first->held());
    }

    public function test_writes_commit_only_while_held()
    {
        $holder = new Option_Lease(self::LEASE);
        $this->assertFalse($holder->write(self::OPTION, ["value" => 1]));
        $this->assertTrue($holder->acquire(60));

        $this->assertTrue($holder->write(self::OPTION, ["value" => 1]));
        $this->assertSame(["value" => 1], get_option(self::OPTION));
        // An unchanged value affects no rows but is still a successful write.
        $this->assertTrue($holder->write(self::OPTION, ["value" => 1]));
        $this->assertSame(["value" => 1], Option_Lease::read(self::OPTION));
        $this->assertTrue($holder->delete(self::OPTION));
        $this->assertTrue($holder->delete(self::OPTION));
        $this->assertFalse(get_option(self::OPTION, false));
        $this->assertTrue($holder->write(self::OPTION, "current"));

        (new Option_Lease(self::LEASE))->seize(60);

        $this->assertFalse($holder->held());
        $this->assertFalse($holder->write(self::OPTION, "fenced"));
        $this->assertFalse($holder->write(self::OPTION, "current"));
        $this->assertFalse($holder->delete(self::OPTION));
        $this->assertSame("current", get_option(self::OPTION));
    }

    public function test_a_cached_value_cannot_hide_a_leased_write()
    {
        update_option(self::OPTION, "cached", false);
        $this->assertSame("cached", get_option(self::OPTION));
        $holder = new Option_Lease(self::LEASE);
        $this->assertTrue($holder->acquire(60));

        $this->assertTrue($holder->write(self::OPTION, "written"));
        $this->assertSame("written", get_option(self::OPTION));
        $this->assertTrue($holder->delete(self::OPTION));
        $this->assertFalse(get_option(self::OPTION, false));
    }
}
