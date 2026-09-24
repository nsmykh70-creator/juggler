<?php
/**
 * Tests for forum_format() BBCode rendering — XSS hardening of [url=] tags.
 *
 * Covers: javascript: scheme, tab/newline scheme-normalization bypass,
 * double-encoded schemes, attribute breakout, and legitimate links.
 */

namespace Aicms\Tests\Security;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../modules/forum_format.php';

class ForumFormatTest extends TestCase
{
    public function test_javascript_scheme_is_rendered_as_plain_text()
    {
        $out = forum_format('[url=javascript:alert(document.cookie)]x[/url]');
        $this->assertSame('x', $out);
        $this->assertStringNotContainsString('<a ', $out);
    }

    public function test_uppercase_javascript_scheme_is_rejected()
    {
        $out = forum_format('[url=JaVaScRiPt:alert(1)]x[/url]');
        $this->assertSame('x', $out);
    }

    public function test_tab_bypass_in_scheme_is_neutralized()
    {
        $out = forum_format("[url=java\tscript:alert(1)]x[/url]");
        $this->assertSame('x', $out);
        $this->assertStringNotContainsString('href=', $out);
    }

    public function test_newline_bypass_in_scheme_is_neutralized()
    {
        $out = forum_format("[url=java\nscript:alert(1)]x[/url]");
        $this->assertSame('x', $out);
    }

    public function test_double_encoded_scheme_is_not_decoded_into_href()
    {
        // &#106;avascript: decodes to 'javascript:' in the browser — must not become a link
        $out = forum_format('[url=&#106;avascript:alert(1)]x[/url]');
        $this->assertSame('x', $out);
    }

    public function test_data_and_vbscript_schemes_are_rejected()
    {
        $this->assertSame('x', forum_format('[url=data:text/html,<script>alert(1)</script>]x[/url]'));
        $this->assertSame('x', forum_format('[url=vbscript:msgbox(1)]x[/url]'));
    }

    public function test_attribute_breakout_is_escaped()
    {
        $out = forum_format('[url=https://example.com" onclick="alert(1)]x[/url]');
        // Quotes stay entity-escaped inside the href value — no new attribute can be injected
        $this->assertStringContainsString('<a href="https://example.com&quot; onclick=&quot;alert(1)"', $out);
        $this->assertStringNotContainsString(' onclick="alert(1)"', $out);
    }

    public function test_legitimate_http_link_renders_with_rel_noopener()
    {
        $out = forum_format('[url=http://example.com/page]Visit[/url]');
        $this->assertSame(
            '<a href="http://example.com/page" target="_blank" rel="nofollow noopener">Visit</a>',
            $out
        );
    }

    public function test_legitimate_https_link_with_query_string_keeps_escaped_ampersand()
    {
        $out = forum_format('[url=https://example.com/?a=1&b=2]Go[/url]');
        $this->assertStringContainsString(
            '<a href="https://example.com/?a=1&amp;b=2" target="_blank" rel="nofollow noopener">Go</a>',
            $out
        );
    }

    public function test_mailto_and_ftp_are_allowed()
    {
        $this->assertStringContainsString(
            '<a href="mailto:user@example.com" target="_blank" rel="nofollow noopener">Email</a>',
            forum_format('[url=mailto:user@example.com]Email[/url]')
        );
        $this->assertStringContainsString(
            '<a href="ftp://files.example.com" target="_blank" rel="nofollow noopener">Files</a>',
            forum_format('[url=ftp://files.example.com]Files[/url]')
        );
    }

    public function test_link_text_stays_escaped()
    {
        $out = forum_format('[url=https://example.com]<img src=x onerror=alert(1)>[/url]');
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $out);
        $this->assertStringNotContainsString('<img', $out);
    }
}
