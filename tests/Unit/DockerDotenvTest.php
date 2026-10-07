<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The container's .env (docker/write-dotenv.sh, sourced by docker-entrypoint.sh).
 *
 * The entrypoint used to copy every environment value into .env unquoted, so a
 * value with a space — MAIL_FROM_NAME=FEYRA Payments — made the file unparsable
 * and Laravel refused to boot on all three Render services. These tests run the
 * real helper under `sh` against the real .env.example, then load the result
 * exactly as Laravel does (Dotenv over Env's immutable repository) in a child
 * PHP process carrying that environment.
 */
class DockerDotenvTest extends TestCase
{
    private const AWKWARD_PASSWORD = 'p@ss word #1 \n "q" \'s\' $HOME ${APP_NAME}';

    private string $root;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = str_replace('\\', '/', dirname(__DIR__, 2));
        $this->dir = str_replace('\\', '/', sys_get_temp_dir()).'/feyra-dotenv-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dir.'/.env')) {
            unlink($this->dir.'/.env');
        }
        rmdir($this->dir);

        parent::tearDown();
    }

    public function test_values_with_spaces_and_shell_characters_reach_laravel_unchanged(): void
    {
        $values = $this->boot([
            'APP_NAME' => 'FEYRA Payments',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'MAIL_PASSWORD' => self::AWKWARD_PASSWORD,
            'MAIL_FROM_NAME' => false,
        ]);

        $this->assertSame('FEYRA Payments', $values['APP_NAME']);
        $this->assertSame(self::AWKWARD_PASSWORD, $values['MAIL_PASSWORD']);
        $this->assertSame('base64:'.base64_encode(str_repeat('k', 32)), $values['APP_KEY']);
        // The committed default MAIL_FROM_NAME="${APP_NAME}" still resolves, now
        // against a value that lives only in the environment.
        $this->assertSame('FEYRA Payments', $values['MAIL_FROM_NAME']);
    }

    public function test_real_values_never_touch_the_file_and_defaults_fill_the_gaps(): void
    {
        $values = $this->boot([
            'APP_NAME' => 'FEYRA Payments',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'MAIL_PASSWORD' => self::AWKWARD_PASSWORD,
            'MAIL_HOST' => false,
            // Render can leave a sync:false key blank: it falls back to the default.
            'LOG_LEVEL' => '',
        ]);

        $env = (string) file_get_contents($this->dir.'/.env');

        $this->assertDoesNotMatchRegularExpression('/^APP_NAME=/m', $env);
        $this->assertDoesNotMatchRegularExpression('/^MAIL_PASSWORD=/m', $env);
        $this->assertDoesNotMatchRegularExpression('/^APP_KEY=/m', $env, 'APP_KEY must come from the environment only');
        $this->assertStringNotContainsString('p@ss', $env);

        $this->assertMatchesRegularExpression('/^MAIL_HOST=$/m', $env);
        $this->assertSame('', $values['MAIL_HOST']);
        $this->assertSame('debug', $values['LOG_LEVEL']);
        $this->assertSame('https://api.paystack.co', $values['PAYSTACK_PAYMENT_URL']);
    }

    public function test_the_entrypoint_sources_the_helper_and_the_image_contains_it(): void
    {
        $entrypoint = (string) file_get_contents($this->root.'/docker/docker-entrypoint.sh');
        $ignore = array_map('trim', file($this->root.'/.dockerignore'));

        $this->assertStringContainsString('. /app/docker/write-dotenv.sh', $entrypoint);
        $this->assertStringContainsString('write_dotenv .env.example .env', $entrypoint);
        $this->assertStringNotContainsString('echo \${', $entrypoint, 'values must not be re-echoed into .env');
        foreach (['docker', 'docker/', 'docker/*', '/docker'] as $entry) {
            $this->assertNotContains($entry, $ignore, 'the helper must be in the build context');
        }
    }

    /**
     * Run the helper with this environment, then load the written .env as
     * Laravel's LoadEnvironmentVariables does and return what Env::get() sees.
     *
     * @param  array<string, string|false>  $env  false removes the variable
     * @return array<string, string|null>
     */
    private function boot(array $env): array
    {
        $sh = (new ExecutableFinder)->find('sh');
        if ($sh === null) {
            $this->markTestSkipped('No POSIX sh available.');
        }

        $check = <<<'PHP'
            require $argv[1].'/vendor/autoload.php';
            Dotenv\Dotenv::create(Illuminate\Support\Env::getRepository(), $argv[2], '.env')->load();
            $keys = ['APP_NAME', 'APP_KEY', 'MAIL_PASSWORD', 'MAIL_FROM_NAME', 'MAIL_HOST', 'LOG_LEVEL', 'PAYSTACK_PAYMENT_URL'];
            echo json_encode(array_combine($keys, array_map(fn ($k) => Illuminate\Support\Env::get($k), $keys)));
            PHP;

        $process = new Process(
            [$sh, '-c', '. "$HELPER" && write_dotenv "$EXAMPLE" "$OUT_DIR/.env" && exec "$PHP_BIN" -r "$CHECK" "$ROOT" "$OUT_DIR"'],
            $this->root,
            $env + [
                'HELPER' => $this->root.'/docker/write-dotenv.sh',
                'EXAMPLE' => $this->root.'/.env.example',
                'OUT_DIR' => $this->dir,
                'ROOT' => $this->root,
                'PHP_BIN' => str_replace('\\', '/', PHP_BINARY),
                'CHECK' => $check,
            ],
        );
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
