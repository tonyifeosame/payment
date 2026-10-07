<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolRememberToken;
use App\Models\Transaction;
use App\Support\SchoolRemember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * L5: a rotated "remember me" token presented again means the cookie was copied;
 * every remembered browser of the school is then revoked. Receipt links stay
 * permanent (they are emailed proofs of payment) but are never cached or indexed.
 */
class PersistentSessionHardeningTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->school = $this->makeSchool('Alpha School', 'alpha');
    }

    /** Sign in with "Remember me" and return the issued cookie value. */
    private function rememberedCookie(): string
    {
        $response = $this->post('/admin/login', ['name' => 'Alpha School', 'password' => 'password123', 'remember' => '1']);
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === SchoolRemember::cookieName());
        $this->assertNotNull($cookie);
        $this->flushSession();

        return $cookie->getValue();
    }

    private function visitWith(string $cookieValue)
    {
        $this->flushSession();

        return $this->withUnencryptedCookie(SchoolRemember::cookieName(), $cookieValue)->get('/admin/alpha/dashboard');
    }

    public function test_a_rotated_token_replayed_after_the_grace_window_revokes_every_browser(): void
    {
        $stolen = $this->rememberedCookie();

        // The real browser uses it first: signed in, token rotated.
        $this->visitWith($stolen)->assertOk();
        // Another device is also remembered.
        $this->flushSession();
        $other = $this->rememberedCookie();

        $this->travel(SchoolRemember::REUSE_GRACE_SECONDS + 5)->seconds();

        // The copy is presented: refused, and every remembered browser revoked.
        $this->visitWith($stolen)->assertRedirect('/admin/login');
        $this->assertSame(0, SchoolRememberToken::where('school_id', $this->school->id)->whereNull('revoked_at')->count());
        $this->visitWith($other)->assertRedirect('/admin/login');
    }

    public function test_a_replay_inside_the_grace_window_is_only_refused(): void
    {
        $cookie = $this->rememberedCookie();
        $this->visitWith($cookie)->assertOk(); // rotation

        $this->visitWith($cookie)->assertRedirect('/admin/login'); // same moment: a racing tab

        $this->assertSame(1, SchoolRememberToken::where('school_id', $this->school->id)->whereNull('revoked_at')->count(),
            'a racing tab must not sign the school out everywhere');
    }

    public function test_password_change_and_reset_still_revoke_every_remembered_browser(): void
    {
        $cookie = $this->rememberedCookie();

        $this->withSession(\App\Support\SchoolSession::payloadFor($this->school))
            ->put('/admin/alpha/settings/password', ['current_password' => 'password123', 'password' => 'a-new-passphrase-77', 'password_confirmation' => 'a-new-passphrase-77'])
            ->assertSessionHasNoErrors();

        $this->visitWith($cookie)->assertRedirect('/admin/login');
        $this->assertSame(0, SchoolRememberToken::where('school_id', $this->school->id)->whereNull('revoked_at')->count());
    }

    public function test_receipts_are_permanent_but_never_cached_or_indexed(): void
    {
        $t = Transaction::create([
            'school_id' => $this->school->id, 'reference' => 'ref-r', 'amount' => 102.5, 'status' => 'success', 'paid_at' => now(),
            'email' => 'p@example.test', 'meta_data' => ['quantity' => 1, 'base_amount' => 100, 'markup_amount' => 2.5, 'gross_amount' => 102.5],
        ]);

        $page = URL::signedRoute('payment.receipt', ['transaction' => $t->id]);
        $pdf = URL::signedRoute('payment.receipt.download', ['transaction' => $t->id]);

        $this->travel(400)->days(); // a link from an old email still opens
        foreach ([$this->get($page), $this->get($pdf)] as $response) {
            $response->assertOk();
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
            $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        }

        // And the bare id is still not enough.
        $this->flushSession();
        $this->get('/payment/receipt/'.$t->id)->assertNotFound();
    }
}
