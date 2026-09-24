<?php
/**
 * Tests for core helper functions: h(), read_json(), write_json(), get_content_path(), read_content(), write_content().
 */

namespace Aicms\Tests\Unit;

use PHPUnit\Framework\TestCase;

class CoreFunctionsTest extends TestCase
{
    public function test_h_escapes_html()
    {
        $this->assertEquals('&lt;script&gt;', h('<script>'));
        $this->assertEquals('&quot;hello&quot;', h('"hello"'));
        $this->assertEquals('test', h('test'));
        $this->assertEquals('', h(''));
    }

    public function test_h_handles_unicode()
    {
        $this->assertEquals('Привет мир', h('Привет мир'));
        $this->assertEquals('日本語', h('日本語'));
    }

    public function test_read_json_returns_default_for_missing_file()
    {
        $result = read_json('/nonexistent/path/file.json', ['default' => true]);
        $this->assertEquals(['default' => true], $result);
    }

    public function test_read_json_returns_default_for_invalid_json()
    {
        $path = AICMS_TEST_DATA_DIR . '/invalid.json';
        file_put_contents($path, 'not json {{{');
        $result = read_json($path, ['fallback']);
        $this->assertEquals(['fallback'], $result);
    }

    public function test_read_json_parses_valid_json()
    {
        $path = AICMS_TEST_DATA_DIR . '/valid.json';
        file_put_contents($path, json_encode(['key' => 'value', 'num' => 42]));
        $result = read_json($path);
        $this->assertEquals(['key' => 'value', 'num' => 42], $result);
    }

    public function test_write_json_creates_file()
    {
        $path = AICMS_TEST_DATA_DIR . '/new_file.json';
        $data = ['name' => 'test', 'items' => [1, 2, 3]];
        $result = write_json($path, $data);
        $this->assertTrue($result);
        $this->assertFileExists($path);
        $loaded = json_decode(file_get_contents($path), true);
        $this->assertEquals($data, $loaded);
    }

    public function test_write_json_preserves_unicode()
    {
        $path = AICMS_TEST_DATA_DIR . '/unicode.json';
        $data = ['greeting' => 'Привет мир', 'emoji' => '🎉'];
        write_json($path, $data);
        $loaded = json_decode(file_get_contents($path), true);
        $this->assertEquals('Привет мир', $loaded['greeting']);
        $this->assertEquals('🎉', $loaded['emoji']);
    }

    public function test_get_content_path_returns_legacy_path_without_multisite()
    {
        // In test mode, sites.json doesn't exist, so should fall back to legacy path
        $path = get_content_path('pages.json', 'ru');
        $this->assertStringEndsWith('/data/content/ru/pages.json', $path);
    }

    public function test_read_content_returns_default_for_missing()
    {
        $result = read_content('nonexistent.json', ['empty' => true], 'ru');
        $this->assertEquals(['empty' => true], $result);
    }

    public function test_write_content_creates_directory()
    {
        $data = [['id' => 1, 'title' => 'Test page']];
        write_content('pages.json', $data, 'ru');
        $path = get_content_path('pages.json', 'ru');
        $this->assertFileExists($path);
        $loaded = json_decode(file_get_contents($path), true);
        $this->assertEquals($data, $loaded);
    }

    public function test_csrf_token_roundtrip()
    {
        $token = generate_csrf_token();
        $this->assertNotEmpty($token);
        $this->assertTrue(verify_csrf_token($token));
        $this->assertFalse(verify_csrf_token('wrong_token'));
        $this->assertFalse(verify_csrf_token(''));
    }

    public function test_csrf_field_returns_hidden_input()
    {
        $field = csrf_field();
        $this->assertStringStartsWith('<input type="hidden" name="csrf_token" value="', $field);
        $this->assertStringEndsWith('">', $field);
    }
}
