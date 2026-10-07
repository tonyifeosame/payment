<?php

namespace Tests\Feature;

use App\Mail\SchoolIdentityChangedMail;
use App\Mail\SchoolPasswordResetMail;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * M1: a stolen session could change the school's email (the password-reset
 * identifier) or name (the login identifier) with no password, then reset the
 * password from its own inbox and own the account. Both changes now need the
 * current password, the old address is told, and a reset link already sent to
 * the old address dies with the change.
 */
class SchoolIdentityChangeTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Mail::fake();
        $this->school = $this->makeSchool('Alpha School', 'alpha', ['email' => 'alpha@example.test']);
    }

    private function save(array $fields)
    {
        return $this->actingAsSchoolAdmin($this->school)->put('/admin/alpha/settings', array_merge([
            'name' => 'Alpha School',
            'email' => 'alpha@example.test',
        ], $fields));
    }

    public function test_changing_the_email_without_the_password_is_refused(): void
    {
        $this->save(['email' => 'attacker@example.test'])->assertSessionHasErrors('identity_password');

        $this->assertSame('alpha@example.test', $this->school->fresh()->email);
        Mail::assertNothingSent();
    }

    public function test_changing_the_name_without_the_password_is_refused(): void
    {
        $this->save(['name' => 'Taken Over'])->assertSessionHasErrors('identity_password');

        $this->assertSame('Alpha School', $this->school->fresh()->name);
    }

    public function test_a_wrong_password_is_refused_and_counted(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->save(['email' => 'attacker@example.test', 'identity_password' => 'wrong-'.$i])
                ->assertSessionHasErrors('identity_password');
        }

        // The sixth attempt is throttled even with the right password.
        $this->save(['email' => 'attacker@example.test', 'identity_password' => 'password123'])->assertStatus(429);
        $this->assertSame('alpha@example.test', $this->school->fresh()->email);
    }

    public function test_the_right_password_changes_the_email_and_notifies_the_old_address(): void
    {
        $this->save(['email' => 'office@example.test', 'identity_password' => 'password123'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('office@example.test', $this->school->fresh()->email);

        Mail::assertSent(SchoolIdentityChangedMail::class, function (SchoolIdentityChangedMail $mail) {
            return $mail->hasTo('alpha@example.test') && ! $mail->hasTo('office@example.test')
                && $mail->previous['email'] === 'alpha@example.test';
        });

        $html = (new SchoolIdentityChangedMail($this->school->fresh(), ['name' => 'Alpha School', 'email' => 'alpha@example.test']))->render();
        $this->assertStringContainsString('office@example.test', $html);
        $this->assertStringNotContainsString('password123', $html);
    }

    public function test_a_name_change_notifies_the_current_address(): void
    {
        $this->save(['name' => 'Alpha Academy', 'identity_password' => 'password123'])->assertSessionHasNoErrors();

        $this->assertSame('Alpha Academy', $this->school->fresh()->name);
        Mail::assertSent(SchoolIdentityChangedMail::class, fn ($m) => $m->hasTo('alpha@example.test'));
    }

    public function test_a_reset_link_sent_to_the_old_address_stops_working(): void
    {
        $this->post('/admin/forgot-password', ['email' => 'alpha@example.test']);
        $link = null;
        Mail::assertSent(SchoolPasswordResetMail::class, function ($m) use (&$link) {
            $link = $m->resetLink;

            return true;
        });
        $token = basename((string) parse_url($link, PHP_URL_PATH));

        $this->save(['email' => 'office@example.test', 'identity_password' => 'password123'])->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'alpha@example.test')->count());

        foreach (['alpha@example.test', 'office@example.test'] as $email) {
            $this->post('/admin/reset-password', [
                'token' => $token, 'email' => $email,
                'password' => 'a-brand-new-passphrase', 'password_confirmation' => 'a-brand-new-passphrase',
            ])->assertSessionHasErrors('email');
        }
    }

    public function test_ordinary_profile_fields_need_no_password(): void
    {
        $this->save(['phone' => '0801 234 5678', 'address' => '1 School Road', 'receipt_footer' => 'Thank you'])
            ->assertSessionHasNoErrors();

        $school = $this->school->fresh();
        $this->assertSame('0801 234 5678', $school->phone);
        $this->assertSame('Thank you', $school->receipt_footer);
        Mail::assertNotSent(SchoolIdentityChangedMail::class);
    }

    public function test_a_change_of_letter_case_only_needs_no_password(): void
    {
        $this->save(['name' => 'ALPHA SCHOOL', 'email' => 'Alpha@Example.test'])->assertSessionHasNoErrors();

        $this->assertSame('ALPHA SCHOOL', $this->school->fresh()->name);
        Mail::assertNotSent(SchoolIdentityChangedMail::class);
    }

    public function test_the_form_offers_the_password_field(): void
    {
        $this->actingAsSchoolAdmin($this->school)->get('/admin/alpha/settings')
            ->assertOk()
            ->assertSee('name="identity_password"', false);
    }
}
