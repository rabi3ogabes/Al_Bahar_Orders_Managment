<?php

use App\Domain\Catalog\Services\ProductService;

it('neutralises script vectors that a pattern-based filter let through', function (string $dirty) {
    $clean = strtolower((string) app(ProductService::class)->sanitizeHtml($dirty));

    expect($clean)->not->toContain('javascript')->and($clean)->not->toContain('onerror')->and($clean)->not->toContain('onmouse')
        ->and($clean)->not->toContain('<script')->and($clean)->not->toContain('<img')->and($clean)->not->toContain('<svg');
})->with([
    'unquoted javascript url' => '<a href=javascript:alert(1)>x</a>',
    'entity-encoded tab in scheme' => '<a href="java&#9;script:alert(1)">x</a>',
    'entity-encoded first letter' => '<a href="&#106;avascript:alert(1)">x</a>',
    'slash separated handler' => '<span/onmouseover=alert(1)>x</span>',
    'image with handler' => '<p>a</p><img src=x onerror=alert(1)>',
    'svg script' => '<svg><script>alert(1)</script></svg>',
    'uppercase scheme' => '<a href="JaVaScRiPt:alert(1)">x</a>',
]);

it('keeps the formatting and safe links, in Arabic as well as English', function () {
    $out = app(ProductService::class)->sanitizeHtml('<h2>عنوان</h2><p>مرحبا 😀 <strong>bold</strong> <a href="https://albahar.qa/x?a=1&b=2" onclick="x()">رابط</a></p><ul><li>one</li></ul><a href="/pages/terms">t</a><a href="//evil.example">e</a>');

    expect($out)->toContain('<h2>عنوان</h2>')->and($out)->toContain('<strong>bold</strong>')->and($out)->toContain('href="https://albahar.qa/x?a=1&amp;b=2"')
        ->and($out)->toContain('<li>one</li>')->and($out)->toContain('href="/pages/terms"')->and($out)->not->toContain('onclick')->and($out)->not->toContain('evil.example')
        ->and($out)->toContain('rel="noopener noreferrer"');
    expect(app(ProductService::class)->sanitizeHtml(null))->toBeNull()->and(app(ProductService::class)->sanitizeHtml('  '))->toBe('');
});
