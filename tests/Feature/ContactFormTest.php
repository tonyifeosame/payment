<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /contact is unauthenticated and it sends mail, so it needs a rate limit.
 *
 * The recipient is fixed to config('mail.from.address') and cannot be chosen by the
 * sender, so this is not an open relay — the exposure is flooding our own inbox and
 * burning the sending domain's reputation. Registration was already throttled;
 * this route was not, which is what these tests pin.
 *
 * Mail::fake() is deliberately avoided, for the reason ReceiptMailRenderingTest
 * documents: a fake intercepts before anything is rendered or addressed, so it
 * cannot show who the message was actually sent to. The array transport keeps the
 * real Symfony message, headers and all.
 */
class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    private const INBOX = 'noreply@school.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'array',
            'mail.from.address' => self::INBOX,
            'mail.from.name' => 'School Fees Portal',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Parent',
            'email' => 'parent@example.test',
            'subject' => 'Question about fees',
            'message' => 'Could you clarify the Primary term fee?',
        ], $overrides);
    }

    /**
     * The array transport stores SentMessage wrappers; unwrap to the Email so the
     * addressing headers can be inspected.
     *
     * @return array<int, \Symfony\Component\Mime\Email>
     */
    private function sentMessages(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->all();
    }

    public function test_a_legitimate_message_is_accepted_and_delivered(): void
    {
        $this->post('/contact', $this->payload())
            ->assertRedirect(route('contact.show'))
            ->assertSessionHas('success');

        $this->assertCount(1, $this->sentMessages());
    }

    public function test_excessive_messages_are_throttled(): void
    {
        // Five an hour per IP is the configured allowance.
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->post('/contact', $this->payload([
                'subject' => "Question {$i}",
            ]));

            $this->assertNotEquals(429, $response->status(), "request {$i} was throttled too early");
        }

        $this->post('/contact', $this->payload(['subject' => 'Question 6']))
            ->assertStatus(429);

        // The sixth message must not have been sent, not merely reported as blocked.
        $this->assertCount(5, $this->sentMessages(), 'a throttled request still sent mail');
    }

    public function test_the_throttle_does_not_apply_to_the_contact_page_itself(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->get('/contact')->assertOk();
        }
    }

    public function test_validation_still_rejects_a_malformed_message(): void
    {
        $this->post('/contact', $this->payload(['email' => 'not-an-email']))
            ->assertSessionHasErrors('email');

        $this->post('/contact', $this->payload(['message' => '']))
            ->assertSessionHasErrors('message');

        $this->assertCount(0, $this->sentMessages());
    }

    public function test_the_sender_cannot_choose_the_recipient(): void
    {
        $this->post('/contact', $this->payload([
            'to' => 'attacker@evil.test',
            'recipient' => 'attacker@evil.test',
            'email' => 'attacker@evil.test',
        ]))->assertRedirect(route('contact.show'));

        $message = $this->sentMessages()[0];
        $recipients = array_map(
            fn ($address) => $address->getAddress(),
            $message->getTo()
        );

        $this->assertSame([self::INBOX], $recipients, 'the contact form addressed mail somewhere it chose');

        // The submitted address is still usable as a reply-to, which is the point of
        // the form — it just never becomes the envelope recipient.
        $replyTo = array_map(fn ($address) => $address->getAddress(), $message->getReplyTo());
        $this->assertSame(['attacker@evil.test'], $replyTo);
    }
}
