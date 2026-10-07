<?php

namespace App\Http\Controllers;

use App\Support\AppUrl;
use Illuminate\Http\Response;

/**
 * /sitemap.xml: the indexable public pages, on APP_URL (https://feyra.site in
 * production), named by public/robots.txt.
 *
 * The list is exactly the pages that declare @section('canonical', 'on'), and
 * every <loc> equals that page's canonical link. School payment pages are left
 * out on purpose: they are noindex (a school's page is shared with its own
 * parents, not found through search), as are receipts and everything under
 * /admin. No <lastmod>: a date that is not a real modification time would only
 * mislead crawlers.
 */
class SitemapController extends Controller
{
    /** Route names of the indexable pages, in sitemap order. */
    public const PAGES = [
        'home',
        'registration.create',
        'contact.show',
        'privacy.show',
        'terms.show',
    ];

    public function __invoke(): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach (self::PAGES as $name) {
            $loc = AppUrl::to(route($name, [], false));
            $xml .= '  <url><loc>'.htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>'."\n";
        }

        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
