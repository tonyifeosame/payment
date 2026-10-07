<?php

namespace App\Support;

/**
 * Untrusted text for the Markdown receipt email (L8).
 *
 * The receipt email is a Markdown mail: its HTML table is passed through
 * CommonMark. A value is safe inside an HTML block only while it stays on its
 * lines — a blank line inside a payer or student value ends the block, and what
 * follows is parsed as Markdown (headings, link reference definitions, links).
 * Single-line fields therefore have every line break and control character
 * collapsed to a space. Link and image syntax is additionally neutralised for
 * every {{ }} value by Markdown::withSecuredEncoding() (AppServiceProvider).
 */
final class MailText
{
    /** One line of text: line breaks and other control characters become spaces. */
    public static function line(?string $value): string
    {
        $value = (string) $value;

        return trim((string) preg_replace('/[\p{Cc}\x{2028}\x{2029}]+/u', ' ', $value));
    }
}
