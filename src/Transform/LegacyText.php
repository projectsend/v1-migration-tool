<?php

declare(strict_types=1);

namespace ProjectSend\V1Migration\Transform;

/**
 * Undoes what v1 did to every string on its way into the database.
 *
 * v1's `encode_html()` is `htmlentities($s, ENT_QUOTES, 'UTF-8')`
 * followed by `nl2br()`. Two consequences that matter here:
 *
 * **One value can be half-encoded.** `htmlentities()` only covers the
 * HTML entity table, so anything outside it stays raw. A single stored
 * string routinely holds both forms at once:
 *
 *     José Pérez & Co.  ->  Jos&eacute; P&eacute;rez &amp; Co.
 *     Λογαριασμός       ->  &Lambda;&omicron;&gamma;…ά&sigmaf;   (ά raw)
 *     Zaļā Enerģija     ->  Zaļā Enerģija                        (all raw)
 *     年度報告書        ->  年度報告書                            (all raw)
 *
 * Decoding handles all three: raw characters pass through untouched.
 *
 * **Decoding is not perfectly reversible.** Someone who typed a literal
 * `&amp;` into v1 stored `&amp;amp;`, and someone who typed `&` stored
 * `&amp;` — both decode to the same thing. Decoding once, rather than
 * repeatedly, is what keeps a genuine `&` in a company name from being
 * mangled; the price is that a literal `&amp;` becomes `&`. That trade
 * is deliberate and reported.
 *
 * The line-break handling has to happen **before** decoding, and that
 * order is the whole reason this is a class rather than one call.
 * `nl2br()` ran last in v1, so its breaks are literal `<br />` in the
 * stored bytes, while a `<br />` the *user typed* was already encoded to
 * `&lt;br /&gt;` by the step before it. Strip first and only the real
 * line breaks disappear; decode first and both look identical, and a
 * description explaining an HTML tag loses it.
 */
final class LegacyText
{
    /**
     * A v1 string as v2 should store it: real UTF-8, real newlines.
     */
    public static function decode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // nl2br's output, while it is still distinguishable from a <br>
        // the user typed. It emits "<br />\n" — collapse the pair, then
        // catch any stray tag on its own.
        $value = preg_replace('/<br\s*\/?>\r?\n/i', "\n", $value) ?? $value;
        $value = preg_replace('/<br\s*\/?>/i', "\n", $value) ?? $value;

        // ENT_HTML401 matches what htmlentities() used by default under
        // the PHP versions v1 ran on. ENT_QUOTES because v1 passed it,
        // so both &quot; and &#039; are in the data.
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML401, 'UTF-8');
    }

    /**
     * The same, for a v2 column that is a single-line string.
     *
     * `users.name`, `groups.name`, `files.name` and friends are varchars
     * rendered inline; a newline that arrived through nl2br would show
     * up as a broken row in every listing. Whitespace is collapsed
     * rather than stripped so "A\nB" stays two words.
     */
    public static function line(?string $value): ?string
    {
        $decoded = self::decode($value);

        if ($decoded === null) {
            return null;
        }

        return trim(preg_replace('/\s+/u', ' ', $decoded) ?? $decoded);
    }

    /**
     * True when decoding would change the value — used by preflight to
     * count how much of an install is affected before touching it.
     */
    public static function isEncoded(?string $value): bool
    {
        return $value !== null && self::decode($value) !== $value;
    }
}
