<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Laravel's config is cached at container START, from Render's environment,
 * never at image build.
 *
 * Render's dockerCommand replaces the image's ENTRYPOINT, so the Render
 * services used to skip docker-entrypoint.sh entirely and Laravel read the
 * config cache baked in at build time: APP_KEY null (MissingAppKeyException),
 * APP_URL http://localhost, sqlite, the log mailer. These tests pin the three
 * halves of the fix: the image carries no config cache, every Render service
 * starts through the entrypoint, and the entrypoint caches config with the
 * runtime environment before it hands over to the command.
 *
 * The entrypoint runs for real under `sh`, with a stub `php` first on PATH that
 * records each call and the environment it saw.
 */
class DockerRuntimeStartupTest extends TestCase
{
    private const KEY = 'base64:cnVudGltZS1rZXktcnVudGltZS1rZXktcnVudGltZTE=';

    private string $root;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = str_replace('\\', '/', dirname(__DIR__, 2));
        $this->dir = str_replace('\\', '/', sys_get_temp_dir()).'/feyra-entrypoint-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $this->removeTree($this->dir);
        }

        parent::tearDown();
    }

    public function test_the_image_build_never_caches_config_routes_or_events(): void
    {
        $runs = $this->dockerfileRunInstructions();

        foreach ($runs as $run) {
            $this->assertDoesNotMatchRegularExpression('/artisan\s+(optimize|config:cache|route:cache|event:cache)\b/', $run);
        }
        $this->assertStringContainsString(
            'rm -f /app/bootstrap/cache/config.php /app/bootstrap/cache/routes-v7.php /app/bootstrap/cache/events.php',
            implode("\n", $runs),
        );
        $this->assertContains('bootstrap/cache/*.php', array_map('trim', file($this->root.'/.dockerignore')), 'no local cache enters the build context');
    }

    public function test_every_render_service_starts_through_the_entrypoint(): void
    {
        $dockerfile = (string) file_get_contents($this->root.'/docker/Dockerfile');
        $this->assertMatchesRegularExpression('#^COPY docker/docker-entrypoint.sh /docker-entrypoint.sh$#m', $dockerfile);
        $this->assertMatchesRegularExpression('#^ENTRYPOINT \["/docker-entrypoint.sh"\]$#m', $dockerfile);

        $services = Yaml::parseFile($this->root.'/render.yaml')['services'];
        $this->assertCount(3, $services);
        foreach ($services as $service) {
            $this->assertStringStartsWith('/docker-entrypoint.sh ', $service['dockerCommand'], $service['name']);
            // Render splits dockerCommand on whitespace and keeps quote
            // characters, so a quoted argument never arrives as intended.
            $this->assertFalse(strpbrk($service['dockerCommand'], '\'"\\'), $service['name'].' has a quote or backslash');
        }
    }

    /** @return array<string, array{string, bool}> service name, migrates */
    public static function renderServices(): array
    {
        return [
            'web' => ['laravel-app', true],
            'queue worker' => ['laravel-queue-worker', false],
            'payout reconciliation cron' => ['laravel-payout-reconciliation', false],
        ];
    }

    #[DataProvider('renderServices')]
    public function test_each_service_caches_config_from_its_runtime_environment_then_runs_its_command(string $name, bool $migrates): void
    {
        $service = collect(Yaml::parseFile($this->root.'/render.yaml')['services'])->firstWhere('name', $name);
        // Literal values as Render passes them: strings, a YAML true as "true".
        $env = collect($service['envVars'])->filter(fn ($v) => isset($v['value']))
            ->mapWithKeys(fn ($v) => [$v['key'] => is_bool($v['value']) ? var_export($v['value'], true) : (string) $v['value']])
            ->all();

        // The values Render supplies from the dashboard (sync: false).
        $env += ['APP_KEY' => self::KEY, 'APP_URL' => 'https://feyra.site', 'APP_NAME' => 'FEYRA Payments'];

        [$exit, $calls] = $this->runEntrypoint($service['dockerCommand'], $env);

        $this->assertSame(0, $exit);
        $prep = array_values(array_filter($calls, fn ($c) => $c['prepared'] === ''));
        $command = array_values(array_filter($calls, fn ($c) => $c['prepared'] === '1'));

        $expected = ['artisan config:clear', 'artisan config:cache'];
        if ($migrates) {
            $expected[] = 'artisan migrate --force';
        }
        $expected[] = 'artisan optimize';
        $this->assertSame($expected, array_column($prep, 'args'), 'runtime preparation, in order');

        // config:cache ran with Render's values present, so the cache holds them.
        $cache = $prep[1];
        $this->assertSame([self::KEY, 'https://feyra.site', 'pgsql'], [$cache['APP_KEY'], $cache['APP_URL'], $cache['DB_CONNECTION']]);

        // Then the service's own command, unchanged.
        $this->assertNotEmpty($command);
        $first = match ($name) {
            'laravel-app' => 'artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000 --workers=1',
            'laravel-queue-worker' => 'artisan queue:work database --queue=default --sleep=3 --tries=3 --max-time=3600 --backoff=30',
            'laravel-payout-reconciliation' => 'artisan payouts:run --dispatch',
        };
        $this->assertSame($first, $command[0]['args']);
        if ($name === 'laravel-payout-reconciliation') {
            $this->assertSame(['artisan payouts:run --dispatch', 'artisan payments:expire-pending', 'artisan jobs:check'], array_column($command, 'args'));
        }
    }

    public function test_a_production_container_without_app_key_stops_before_caching_anything(): void
    {
        [$exit, $calls, $stderr] = $this->runEntrypoint('/docker-entrypoint.sh php artisan octane:start', [
            'APP_ENV' => 'production', 'APP_KEY' => false, 'APP_URL' => 'https://feyra.site',
        ]);

        $this->assertNotSame(0, $exit);
        $this->assertSame([], $calls, 'no config was cached and the command never ran');
        $this->assertStringContainsString('APP_KEY is not set', $stderr);
    }

    public function test_a_production_container_without_an_https_app_url_stops_before_caching_anything(): void
    {
        foreach ([false, 'http://localhost', 'http://feyra.site', 'https://'] as $url) {
            [$exit, $calls, $stderr] = $this->runEntrypoint('/docker-entrypoint.sh php artisan queue:work', [
                'APP_ENV' => 'production', 'APP_KEY' => self::KEY, 'APP_URL' => $url,
            ]);

            $this->assertNotSame(0, $exit, var_export($url, true));
            $this->assertSame([], $calls, var_export($url, true));
            $this->assertStringContainsString('APP_URL must be the public https:// address', $stderr);
        }
    }

    public function test_a_local_non_production_run_needs_neither(): void
    {
        [$exit, $calls] = $this->runEntrypoint('/docker-entrypoint.sh php artisan about', [
            'APP_ENV' => false, 'APP_KEY' => false, 'APP_URL' => false, 'SKIP_MIGRATIONS' => false,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('artisan about', end($calls)['args']);
    }

    public function test_preparation_runs_once_when_the_platform_also_runs_the_entrypoint(): void
    {
        // ENTRYPOINT + a dockerCommand that names the entrypoint again.
        [$exit, $calls] = $this->runEntrypoint('/docker-entrypoint.sh /docker-entrypoint.sh php artisan queue:work', [
            'APP_ENV' => 'production', 'APP_KEY' => self::KEY, 'APP_URL' => 'https://feyra.site', 'SKIP_MIGRATIONS' => 'true',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(
            ['artisan config:clear', 'artisan config:cache', 'artisan optimize', 'artisan queue:work'],
            array_column($calls, 'args'),
        );
    }

    public function test_config_cache_built_at_runtime_holds_the_runtime_environment(): void
    {
        // The real artisan, as the entrypoint runs it: whatever the environment
        // holds when config:cache runs is what the cached config serves.
        $cache = 'storage/framework/testing/runtime-config-'.bin2hex(random_bytes(6)).'.php';
        if (! is_dir($this->root.'/storage/framework/testing')) {
            mkdir($this->root.'/storage/framework/testing', 0777, true);
        }

        try {
            (new Process([PHP_BINARY, 'artisan', 'config:cache'], $this->root, [
                'APP_CONFIG_CACHE' => $cache,
                'APP_ENV' => 'production',
                'APP_KEY' => self::KEY,
                'APP_URL' => 'https://feyra.site',
                'DB_CONNECTION' => 'pgsql',
                'MAIL_MAILER' => 'smtp',
            ]))->mustRun();

            $config = require $this->root.'/'.$cache;

            $this->assertSame(self::KEY, $config['app']['key']);
            $this->assertSame('https://feyra.site', $config['app']['url']);
            $this->assertSame('production', $config['app']['env']);
            $this->assertSame('pgsql', $config['database']['default']);
            $this->assertSame('smtp', $config['mail']['default']);
        } finally {
            if (is_file($this->root.'/'.$cache)) {
                unlink($this->root.'/'.$cache);
            }
        }
    }

    /**
     * Run a Render dockerCommand with the real entrypoint (and the real
     * .env.example and write-dotenv.sh) in a scratch app root.
     *
     * @param  array<string, string|false>  $env  false removes the variable
     * @return array{int, list<array{args: string, APP_KEY: string, APP_URL: string, DB_CONNECTION: string, prepared: string}>, string}
     */
    private function runEntrypoint(string $dockerCommand, array $env): array
    {
        $sh = (new ExecutableFinder)->find('sh');
        if ($sh === null) {
            $this->markTestSkipped('No POSIX sh available.');
        }

        if (is_dir($this->dir)) {
            $this->removeTree($this->dir);
        }
        mkdir($this->dir.'/app/docker', 0777, true);
        mkdir($this->dir.'/bin', 0777, true);
        copy($this->root.'/.env.example', $this->dir.'/app/.env.example');
        copy($this->root.'/docker/write-dotenv.sh', $this->dir.'/app/docker/write-dotenv.sh');
        copy($this->root.'/docker/payout-reconciliation.sh', $this->dir.'/app/docker/payout-reconciliation.sh');

        // The stub records "args|APP_KEY|APP_URL|DB_CONNECTION|FEYRA_RUNTIME_PREPARED".
        file_put_contents($this->dir.'/bin/php', "#!/bin/sh\n"
            ."printf '%s|%s|%s|%s|%s\\n' \"\$*\" \"\${APP_KEY:-}\" \"\${APP_URL:-}\" \"\${DB_CONNECTION:-}\" \"\${FEYRA_RUNTIME_PREPARED:-}\" >> \"\$FAKE_PHP_LOG\"\n"
            ."exit 0\n");
        chmod($this->dir.'/bin/php', 0755);
        touch($this->dir.'/calls.log');

        // As Render runs it: split on whitespace with no shell parsing (quotes
        // stay literal), the first word executed by path. Image paths are
        // swapped for the repository's entrypoint and the scratch app root.
        $argv = array_map(fn (string $word) => match (true) {
            $word === '/docker-entrypoint.sh' => $this->root.'/docker/docker-entrypoint.sh',
            str_starts_with($word, '/app/') => $this->dir.'/app/'.substr($word, strlen('/app/')),
            default => $word,
        }, preg_split('/\s+/', trim($dockerCommand)));

        $process = new Process([$sh, '-c', 'exec "$@"', 'sh', ...$argv], $this->dir, $env + [
            'PATH' => $this->dir.'/bin'.PATH_SEPARATOR.getenv('PATH'),
            'FEYRA_APP_ROOT' => $this->dir.'/app',
            'FAKE_PHP_LOG' => $this->dir.'/calls.log',
            'FEYRA_RUNTIME_PREPARED' => false,
        ]);
        $process->run();

        $calls = [];
        foreach (file($this->dir.'/calls.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            [$args, $key, $url, $db, $prepared] = explode('|', $line);
            $calls[] = ['args' => $args, 'APP_KEY' => $key, 'APP_URL' => $url, 'DB_CONNECTION' => $db, 'prepared' => $prepared];
        }

        return [$process->getExitCode(), $calls, $process->getErrorOutput()];
    }

    /** @return list<string> every RUN instruction, continuation lines joined */
    private function dockerfileRunInstructions(): array
    {
        $dockerfile = (string) file_get_contents($this->root.'/docker/Dockerfile');
        $joined = preg_replace('/\\\\\r?\n/', ' ', $dockerfile);
        preg_match_all('/^RUN\s+(.+)$/m', $joined, $m);

        return $m[1];
    }

    private function removeTree(string $path): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
