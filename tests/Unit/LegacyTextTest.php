<?php

declare(strict_types=1);

use ProjectSend\V1Migration\Transform\LegacyText;

it('decodes the entities v1 wrote', function (): void {
    expect(LegacyText::decode('Jos&eacute; P&eacute;rez &amp; Co.'))->toBe('José Pérez & Co.')
        ->and(LegacyText::decode('Dise&ntilde;o'))->toBe('Diseño')
        ->and(LegacyText::decode('M&uuml;ller &amp; S&ouml;hne'))->toBe('Müller & Söhne')
        ->and(LegacyText::decode('&Scaron;umava &mdash; přehled'))->toBe('Šumava — přehled');
});

it('handles a value that is entity-encoded and raw at the same time', function (): void {
    // htmlentities() only covers the HTML entity table, so v1 routinely
    // stored both forms in one string. The Greek accented characters
    // below have no entity and stayed raw while their neighbours did not.
    expect(LegacyText::decode('&Epsilon;&lambda;&lambda;&eta;&nu;&iota;&kappa;ά'))
        ->toBe('Ελληνικά');
});

it('leaves a fully raw value untouched', function (): void {
    expect(LegacyText::decode('Zaļā Enerģija'))->toBe('Zaļā Enerģija')
        ->and(LegacyText::decode('年度報告書'))->toBe('年度報告書');
});

it('decodes both quote styles, because v1 passed ENT_QUOTES', function (): void {
    expect(LegacyText::decode('He said &quot;hello&quot; to O&#039;Brien'))
        ->toBe('He said "hello" to O\'Brien');
});

it("turns nl2br's output back into real newlines", function (): void {
    expect(LegacyText::decode("First line<br />\nSecond line"))
        ->toBe("First line\nSecond line");
});

it('keeps a line break the user typed as a tag, because stripping happens before decoding', function (): void {
    // This is the whole reason the order matters. v1 ran htmlentities()
    // and *then* nl2br(), so a <br /> the user typed was already
    // &lt;br /&gt; by the time nl2br could see it. Decode first and the
    // two become indistinguishable — and a description explaining an
    // HTML tag silently loses it.
    expect(LegacyText::decode('Use &lt;br /&gt; for a line break'))
        ->toBe('Use <br /> for a line break');
});

it('handles a stray break with no newline after it', function (): void {
    expect(LegacyText::decode('One<br>Two<br/>Three'))->toBe("One\nTwo\nThree");
});

it('collapses whitespace for single-line columns', function (): void {
    expect(LegacyText::line("Acme<br />\nHoldings"))->toBe('Acme Holdings')
        ->and(LegacyText::line('  padded  name  '))->toBe('padded name');
});

it('passes null through untouched', function (): void {
    expect(LegacyText::decode(null))->toBeNull()
        ->and(LegacyText::line(null))->toBeNull();
});

it('decodes only once, so a genuine ampersand survives', function (): void {
    // Someone who typed "&amp;" into v1 stored "&amp;amp;". Decoding
    // repeatedly would rescue them and mangle everyone who typed a
    // plain "&" in a company name — far more common. One pass, and the
    // trade-off is documented.
    expect(LegacyText::decode('Smith &amp;amp; Sons'))->toBe('Smith &amp; Sons');
});

it('reports whether a value was encoded at all', function (): void {
    expect(LegacyText::isEncoded('Dise&ntilde;o'))->toBeTrue()
        ->and(LegacyText::isEncoded('Diseño'))->toBeFalse()
        ->and(LegacyText::isEncoded(null))->toBeFalse();
});
