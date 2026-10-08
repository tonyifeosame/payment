<?php

namespace Tests\Feature;

use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\TestCase;

/**
 * The SMTP mailer carries an explicit timeout.
 *
 * Registration links, password resets, the change notices and the contact form
 * are sent inline from a web request, and the web service runs one Octane
 * worker. With no timeout, PHP's default_socket_timeout (60s) applies, so an
 * SMTP server that stops answering would hold every request — the Paystack
 * webhook included — for a minute per send.
 */
class MailTimeoutTest extends TestCase
{
    public function test_the_smtp_mailer_has_a_short_timeout(): void
    {
        $timeout = config('mail.mailers.smtp.timeout');

        $this->assertIsFloat($timeout);
        $this->assertGreaterThan(0, $timeout);
        $this->assertLessThanOrEqual(30, $timeout, 'long enough to hold the single web worker');
    }

    public function test_the_timeout_reaches_the_smtp_socket(): void
    {
        // Building the transport opens no connection, so no server is needed.
        config([
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.timeout' => 7.0,
        ]);

        $stream = app('mail.manager')->mailer('smtp')->getSymfonyTransport()->getStream();

        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame(7.0, $stream->getTimeout());
    }

    public function test_the_example_environment_ships_the_default(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^MAIL_TIMEOUT=10$/m', $example);
        $this->assertMatchesRegularExpression('/^CONTACT_EMAIL=$/m', $example);
    }
}
