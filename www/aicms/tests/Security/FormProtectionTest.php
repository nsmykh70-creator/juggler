<?php
/**
 * Tests for the Form Protection module (honeypot + time-trap + rate limit).
 */

namespace Aicms\Tests\Security;

use PHPUnit\Framework\TestCase;

class FormProtectionTest extends TestCase
{
    public function test_form_guard_defaults_returns_known_actions()
    {
        $defaults = form_guard_defaults();
        $this->assertArrayHasKey('comment', $defaults);
        $this->assertArrayHasKey('booking', $defaults);
        $this->assertArrayHasKey('contact', $defaults);
        $this->assertArrayHasKey('newsletter', $defaults);
        $this->assertArrayHasKey('default', $defaults);

        // Each action has max + window
        foreach ($defaults as $action => $cfg) {
            $this->assertArrayHasKey('max', $cfg);
            $this->assertArrayHasKey('window', $cfg);
            $this->assertGreaterThan(0, $cfg['max']);
            $this->assertGreaterThan(0, $cfg['window']);
        }
    }

    public function test_form_guard_check_returns_ok_for_first_request()
    {
        // Clear any previous data
        @unlink(__DIR__ . '/../../data/form_guard.json');
        $result = form_guard_check('comment');
        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($result['message'] ?? 'something');
        $this->assertGreaterThan(0, $result['remaining']);
    }

    public function test_form_honeypot_ok_returns_true_when_empty()
    {
        unset($_POST[FORM_GUARD_HONEYPOT_FIELD]);
        $this->assertTrue(form_honeypot_ok());

        $_POST[FORM_GUARD_HONEYPOT_FIELD] = '';
        $this->assertTrue(form_honeypot_ok());
    }

    public function test_form_honeypot_ok_returns_false_when_filled()
    {
        $_POST[FORM_GUARD_HONEYPOT_FIELD] = 'http://spam-site.com';
        $this->assertFalse(form_honeypot_ok());
        unset($_POST[FORM_GUARD_HONEYPOT_FIELD]);
    }

    public function test_form_timetrap_ok_when_no_field()
    {
        unset($_POST[FORM_GUARD_TIMETRAP_FIELD]);
        $this->assertTrue(form_timetrap_ok());
    }

    public function test_form_timetrap_ok_when_old_enough()
    {
        $_POST[FORM_GUARD_TIMETRAP_FIELD] = (string)(time() - 10);
        $this->assertTrue(form_timetrap_ok());
        unset($_POST[FORM_GUARD_TIMETRAP_FIELD]);
    }

    public function test_form_timetrap_fails_when_too_fast()
    {
        $_POST[FORM_GUARD_TIMETRAP_FIELD] = (string)time();  // Just now
        $this->assertFalse(form_timetrap_ok());
        unset($_POST[FORM_GUARD_TIMETRAP_FIELD]);
    }

    public function test_form_honeypot_field_returns_hidden_html()
    {
        $html = form_honeypot_field();
        $this->assertStringContainsString('<input type="text"', $html);
        $this->assertStringContainsString('name="' . FORM_GUARD_HONEYPOT_FIELD . '"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('left:-9999px', $html);
    }

    public function test_form_timetrap_field_contains_timestamp()
    {
        $html = form_timetrap_field();
        $this->assertStringContainsString('<input type="hidden"', $html);
        $this->assertStringContainsString('name="' . FORM_GUARD_TIMETRAP_FIELD . '"', $html);
        // Should contain a numeric timestamp
        $this->assertMatchesRegularExpression('/value="\d{10}"/', $html);
    }

    public function test_form_guard_full_check_with_clean_request()
    {
        @unlink(__DIR__ . '/../../data/form_guard.json');
        // Clean request: no honeypot, old enough time-trap
        unset($_POST[FORM_GUARD_HONEYPOT_FIELD]);
        $_POST[FORM_GUARD_TIMETRAP_FIELD] = (string)(time() - 10);

        $result = form_guard_full_check('newsletter');
        $this->assertTrue($result['ok']);
        $this->assertFalse($result['silent']);
    }

    public function test_form_guard_full_check_blocks_bot_via_honeypot()
    {
        $_POST[FORM_GUARD_HONEYPOT_FIELD] = 'spam';
        $_POST[FORM_GUARD_TIMETRAP_FIELD] = (string)(time() - 10);

        $result = form_guard_full_check('newsletter');
        $this->assertFalse($result['ok']);
        $this->assertTrue($result['silent'], 'Honeypot trigger should be silent');
        unset($_POST[FORM_GUARD_HONEYPOT_FIELD]);
    }

    public function test_form_guard_full_check_blocks_bot_via_timetrap()
    {
        unset($_POST[FORM_GUARD_HONEYPOT_FIELD]);
        $_POST[FORM_GUARD_TIMETRAP_FIELD] = (string)time();

        $result = form_guard_full_check('newsletter');
        $this->assertFalse($result['ok']);
        $this->assertTrue($result['silent'], 'Time-trap trigger should be silent');
        unset($_POST[FORM_GUARD_TIMETRAP_FIELD]);
    }
}
