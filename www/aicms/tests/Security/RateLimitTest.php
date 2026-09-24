<?php
/**
 * Tests for the Rate Limiter (admin login + API rate limit).
 */

namespace Aicms\Tests\Security;

use PHPUnit\Framework\TestCase;

class RateLimitTest extends TestCase
{
    public function setUp(): void
    {
        // Clean rate limit files
        @unlink(__DIR__ . '/../../data/login_attempts.json');
        @unlink(__DIR__ . '/../../data/api_rate_limit.json');
    }

    public function test_rate_limit_check_returns_unblocked_for_first_attempt()
    {
        $result = rate_limit_check('test_action');
        $this->assertFalse($result['blocked']);
        $this->assertEquals(0, $result['attempts']);
        $this->assertEquals(0, $result['wait_seconds']);
    }

    public function test_rate_limit_fail_increments_count()
    {
        rate_limit_fail('test_action');
        $result = rate_limit_check('test_action');
        $this->assertEquals(1, $result['attempts']);

        rate_limit_fail('test_action');
        $result = rate_limit_check('test_action');
        $this->assertEquals(2, $result['attempts']);
    }

    public function test_rate_limit_blocks_after_max_attempts()
    {
        // Default is 5 attempts
        for ($i = 0; $i < 5; $i++) {
            rate_limit_fail('test_action');
        }
        $result = rate_limit_check('test_action');
        $this->assertTrue($result['blocked']);
        $this->assertGreaterThan(0, $result['wait_seconds']);
    }

    public function test_rate_limit_reset_clears_attempts()
    {
        for ($i = 0; $i < 3; $i++) {
            rate_limit_fail('test_action');
        }
        rate_limit_reset('test_action');
        $result = rate_limit_check('test_action');
        $this->assertFalse($result['blocked']);
        $this->assertEquals(0, $result['attempts']);
    }

    public function test_rate_limit_remaining_decreases()
    {
        $this->assertEquals(5, rate_limit_remaining('test_action'));
        rate_limit_fail('test_action');
        $this->assertEquals(4, rate_limit_remaining('test_action'));
        rate_limit_fail('test_action');
        $this->assertEquals(3, rate_limit_remaining('test_action'));
    }

    public function test_rate_limit_remaining_zero_when_blocked()
    {
        for ($i = 0; $i < 5; $i++) {
            rate_limit_fail('test_action');
        }
        $this->assertEquals(0, rate_limit_remaining('test_action'));
    }

    public function test_api_rate_limit_allows_within_quota()
    {
        // First 5 requests should be allowed
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(api_rate_limit('test_api', 5, 60));
        }
    }

    public function test_api_rate_limit_blocks_over_quota()
    {
        // Use up the quota
        for ($i = 0; $i < 3; $i++) {
            api_rate_limit('test_api_block', 3, 60);
        }
        // 4th request should be blocked
        $this->assertFalse(api_rate_limit('test_api_block', 3, 60));
    }

    public function test_api_rate_limit_separates_buckets()
    {
        // Use up bucket A
        for ($i = 0; $i < 2; $i++) {
            api_rate_limit('bucket_a', 2, 60);
        }
        // Bucket B should still have full quota
        $this->assertTrue(api_rate_limit('bucket_b', 2, 60));
        $this->assertTrue(api_rate_limit('bucket_b', 2, 60));
        // Bucket A should be blocked
        $this->assertFalse(api_rate_limit('bucket_a', 2, 60));
    }

    public function test_api_rate_limit_status_returns_correct_format()
    {
        $status = api_rate_limit_status('status_test', 10, 60);
        $this->assertArrayHasKey('limit', $status);
        $this->assertArrayHasKey('remaining', $status);
        $this->assertArrayHasKey('reset', $status);
        $this->assertEquals(10, $status['limit']);
        $this->assertEquals(10, $status['remaining']);
    }

    public function tearDown(): void
    {
        @unlink(__DIR__ . '/../../data/login_attempts.json');
        @unlink(__DIR__ . '/../../data/api_rate_limit.json');
    }
}
