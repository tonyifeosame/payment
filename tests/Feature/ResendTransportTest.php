<?php

namespace Tests\Feature;

use Illuminate\Mail\Transport\ResendTransport;
use Tests\TestCase;

/**
 * Production sends mail with MAIL_MAILER=resend. Laravel's `resend` transport
 * calls Resend::client() from Resend's official PHP SDK, resend/resend-php;
 * while that package was missing every send failed with `Class "Resend" not
 * found`. Building the transport makes no request, so nothing here reaches
 * Resend's API.
 */
class ResendTransportTest extends TestCase
{
    public function test_the_resend_sdk_is_installed_and_loads(): void
    {
        $this->assertTrue(class_exists(\Resend::class), 'resend/resend-php must be installed');
        $this->assertTrue(class_exists(\Resend\Client::class));
    }

    public function test_the_resend_mailer_builds_its_transport_with_the_resend_key(): void
    {
        $this->assertSame('resend', config('mail.mailers.resend.transport'));

        config(['services.resend.key' => 're_test_not_a_real_key']);

        $transport = app('mail.manager')->mailer('resend')->getSymfonyTransport();

        $this->assertInstanceOf(ResendTransport::class, $transport);
        $this->assertSame('resend', (string) $transport);
    }
}
