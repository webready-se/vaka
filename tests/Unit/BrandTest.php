<?php

test('a mark is inline SVG, named when it stands alone and hidden beside a visible name', function () {
    expect(mark_svg('vaka', 'mark'))->toStartWith('<svg class="mark" aria-hidden="true" focusable="false" ')
        ->and(mark_svg('puls', 'credit-mark', 'Puls'))->toStartWith('<svg class="credit-mark" role="img" aria-label="Puls" focusable="false" ');
});

test('the footer credit links Webready and the released tools only, each mark linked, and can be turned off', function () {
    $credit = footer_credit(['footer_credit' => true]);
    expect($credit)->toContain('Made by <a href="https://webready.se">Webready</a> in Sweden')
        ->and($credit)->toContain('<a href="https://github.com/webready-se/puls"><svg class="credit-mark" role="img" aria-label="Puls"')
        ->and(substr_count($credit, 'class="credit-mark"'))->toBe(1)
        ->and($credit)->not->toContain('aria-label="Vaka"')
        ->and($credit)->not->toContain('aria-label="Snoka"')
        ->and($credit)->not->toContain('aria-label="Hamstra"')
        ->and(footer_credit(['footer_credit' => false]))->toBe('');
});

test('the tokens at the top of the stylesheet are the design kit\'s tokens.css, as they are', function () {
    $kit = __DIR__ . '/../../../open-source-design-kit';
    if (!is_dir($kit)) $this->markTestSkipped('the design kit is not cloned next to this repo');
    expect(file_get_contents(__DIR__ . '/../../public/app.css'))->toContain(file_get_contents("{$kit}/tokens.css"));
});

test('the favicons are the design kit marks', function () {
    $kit = __DIR__ . '/../../../open-source-design-kit/marks';
    if (!is_dir($kit)) $this->markTestSkipped('the design kit is not cloned next to this repo');
    expect(file_get_contents(__DIR__ . '/../../public/favicon.svg'))->toBe(file_get_contents("{$kit}/vaka.svg"))
        ->and(file_get_contents(__DIR__ . '/../../public/favicon-alert.svg'))->toBe(file_get_contents("{$kit}/vaka-alarm.svg"));
});
