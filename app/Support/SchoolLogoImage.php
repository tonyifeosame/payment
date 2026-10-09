<?php

namespace App\Support;

use GdImage;
use InvalidArgumentException;

/**
 * Normalises an uploaded school logo before it is stored.
 *
 * A logo is only ever shown small (40–80px on pages, 48px in email, 52px in the
 * PDF), but the original upload — up to 1 MiB — used to be stored and served
 * as-is. Every upload is now re-encoded once, with GD, into a single copy:
 *
 *   - the format is kept: JPEG stays JPEG (quality 86), PNG stays PNG and WebP
 *     stays lossy WebP (quality 86);
 *   - anything larger than 512px on its longest side is scaled down to fit,
 *     keeping its aspect ratio; smaller images are never scaled up;
 *   - PNG and WebP transparency is kept, and a PNG that needs no resizing and is
 *     a palette image stays a palette image, so it does not double in size;
 *   - a JPEG's EXIF orientation is applied to the pixels, because re-encoding
 *     drops the EXIF block a browser would otherwise have rotated it by;
 *   - re-encoding writes none of the upload's metadata (EXIF, GPS, text chunks).
 *
 * Nothing is decoded before the header has been checked: an image larger than
 * 5000px on either side, an unsupported type, or an animated WebP (which GD
 * cannot decode) is refused from its header alone, so a small file that
 * declares enormous dimensions can never be expanded into memory.
 *
 * The output is deterministic, so re-uploading the same file stores the same
 * bytes and the logo's ETag and Last-Modified do not change.
 */
final class SchoolLogoImage
{
    public const MAX_SOURCE_DIMENSION = 5000;

    public const MAX_OUTPUT_DIMENSION = 512;

    public const JPEG_QUALITY = 86;

    public const WEBP_QUALITY = 86;

    private const MIME_TYPES = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    /**
     * Why these bytes cannot become a logo, or null when they can. Reads the
     * image header only; never decodes pixels.
     */
    public static function problem(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false || ! isset(self::MIME_TYPES[$info[2]])) {
            return 'The logo must be a PNG, JPG or WebP image.';
        }

        [$width, $height] = $info;

        if ($width < 1 || $height < 1) {
            return 'The logo image could not be read. Please upload a different file.';
        }

        if ($width > self::MAX_SOURCE_DIMENSION || $height > self::MAX_SOURCE_DIMENSION) {
            return 'The logo must be at most '.self::MAX_SOURCE_DIMENSION.' × '.self::MAX_SOURCE_DIMENSION.' pixels.';
        }

        if ($info[2] === IMAGETYPE_WEBP && self::isAnimatedWebp($bytes)) {
            return 'Animated images cannot be used as a logo. Please upload a still PNG, JPG or WebP image.';
        }

