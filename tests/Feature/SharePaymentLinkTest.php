<?php

namespace Tests\Feature;

use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Phase 1 — sharing the public payment page: link, WhatsApp, QR.
 */
class SharePaymentLinkTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeSchool('Alpha School', 'alpha', ['paystack_recipient_code' => 'RCP_secret']);
        $this->makeStudent($this->alpha, 'A/2026/001', 'Adaeze Okonkwo');
    }

    public function test_share_page_shows_link_whatsapp_and_qr_for_the_owning_admin_only(): void
    {
        $this->get('/admin/alpha/share')->assertRedirect('/admin/login');

        $beta = $this->makeSchool('Beta School', 'beta');
        $this->actingAsSchoolAdmin($beta)->get('/admin/alpha/share')->assertNotFound();
        $this->flushSession();

        $page = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/share')->assertOk();

        $page->assertSee('http://localhost/pay/alpha');
        $page->assertSee('https://wa.me/?text=', false);
        $page->assertSee(rawurlencode('http://localhost/pay/alpha'), false);
        $page->assertSee('<svg', false);
        $page->assertSee('/admin/alpha/share/qr.svg', false);

        // Nothing sensitive leaks onto the share page.
        $page->assertDontSee('RCP_secret')->assertDontSee('0123456789')->assertDontSee('A/2026/001');
    }

    public function test_qr_svg_encodes_only_the_public_payment_url(): void
    {
        $response = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/share/qr.svg');

        $response->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $svg = $response->getContent();
        $this->assertStringStartsWith('<?xml', $svg);
        $this->assertStringContainsString('<svg', $svg);

        // Decode-equivalent check: regenerate a QR for the same URL with the same
        // renderer and confirm the modules are identical, and that a different
        // payload would not be.
        $render = function (string $text) {
            $renderer = new \BaconQrCode\Renderer\ImageRenderer(new \BaconQrCode\Renderer\RendererStyle\RendererStyle(600, 2), new \BaconQrCode\Renderer\Image\SvgImageBackEnd);

            return (new \BaconQrCode\Writer($renderer))->writeString($text);
        };
        $this->assertSame($render('http://localhost/pay/alpha'), $svg);
        $this->assertNotSame($render('http://localhost/pay/alpha?x=1'), $svg);

        foreach (['RCP_secret', '0123456789', 'A/2026/001', (string) $this->alpha->id.'"'] as $needle) {
            $this->assertStringNotContainsString($needle, $svg);
        }

        $this->flushSession();
        $this->get('/admin/alpha/share/qr.svg')->assertRedirect('/admin/login');
    }

    public function test_the_shared_link_opens_the_public_payment_page_without_login(): void
    {
        // A payable fee, so the checkout form renders: with nothing payable the
        // page shows its empty state instead (L6), and the form fields this
        // asserts on would legitimately be absent.
        $this->makeFee($this->alpha, 'Uniform', 'Shirt', 3000);

        $this->get($this->alpha->paymentUrl())->assertOk()->assertSee('Alpha School')->assertSee('admission_number', false);
    }
}
