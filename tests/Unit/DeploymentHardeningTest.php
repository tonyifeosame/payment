<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * L6 (image hygiene), L7 (production log level) and the CI gates (C1 secret
 * scan, H2 dependency audit), checked on the committed files so a regression
 * shows up in the suite rather than in production.
 */
class DeploymentHardeningTest extends TestCase
{
    private function file(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }

    public function test_the_image_has_no_world_writable_paths_extra_ports_or_floating_base(): void
    {
        $dockerfile = $this->file('docker/Dockerfile');

        $this->assertStringNotContainsString('777', $dockerfile);
        $this->assertDoesNotMatchRegularExpression('/^FROM\s+dunglas\/frankenphp\s*$/m', $dockerfile, 'base image must be pinned');
        $this->assertMatchesRegularExpression('/^FROM\s+dunglas\/frankenphp:\S+/m', $dockerfile);
        $this->assertStringNotContainsString('releases/latest', $dockerfile);
        $this->assertStringNotContainsString('composer:latest', $dockerfile);
        $this->assertDoesNotMatchRegularExpression('/^EXPOSE\s+(443|2019)\b/m', $dockerfile);
        $this->assertMatchesRegularExpression('/^USER\s+\$\{USER\}/m', $dockerfile, 'the container must not run as root');
    }

    public function test_secrets_and_local_state_never_enter_the_build_context(): void
    {
        $ignore = array_map('trim', file(dirname(__DIR__, 2).'/.dockerignore'));

        foreach (['.env', '.env.*', '.git', 'storage/logs/*', 'database/*.sqlite', 'storage/framework/sessions/*', 'tests', '.phpunit.result.cache', 'auth.json'] as $entry) {
            $this->assertContains($entry, $ignore, ".dockerignore must exclude {$entry}");
        }
        $this->assertContains('!.env.example', $ignore, 'the entrypoint builds .env from .env.example');
    }

    public function test_php_limits_fit_the_instance(): void
    {
        $ini = parse_ini_string($this->file('docker/php.ini'));

        $this->assertSame('256M', $ini['memory_limit']);
        $this->assertSame('8M', $ini['post_max_size']);
        $this->assertSame('6M', $ini['upload_max_filesize']);
        $this->assertSame('', $ini['expose_php']); // Off
    }

    public function test_every_render_service_logs_at_info(): void
    {
        $render = Yaml::parse($this->file('render.yaml'));

        foreach ($render['services'] as $service) {
            $env = array_column($service['envVars'], 'value', 'key');
            $this->assertSame('info', $env['LOG_LEVEL'] ?? null, $service['name'].' must set LOG_LEVEL=info');
            $this->assertSame('false', var_export($env['APP_DEBUG'] ?? null, true), $service['name'].' must keep APP_DEBUG=false');
        }
    }

    public function test_ci_keeps_the_secret_scan_and_the_dependency_audit(): void
    {
        $ci = Yaml::parse($this->file('.github/workflows/ci.yml'));

        $secretSteps = implode("\n", array_map(fn ($s) => $s['run'] ?? '', $ci['jobs']['secrets']['steps']));
        $this->assertStringContainsString('gitleaks git . --config .gitleaks.toml --redact', $secretSteps);
        $this->assertStringContainsString('sha256sum --check', $secretSteps);

        $auditSteps = implode("\n", array_map(fn ($s) => $s['run'] ?? '', $ci['jobs']['dependencies']['steps']));
        $this->assertStringContainsString('composer audit --locked', $auditSteps);
    }
}
