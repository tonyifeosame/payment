<?php

namespace Tests\Unit;

use App\Support\SchoolLogoImage;
use GdImage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\BuildsLogoFixtures;

/**
 * The logo normalisation rules: size, aspect ratio, format, transparency, palette
 * PNGs, metadata, JPEG orientation, and refusal from the header before decoding.
 */
class SchoolLogoImageTest extends TestCase
{
    use BuildsLogoFixtures;

    // -----------------------------------------------------------------------
    // Resizing
    // -----------------------------------------------------------------------

    public function test_large_images_are_reduced_to_512_on_the_longest_side_keeping_aspect_ratio(): void
    {
        foreach ([[2000, 1200, 512, 307], [900, 1800, 256, 512], [1024, 1024, 512, 512]] as [$w, $h, $ew, $eh]) {
            foreach (['jpeg', 'png', 'webp'] as $format) {
                $out = SchoolLogoImage::normalize($this->encode($this->photo($w, $h), $format));

                $this->assertSame([$ew, $eh], $this->dimensions($out['bytes']), "{$format} {$w}x{$h}");
            }
        }
    }

    public function test_images_already_within_512_are_never_upscaled(): void
    {
        foreach (['jpeg', 'png', 'webp'] as $format) {
            $out = SchoolLogoImage::normalize($this->encode($this->photo(300, 120), $format));

            $this->assertSame([300, 120], $this->dimensions($out['bytes']), $format);
        }
    }

    public function test_the_output_is_deterministic(): void
    {
        foreach (['jpeg', 'png', 'webp'] as $format) {
            $upload = $this->encode($this->photo(900, 600), $format);

            $this->assertSame(SchoolLogoImage::normalize($upload), SchoolLogoImage::normalize($upload), $format);
        }
    }

    // -----------------------------------------------------------------------
    // Formats
    // -----------------------------------------------------------------------

