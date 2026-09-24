<?php
/**
 * Tests for the Multi-site Manager.
 */

namespace Aicms\Tests\Unit;

use PHPUnit\Framework\TestCase;

class MultisiteTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Clean up any existing sites.json
        @unlink(__DIR__ . '/../../data/sites.json');
        // Also remove site dirs
        $sites_dir = __DIR__ . '/../../data/sites';
        if (is_dir($sites_dir)) {
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sites_dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($rii as $file) {
                $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
            }
            rmdir($sites_dir);
        }
    }

    public function test_get_sites_creates_default_on_first_run()
    {
        // First call should auto-create the default site
        $sites = get_sites();
        $this->assertIsArray($sites);
        $this->assertGreaterThanOrEqual(1, count($sites));

        $default = $sites[0];
        $this->assertEquals('default', $default['id']);
        $this->assertNotEmpty($default['name']);
        $this->assertNotEmpty($default['theme']);
        $this->assertTrue($default['enabled']);
    }

    public function test_get_site_returns_site_by_id()
    {
        $site = get_site('default');
        $this->assertNotNull($site);
        $this->assertEquals('default', $site['id']);
    }

    public function test_get_site_returns_null_for_unknown()
    {
        $this->assertNull(get_site('nonexistent-site-id'));
    }

    public function test_current_site_returns_default()
    {
        $site = current_site();
        $this->assertNotNull($site);
        $this->assertEquals('default', $site['id']);
    }

    public function test_current_site_id_returns_default()
    {
        $this->assertEquals('default', current_site_id());
    }

    public function test_create_site_with_valid_data()
    {
        $result = create_site([
            'id' => 'test-site',
            'name' => 'Test Site',
            'host' => 'test.example.com',
            'theme' => 'aurora',
            'default_lang' => 'ru',
            'languages' => [['code' => 'ru'], ['code' => 'en']],
        ]);
        $this->assertTrue($result, 'create_site should return true on success');

        // Verify the site was created
        $site = get_site('test-site');
        $this->assertNotNull($site);
        $this->assertEquals('Test Site', $site['name']);
        $this->assertEquals('test.example.com', $site['host']);
        $this->assertEquals('aurora', $site['theme']);
        $this->assertTrue($site['enabled']);

        // Verify content directory was created
        $this->assertDirectoryExists(__DIR__ . '/../../data/sites/test-site');
        $this->assertFileExists(__DIR__ . '/../../data/sites/test-site/content/ru/pages.json');
        $this->assertFileExists(__DIR__ . '/../../data/sites/test-site/content/ru/news.json');
        $this->assertFileExists(__DIR__ . '/../../data/sites/test-site/content/en/pages.json');

        // Verify per-site config.json was created
        $this->assertFileExists(__DIR__ . '/../../data/sites/test-site/config.json');
    }

    public function test_create_site_rejects_duplicate_id()
    {
        $result = create_site([
            'id' => 'default',  // already exists
            'name' => 'Another',
            'host' => '',
            'theme' => 'aurora',
            'default_lang' => 'ru',
        ]);
        $this->assertIsString($result, 'Should return error message for duplicate ID');
        $this->assertStringContainsString('already exists', $result);
    }

    public function test_create_site_rejects_invalid_id()
    {
        $result = create_site([
            'id' => '',  // empty
            'name' => 'Invalid',
            'host' => '',
            'theme' => 'aurora',
            'default_lang' => 'ru',
        ]);
        $this->assertIsString($result);
        $this->assertStringContainsString('must be', $result);
    }

    public function test_create_site_rejects_duplicate_host()
    {
        // First create a site with a specific host
        create_site([
            'id' => 'site-a',
            'name' => 'Site A',
            'host' => 'unique.example.com',
            'theme' => 'aurora',
            'default_lang' => 'ru',
        ]);

        // Try to create another with the same host
        $result = create_site([
            'id' => 'site-b',
            'name' => 'Site B',
            'host' => 'unique.example.com',
            'theme' => 'aurora',
            'default_lang' => 'ru',
        ]);
        $this->assertIsString($result);
        $this->assertStringContainsString('Host already used', $result);
    }

    public function test_update_site_modifies_fields()
    {
        create_site([
            'id' => 'update-test',
            'name' => 'Before Update',
            'host' => '',
            'theme' => 'aurora',
            'default_lang' => 'ru',
        ]);

        $result = update_site('update-test', [
            'name' => 'After Update',
            'host' => 'updated.example.com',
            'theme' => 'helios',
            'enabled' => false,
        ]);
        $this->assertTrue($result);

        $site = get_site('update-test');
        $this->assertEquals('After Update', $site['name']);
        $this->assertEquals('updated.example.com', $site['host']);
        $this->assertEquals('helios', $site['theme']);
        $this->assertFalse($site['enabled']);
    }

    public function test_delete_site_removes_non_default()
    {
        create_site([
            'id' => 'to-delete',
            'name' => 'To Delete',
            'host' => '',
            'theme' => 'aurora',
            'default_lang' => 'ru',
        ]);
        $this->assertNotNull(get_site('to-delete'));

        $result = delete_site('to-delete');
        $this->assertTrue($result);
        $this->assertNull(get_site('to-delete'));
    }

    public function test_delete_site_refuses_default()
    {
        $result = delete_site('default');
        $this->assertFalse($result, 'Cannot delete the default site');
        $this->assertNotNull(get_site('default'), 'Default site should still exist');
    }

    public function test_is_multisite_returns_true_when_more_than_one_site()
    {
        // We've created several test sites above, so this should be true
        $this->assertTrue(is_multisite());
    }

    public function test_set_current_site_persists_in_session()
    {
        create_site([
            'id' => 'session-test',
            'name' => 'Session Test',
            'host' => '',
            'theme' => 'aurora',
            'default_lang' => 'ru',
        ]);

        $result = set_current_site('session-test');
        $this->assertTrue($result);
        $this->assertEquals('session-test', $_SESSION['aicms_current_site'] ?? '');

        // current_site() should now return the switched site
        $site = current_site();
        $this->assertEquals('session-test', $site['id']);

        // Reset back to default
        set_current_site('default');
    }

    public function test_set_current_site_rejects_unknown_id()
    {
        $result = set_current_site('does-not-exist');
        $this->assertFalse($result);
    }

    public static function tearDownAfterClass(): void
    {
        // Clean up test sites
        @unlink(__DIR__ . '/../../data/sites.json');
        $sites_dir = __DIR__ . '/../../data/sites';
        if (is_dir($sites_dir)) {
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sites_dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($rii as $file) {
                $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
            }
            rmdir($sites_dir);
        }
        unset($_SESSION['aicms_current_site']);
    }
}
