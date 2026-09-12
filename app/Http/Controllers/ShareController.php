<?php

namespace App\Http\Controllers;

use App\Models\School;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Tools for handing the public payment page to parents: the link, a WhatsApp
 * share, and a QR code.
 *
 * The QR encodes School::paymentUrl() and nothing else — a public URL that
 * contains only the school's slug.
 */
class ShareController extends Controller
{
    public function index(School $school)
    {
        $url = $school->paymentUrl();

        $message = sprintf(
            "Pay your %s school fees online here: %s\n\nYou will need the student's admission number.",
            $school->name,
            $url
        );

        return view('share.index', [
            'school' => $school,
            'paymentUrl' => $url,
            'whatsappUrl' => 'https://wa.me/?text='.rawurlencode($message),
            'qrUrl' => route('school.share.qr', ['school' => $school->slug]),
            'qrSvg' => $this->svg($url, 260),
        ]);
    }

    /** The QR as a standalone SVG, for download and print. */
    public function qr(School $school)
    {
        return response($this->svg($school->paymentUrl(), 600), 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'inline; filename="'.$school->slug.'-payment-qr.svg"',
        ]);
    }

    private function svg(string $text, int $size): string
    {
        $renderer = new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($text);
    }
}