        return null;
    }

    /**
     * The normalised logo.
     *
     * @return array{mime: string, bytes: string}
     *
     * @throws InvalidArgumentException with a message fit to show the uploader
     */
    public static function normalize(string $bytes): array
    {
        if (($problem = self::problem($bytes)) !== null) {
            throw new InvalidArgumentException($problem);
        }

        // Silenced like every GD/libjpeg call here: a recoverable "corrupt data"
        // warning would otherwise become an exception inside a request.
        $type = @getimagesizefromstring($bytes)[2];

        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof GdImage) {
            throw new InvalidArgumentException('The logo image could not be read. Please upload a different file.');
        }

        // Scaled down before it is oriented, so the rotated copy is at most 512px
        // and a full-size source is never held in memory twice. Orientation only
        // flips or turns by 90°, so the longest side, and the fit, are the same.
        $image = self::fit($image);

        if ($type === IMAGETYPE_JPEG) {
            $image = self::applyOrientation($image, self::jpegOrientation($bytes));
        }

        ob_start();
        match ($type) {
            IMAGETYPE_JPEG => imagejpeg($image, null, self::JPEG_QUALITY),
            IMAGETYPE_PNG => imagepng($image, null, 9),
            IMAGETYPE_WEBP => imagewebp($image, null, self::WEBP_QUALITY),
        };
        $encoded = (string) ob_get_clean();

        if ($encoded === '') {
            throw new InvalidArgumentException('The logo image could not be processed. Please upload a different file.');
        }

        return ['mime' => self::MIME_TYPES[$type], 'bytes' => $encoded];
    }

    /**
     * Scale down to fit MAX_OUTPUT_DIMENSION, keeping transparency. An image that
     * already fits is returned as it is, palette or truecolor.
     */
    private static function fit(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = self::MAX_OUTPUT_DIMENSION / max($width, $height);

        if ($scale >= 1) {
            imagesavealpha($image, true);

            return $image;
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $resized;
    }

    /**
     * A WebP whose VP8X header sets the animation flag. The container format
     * requires that flag on every animated file; anything else GD cannot decode
     * is still caught when decoding fails.
     */
    private static function isAnimatedWebp(string $bytes): bool
    {
        return substr($bytes, 12, 4) === 'VP8X' && strlen($bytes) > 20 && (ord($bytes[20]) & 0x02) !== 0;
    }

    /**
     * The EXIF orientation (1–8) of a JPEG, read without the exif extension.
     *
     * Walks the JPEG segments to the APP1 "Exif" block and reads tag 0x0112 from
     * IFD0, in either byte order. Every offset is bounds-checked, and anything
     * unexpected answers 1 (as stored), which is what GD did before.
     */
    public static function jpegOrientation(string $bytes): int
    {
        $length = strlen($bytes);

        if ($length < 4 || $bytes[0] !== "\xFF" || $bytes[1] !== "\xD8") {
            return 1;
        }

        $pos = 2;

        while ($pos + 4 <= $length && $bytes[$pos] === "\xFF") {
            $marker = ord($bytes[$pos + 1]);

            if ($marker === 0xDA || $marker === 0xD9) {
                break; // start of image data, or end of image
            }

            $segmentLength = unpack('n', substr($bytes, $pos + 2, 2))[1];
            if ($segmentLength < 2 || $pos + 2 + $segmentLength > $length) {
                return 1;
            }

            if ($marker === 0xE1 && substr($bytes, $pos + 4, 6) === "Exif\0\0") {
                return self::tiffOrientation(substr($bytes, $pos + 10, $segmentLength - 8));
            }

            $pos += 2 + $segmentLength;
        }

        return 1;
    }

    private static function tiffOrientation(string $tiff): int
    {
        $length = strlen($tiff);

        if ($length < 8) {
            return 1;
        }

        [$u16, $u32] = match (substr($tiff, 0, 2)) {
            'II' => ['v', 'V'],
            'MM' => ['n', 'N'],
            default => [null, null],
        };

        if ($u16 === null || unpack($u16, substr($tiff, 2, 2))[1] !== 42) {
            return 1;
        }

        $ifd = unpack($u32, substr($tiff, 4, 4))[1];
        if ($ifd < 8 || $ifd + 2 > $length) {
            return 1;
        }

        $entries = unpack($u16, substr($tiff, $ifd, 2))[1];

        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($entry + 12 > $length) {
                return 1;
            }

            if (unpack($u16, substr($tiff, $entry, 2))[1] !== 0x0112) {
                continue;
            }

            $type = unpack($u16, substr($tiff, $entry + 2, 2))[1];
            $count = unpack($u32, substr($tiff, $entry + 4, 4))[1];
            if ($type !== 3 || $count !== 1) {
                return 1; // not a single SHORT: not a value we trust
            }

            $value = unpack($u16, substr($tiff, $entry + 8, 2))[1];

            return $value >= 1 && $value <= 8 ? $value : 1;
        }

        return 1;
    }

    /** Turn the stored pixels into the upright image a browser would show. */
    private static function applyOrientation(GdImage $image, int $orientation): GdImage
    {
        $oriented = self::orient($image, $orientation);

        if (! $oriented instanceof GdImage) {
            throw new InvalidArgumentException('The logo image could not be processed. Please upload a different file.');
        }

        return $oriented;
    }

    private static function orient(GdImage $image, int $orientation): GdImage|false
    {
        // imagerotate() angles are counter-clockwise.
        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $image;
            case 3:
                return imagerotate($image, 180, 0);
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);

                return $image;
            case 5:
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return imagerotate($image, 90, 0);
            case 6:
                return imagerotate($image, -90, 0);
            case 7:
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return imagerotate($image, -90, 0);
            case 8:
                return imagerotate($image, 90, 0);
            default:
                return $image;
        }
    }
}
