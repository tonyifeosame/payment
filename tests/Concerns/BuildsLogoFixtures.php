<?php

namespace Tests\Concerns;

/**
 * Hand-built image files for the logo tests: ones GD cannot produce itself.
 */
trait BuildsLogoFixtures
{
    /**
     * A valid greyscale PNG of $side × $side zero pixels. It deflates to roughly
     * 1 KB per 1,000 rows, so 10,000 × 10,000 is about 100 KB on disk and would
     * need ~400 MB of memory to decode.
     */
    protected function bombPng(int $side): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        $z = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
        $row = str_repeat("\0", $side + 1); // filter byte + one byte per pixel
        $idat = '';
        for ($y = 0; $y < $side; $y++) {
            $idat .= deflate_add($z, $row, ZLIB_NO_FLUSH);
        }
        $idat .= deflate_add($z, '', ZLIB_FINISH);

        return "\x89PNG\r\n\x1A\n"
            .$chunk('IHDR', pack('NN', $side, $side)."\x08\x00\x00\x00\x00")
            .$chunk('IDAT', $idat)
            .$chunk('IEND', '');
    }

    /** A two-frame animated WebP (VP8X + ANIM + ANMF), built from a GD lossless frame. */
    protected function animatedWebp(): string
    {
        $frame = imagecreatetruecolor(16, 16);
        ob_start();
        imagewebp($frame, null, IMG_WEBP_LOSSLESS);
        $still = (string) ob_get_clean();
        $vp8l = substr($still, 12); // the image chunk after "RIFF....WEBP"

        $u24 = fn (int $n) => substr(pack('V', $n), 0, 3);
        $chunk = fn (string $type, string $data) => $type.pack('V', strlen($data)).$data.(strlen($data) % 2 ? "\0" : '');

        $vp8x = $chunk('VP8X', "\x02\0\0\0".$u24(15).$u24(15)); // animation flag, 16 × 16 canvas
        $anim = $chunk('ANIM', "\0\0\0\0\0\0");
        $anmf = $chunk('ANMF', $u24(0).$u24(0).$u24(15).$u24(15).$u24(100)."\0".$vp8l);
        $body = 'WEBP'.$vp8x.$anim.$anmf.$anmf;

        return 'RIFF'.pack('V', strlen($body)).$body;
    }
}
