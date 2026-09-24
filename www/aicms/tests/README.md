# AICMS Test Suite

This directory contains PHPUnit tests for the AICMS project.

## Setup

Tests require PHP 7.4+ and Composer.

```bash
# Install PHPUnit via Composer (one-time)
cd /path/to/aicms
composer install

# Run all tests
composer test
# OR directly:
vendor/bin/phpunit
```

If Composer is not available, the test bootstrap (`tests/bootstrap.php`) falls back to manually `require_once`-ing the core files. In that case you'll need to install PHPUnit separately:

```bash
# Ubuntu/Debian
sudo apt install phpunit

# Run via system phpunit
phpunit --bootstrap tests/bootstrap.php tests/
```

## Test Structure

```
tests/
├── bootstrap.php                — test environment setup (creates temp data dir, loads core)
├── Unit/                        — unit tests for core functions
│   ├── CoreFunctionsTest.php    — h(), read_json(), write_json(), CSRF helpers
│   └── MultisiteTest.php        — site CRUD, host detection, session switching
└── Security/                    — security-focused tests
    ├── TotpTest.php             — RFC 6238 TOTP + backup codes
    ├── FormProtectionTest.php   — honeypot, time-trap, rate limit on forms
    └── RateLimitTest.php        — login attempt limiter + API rate limiter
```

## What's Tested

### Core Functions (`CoreFunctionsTest`)
- HTML escaping via `h()` — including Unicode (Cyrillic, CJK)
- JSON read/write — file creation, Unicode preservation, error fallback
- Content path resolution — legacy vs multi-site mode
- CSRF token generation and verification — roundtrip, invalid tokens

### TOTP (`TotpTest`)
- Secret generation — random Base32, 16 chars
- URI generation for QR codes — `otpauth://` format
- Code verification — current code, ±60s window, rejection of old codes
- Backup codes — generation, hashing (sha256), verification, invalidation, case-insensitivity

### Form Protection (`FormProtectionTest`)
- Honeypot field — accepts empty, rejects filled
- Time-trap — accepts old timestamps, rejects fast submits
- Rate limit — first request allowed, blocking after quota
- Combined check — silent mode for bots, error message for rate limit

### Rate Limiter (`RateLimitTest`)
- Login attempt tracking — count increments, blocks after 5
- API rate limiter — bucket separation, quota tracking
- Reset functionality — clears attempts after success

### Multi-site (`MultisiteTest`)
- Default site auto-creation on first run
- Site CRUD — create, update, delete (default cannot be deleted)
- Host uniqueness validation
- Session-based site switching
- Per-site content directory creation

## Running Specific Tests

```bash
# Run only security tests
vendor/bin/phpunit --testsuite "AICMS Security"

# Run only the TOTP test class
vendor/bin/phpunit tests/Security/TotpTest.php

# Run a single test method
vendor/bin/phpunit --filter test_rfc6238_test_vectors tests/Security/TotpTest.php
```

## Coverage

To generate code coverage reports:

```bash
# Requires xdebug enabled
vendor/bin/phpunit --coverage-html coverage/
```

Then open `coverage/index.html` in a browser.

## Adding New Tests

1. Pick the appropriate subdirectory (`Unit/`, `Security/`, or `Modules/`).
2. Create a new file `<Name>Test.php`.
3. Follow PSR-4 naming: class `NameTest` in namespace `Aicms\Tests\<Subdir>`.
4. Use the helpers in `tests/bootstrap.php`:
   - `AICMS_TEST_DATA_DIR` — temp dir for test files (auto-cleaned)
   - `test_write_file($rel, $content)` — write to test data dir
   - `test_read_file($rel)` — read from test data dir
5. Always clean up test data in `tearDown()` or use `@unlink()`.

## CI Integration

Add this to your CI pipeline (GitHub Actions, GitLab CI, etc.):

```yaml
# .github/workflows/tests.yml
name: Tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          coverage: xdebug
      - run: composer install --no-interaction
      - run: vendor/bin/phpunit
```
