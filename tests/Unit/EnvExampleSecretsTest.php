<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * C1: .env.example is committed to a public repository, and two real Gmail app
 * passwords once reached the history through it. Credentials belong in the
 * untracked .env and the host's environment; the example file carries keys
 * with empty values only.
 *
 * CI's gitleaks scan is the full-history gate; this is the same rule on the
 * working tree, so a local `php artisan test` catches it before a push.
 */
class EnvExampleSecretsTest extends TestCase
{
    /** Keys whose value is a credential and must be empty in the example file. */
    private const CREDENTIAL_KEYS = [
        'MAIL_USERNAME',
        'MAIL_PASSWORD',
        'PAYSTACK_SECRET_KEY',
        'PAYSTACK_PUBLIC_KEY',
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'APP_KEY',
    ];

    public function test_env_example_leaves_every_credential_empty(): void
    {
        $values = $this->parse(dirname(__DIR__, 2).'/.env.example');

        foreach (self::CREDENTIAL_KEYS as $key) {
            $this->assertArrayHasKey($key, $values, "{$key} should stay documented in .env.example");
            // The value itself is never put in the failure message.
            $this->assertSame('', $values[$key], "{$key} must be empty in .env.example: put the real value in .env or the host environment");
        }
    }

    public function test_env_example_names_no_real_smtp_host_account(): void
    {
        $values = $this->parse(dirname(__DIR__, 2).'/.env.example');

        $this->assertSame('', $values['MAIL_HOST'] ?? null, 'MAIL_HOST must be empty in .env.example');
        $this->assertStringNotContainsString('@gmail.com', strtolower((string) file_get_contents(dirname(__DIR__, 2).'/.env.example')));
    }

    /** @return array<string, string> KEY => unquoted value */
    private function parse(string $path): array
    {
        $values = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if (! preg_match('/^\s*([A-Z0-9_]+)\s*=(.*)$/', $line, $m)) {
                continue;
            }

            $values[$m[1]] = trim(trim($m[2]), '"\'');
        }

        return $values;
    }
}
