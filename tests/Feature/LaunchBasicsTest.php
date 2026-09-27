<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Launch-checklist basics: icons and admin noindex. The icons are static files
 * served by the web server, so they are checked on disk.
 */
class LaunchBasicsTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    public function test_favicon_ico_is_a_real_icon_with_the_standard_sizes(): void
    {
        $ico = file_get_contents(public_path('favicon.ico'));
        $header = unpack('vreserved/vtype/vcount', $ico);

        $this->assertSame(0, $header['reserved']);
        $this->assertSame(1, $header['type']); // 1 = icon
        $sizes = [];
        for ($i = 0; $i < $header['count']; $i++) {
            $entry = unpack('Cwidth/Cheight/x2/x2/x2/Vsize/Voffset', substr($ico, 6 + 16 * $i, 16));
            $image = imagecreatefromstring(substr($ico, $entry['offset'], $entry['size']));
            $this->assertNotFalse($image);
            $this->assertSame([$entry['width'], $entry['width']], [imagesx($image), imagesy($image)]);
            $sizes[] = $entry['width'];
        }
        $this->assertSame([16, 32, 48], $sizes);
    }

    public function test_apple_touch_icon_is_opaque_180px_and_linked_from_the_marketing_layout(): void
    {
        [$width, $height, $type] = getimagesize(public_path('apple-touch-icon.png'));
        $this->assertSame([180, 180, IMAGETYPE_PNG], [$width, $height, $type]);

        // iOS renders transparent pixels black, so the corners must be filled.
        $image = imagecreatefrompng(public_path('apple-touch-icon.png'));
        $corner = imagecolorsforindex($image, imagecolorat($image, 0, 0));
        $this->assertSame(0, $corner['alpha']);

        $this->get('/')->assertSee('<link rel="apple-touch-icon" href="'.asset('apple-touch-icon.png').'">', false);
    }

    public function test_admin_pages_are_noindex_and_marketing_pages_are_not(): void
    {
        $school = $this->makeSchool('Alpha School', 'alpha');

        $this->actingAsSchoolAdmin($school)->get('/admin/alpha/dashboard')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex">', false);

        $this->get('/')->assertDontSee('noindex', false);
    }

    public function test_dead_payment_success_and_failed_routes_are_gone(): void
    {
        $this->assertFalse(Route::has('payment.success'));
        $this->assertFalse(Route::has('payment.failed'));

        $this->get('/payment/success')->assertNotFound();
        $this->get('/payment/failed')->assertNotFound();
    }
}