    public function test_jpeg_stays_jpeg_and_is_smaller(): void
    {
        $upload = $this->encode($this->photo(1600, 1000), 'jpeg', 90);
        $out = SchoolLogoImage::normalize($upload);

        $this->assertSame('image/jpeg', $out['mime']);
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($out['bytes'])[2]);
        $this->assertLessThan(strlen($upload), strlen($out['bytes']));
    }

    public function test_png_stays_png_and_keeps_transparency_when_resized(): void
    {
        $out = SchoolLogoImage::normalize($this->encode($this->transparentLogo(1000, 800), 'png'));

        $this->assertSame('image/png', $out['mime']);
        $this->assertSame([512, 410], $this->dimensions($out['bytes']));
        $image = imagecreatefromstring($out['bytes']);
        $this->assertSame(127, imagecolorsforindex($image, imagecolorat($image, 2, 2))['alpha'], 'transparent corner stays transparent');
        $this->assertSame(0, imagecolorsforindex($image, imagecolorat($image, 256, 205))['alpha'], 'opaque centre stays opaque');
    }

    public function test_a_palette_png_within_512_stays_a_palette_png_and_does_not_grow(): void
    {
        $palette = $this->transparentLogo(400, 400);
        imagetruecolortopalette($palette, true, 256);
        $upload = $this->encode($palette, 'png');

        $out = SchoolLogoImage::normalize($upload);

        $this->assertSame('image/png', $out['mime']);
        $this->assertSame(3, ord($out['bytes'][25]), 'IHDR colour type 3: still a palette PNG');
        $this->assertLessThanOrEqual(strlen($upload), strlen($out['bytes']));
        $this->assertSame([400, 400], $this->dimensions($out['bytes']));
    }

    public function test_a_palette_png_that_must_be_resized_keeps_its_transparency(): void
    {
        $palette = $this->transparentLogo(1000, 1000);
        imagetruecolortopalette($palette, false, 64);
        imagecolortransparent($palette, imagecolorat($palette, 2, 2));

        $out = SchoolLogoImage::normalize($this->encode($palette, 'png'));

        $this->assertSame([512, 512], $this->dimensions($out['bytes']));
        $image = imagecreatefromstring($out['bytes']);
        $this->assertSame(127, imagecolorsforindex($image, imagecolorat($image, 2, 2))['alpha']);
    }

    public function test_webp_stays_lossy_webp_is_smaller_and_keeps_transparency(): void
    {
        $upload = $this->encode($this->photo(1600, 1000), 'webp', 80);
        $out = SchoolLogoImage::normalize($upload);

        $this->assertSame('image/webp', $out['mime']);
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($out['bytes'])[2]);
        $this->assertSame('VP8 ', substr($out['bytes'], 12, 4), 'lossy WebP');
        $this->assertLessThan(strlen($upload), strlen($out['bytes']));

        $transparent = SchoolLogoImage::normalize($this->encode($this->transparentLogo(1000, 800), 'webp'));
        $this->assertSame('image/webp', $transparent['mime']);
        $image = imagecreatefromstring($transparent['bytes']);
        $this->assertSame(127, imagecolorsforindex($image, imagecolorat($image, 2, 2))['alpha']);
    }

    // -----------------------------------------------------------------------
    // Metadata
    // -----------------------------------------------------------------------

    public function test_jpeg_exif_is_removed(): void
    {
        $upload = $this->withExif($this->encode($this->photo(800, 600), 'jpeg'), 1, 'MM');
        $this->assertStringContainsString("Exif\0\0", $upload);

        $this->assertStringNotContainsString("Exif\0\0", SchoolLogoImage::normalize($upload)['bytes']);
    }

    public function test_png_text_chunks_are_removed(): void
    {
        $png = $this->encode($this->photo(300, 200), 'png');
        $text = 'Author'."\0".'Private scanner location';
        $upload = substr($png, 0, 33).pack('N', strlen($text)).'tEXt'.$text.pack('N', crc32('tEXt'.$text)).substr($png, 33);
        $this->assertNotFalse(imagecreatefromstring($upload), 'the fixture is a valid PNG');

        $out = SchoolLogoImage::normalize($upload)['bytes'];

        $this->assertStringNotContainsString('tEXt', $out);
        $this->assertStringNotContainsString('Private scanner location', $out);
    }

    // -----------------------------------------------------------------------
    // JPEG orientation (expected results cross-checked against Chrome's own
    // EXIF-orientation rendering of the same files)
    // -----------------------------------------------------------------------

    /** @return array<string, array{int, string, array{int, int}, list<string>}> */
    public static function orientations(): array
    {
        $cases = [
            1 => [[120, 80], ['red', 'green', 'blue', 'yellow']],
            2 => [[120, 80], ['green', 'red', 'yellow', 'blue']],
            3 => [[120, 80], ['yellow', 'blue', 'green', 'red']],
            4 => [[120, 80], ['blue', 'yellow', 'red', 'green']],
            5 => [[80, 120], ['red', 'blue', 'green', 'yellow']],
            6 => [[80, 120], ['blue', 'red', 'yellow', 'green']],
            7 => [[80, 120], ['yellow', 'green', 'blue', 'red']],
            8 => [[80, 120], ['green', 'yellow', 'red', 'blue']],
        ];

        $data = [];
        foreach (['MM', 'II'] as $order) {
            foreach ($cases as $orientation => [$size, $quadrants]) {
                $data["{$orientation} {$order}"] = [$orientation, $order, $size, $quadrants];
            }
        }

        return $data;
    }

    #[DataProvider('orientations')]
    public function test_jpeg_orientation_is_applied_to_the_pixels(int $orientation, string $order, array $size, array $quadrants): void
    {
        $upload = $this->withExif($this->encode($this->quadrants(), 'jpeg', 95), $orientation, $order);

        $this->assertSame($orientation, SchoolLogoImage::jpegOrientation($upload));

        $out = SchoolLogoImage::normalize($upload)['bytes'];
        $this->assertSame($size, $this->dimensions($out));
        $this->assertSame($quadrants, $this->quadrantColours(imagecreatefromstring($out)));
    }

    public function test_malformed_exif_is_read_as_upright_and_never_errors(): void
    {
        $jpeg = $this->encode($this->quadrants(), 'jpeg', 95);
        $app1 = $this->exifSegment(6, 'MM');

        $cases = [
            'truncated segment' => substr($jpeg, 0, 2)."\xFF\xE1\x00\x40Exif\0\0MM".substr($jpeg, 2),
            'IFD offset out of range' => substr($jpeg, 0, 2).substr_replace($app1, pack('N', 99999), 14, 4).substr($jpeg, 2),
            'entry count past the end' => substr($jpeg, 0, 2).substr_replace(substr_replace($app1, pack('n', 65535), 18, 2), pack('n', 0x0110), 20, 2).substr($jpeg, 2),
            'orientation out of range' => substr($jpeg, 0, 2).substr_replace($app1, pack('n', 99), 28, 2).substr($jpeg, 2),
            'orientation not a SHORT' => substr($jpeg, 0, 2).substr_replace($app1, pack('n', 4), 22, 2).substr($jpeg, 2),
            'bad byte order' => substr($jpeg, 0, 2).substr_replace($app1, 'XX', 10, 2).substr($jpeg, 2),
        ];

        foreach ($cases as $name => $bytes) {
            $this->assertSame(1, SchoolLogoImage::jpegOrientation($bytes), $name);
            $this->assertSame([120, 80], $this->dimensions(SchoolLogoImage::normalize($bytes)['bytes']), $name);
        }

        foreach (['', 'not a jpeg', "\xFF\xD8", "\xFF\xD8\xFF\xE1\x00\x01"] as $garbage) {
            $this->assertSame(1, SchoolLogoImage::jpegOrientation($garbage));
        }
    }

    // -----------------------------------------------------------------------
    // Refused from the header, before decoding
    // -----------------------------------------------------------------------

    public function test_images_over_4000_pixels_on_either_side_are_refused(): void
    {
        foreach ([[4001, 10], [10, 4001]] as [$w, $h]) {
            $upload = $this->encode(imagecreatetruecolor($w, $h), 'png');

            $this->assertSame('The logo must be at most 4000 × 4000 pixels.', SchoolLogoImage::problem($upload));
            $this->assertThrows(fn () => SchoolLogoImage::normalize($upload), InvalidArgumentException::class, 'at most 4000 × 4000 pixels');
        }

        $this->assertNull(SchoolLogoImage::problem($this->encode(imagecreatetruecolor(4000, 10), 'png')));
    }

    public function test_a_decompression_bomb_is_refused_without_being_decoded(): void
    {
        // 10,000 × 10,000 pixels in about 100 KB: GD would need ~400 MB to decode it.
        $bomb = $this->bombPng(10000);
        $this->assertLessThan(1024 * 1024, strlen($bomb), 'small enough to pass the upload size limit');
        $this->assertSame([10000, 10000], array_slice(getimagesizefromstring($bomb), 0, 2));

        memory_reset_peak_usage();
        $before = memory_get_peak_usage();

        $this->assertThrows(fn () => SchoolLogoImage::normalize($bomb), InvalidArgumentException::class, 'at most 4000 × 4000 pixels');

        $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage() - $before, 'refused from the header: nothing was decoded');
    }

    public function test_an_animated_webp_is_refused_with_a_clear_message(): void
    {
        $animated = $this->animatedWebp();
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($animated)[2], 'the fixture is a WebP by its header');

        $this->assertSame('Animated images cannot be used as a logo. Please upload a still PNG, JPG or WebP image.', SchoolLogoImage::problem($animated));
        $this->assertThrows(fn () => SchoolLogoImage::normalize($animated), InvalidArgumentException::class, 'Animated images');
    }

    public function test_unsupported_and_unreadable_inputs_are_refused(): void
    {
        foreach (['gif', 'bmp'] as $format) {
            $this->assertSame('The logo must be a PNG, JPG or WebP image.', SchoolLogoImage::problem($this->encode($this->photo(40, 40), $format)), $format);
        }

        foreach (['', 'plain text', '<svg xmlns="http://www.w3.org/2000/svg"/>'] as $bytes) {
            $this->assertNotNull(SchoolLogoImage::problem($bytes));
            $this->assertThrows(fn () => SchoolLogoImage::normalize($bytes), InvalidArgumentException::class);
        }

        // A valid PNG header whose image data is corrupt: refused, not a crash.
        $png = $this->encode($this->photo(100, 100), 'png');
        $corrupt = substr($png, 0, 60).str_repeat("\0", 40).substr($png, 100);
        $this->assertThrows(fn () => SchoolLogoImage::normalize($corrupt), InvalidArgumentException::class);
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    /** A noisy, photo-like truecolor image. */
    private function photo(int $w, int $h): GdImage
    {
        $im = imagecreatetruecolor($w, $h);
        mt_srand(7);
        for ($y = 0; $y < $h; $y += 4) {
            for ($x = 0; $x < $w; $x += 4) {
                $c = imagecolorallocate($im, (intdiv($x * 255, $w) + mt_rand(0, 40)) % 256, (intdiv($y * 255, $h) + mt_rand(0, 40)) % 256, mt_rand(60, 200));
                imagefilledrectangle($im, $x, $y, $x + 3, $y + 3, $c);
            }
        }

        return $im;
    }

    /** A logo-like graphic on a transparent background, opaque in the middle. */
    private function transparentLogo(int $w, int $h): GdImage
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledellipse($im, intdiv($w, 2), intdiv($h, 2), intdiv($w * 3, 4), intdiv($h * 3, 4), imagecolorallocate($im, 14, 58, 120));

        return $im;
    }

    /** 120 × 80: red, green / blue, yellow. Asymmetric, so every orientation differs. */
    private function quadrants(): GdImage
    {
        $im = imagecreatetruecolor(120, 80);
        foreach ([[0, 0, [220, 30, 30]], [60, 0, [30, 170, 60]], [0, 40, [30, 60, 220]], [60, 40, [240, 210, 30]]] as [$x, $y, $rgb]) {
            imagefilledrectangle($im, $x, $y, $x + 59, $y + 39, imagecolorallocate($im, ...$rgb));
        }

        return $im;
    }

    /** @return list<string> the nearest named colour at the centre of each quadrant */
    private function quadrantColours(GdImage $im): array
    {
        $named = ['red' => [220, 30, 30], 'green' => [30, 170, 60], 'blue' => [30, 60, 220], 'yellow' => [240, 210, 30]];
        $w = imagesx($im);
        $h = imagesy($im);
        $result = [];

        foreach ([[0.25, 0.25], [0.75, 0.25], [0.25, 0.75], [0.75, 0.75]] as [$fx, $fy]) {
            $c = imagecolorsforindex($im, imagecolorat($im, (int) ($w * $fx), (int) ($h * $fy)));
            $distances = array_map(fn ($rgb) => ($c['red'] - $rgb[0]) ** 2 + ($c['green'] - $rgb[1]) ** 2 + ($c['blue'] - $rgb[2]) ** 2, $named);
            $result[] = array_search(min($distances), $distances, true);
        }

        return $result;
    }

    private function encode(GdImage $im, string $format, int $quality = 90): string
    {
        ob_start();
        match ($format) {
            'jpeg' => imagejpeg($im, null, $quality),
            'png' => (function () use ($im) {
                imagesavealpha($im, true);
                imagepng($im);
            })(),
            'webp' => imagewebp($im, null, $quality),
            'gif' => imagegif($im),
            'bmp' => imagebmp($im),
        };

        return (string) ob_get_clean();
    }

    /** @return array{int, int} */
    private function dimensions(string $bytes): array
    {
        return array_slice(getimagesizefromstring($bytes), 0, 2);
    }

    /** An APP1 Exif segment holding only an orientation tag. */
    private function exifSegment(int $orientation, string $order): string
    {
        [$u16, $u32] = $order === 'II' ? ['v', 'V'] : ['n', 'N'];
        $tiff = $order.pack($u16, 42).pack($u32, 8)
            .pack($u16, 1)
            .pack($u16, 0x0112).pack($u16, 3).pack($u32, 1).pack($u16, $orientation)."\0\0"
            .pack($u32, 0);
        $data = "Exif\0\0".$tiff;

        return "\xFF\xE1".pack('n', strlen($data) + 2).$data;
    }

    private function withExif(string $jpeg, int $orientation, string $order): string
    {
        return substr($jpeg, 0, 2).$this->exifSegment($orientation, $order).substr($jpeg, 2);
    }

    private function assertThrows(callable $fn, string $class, ?string $messageContains = null): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);
            if ($messageContains !== null) {
                $this->assertStringContainsString($messageContains, $e->getMessage());
            }

            return;
        }

        $this->fail("Expected {$class} to be thrown.");
    }
}
