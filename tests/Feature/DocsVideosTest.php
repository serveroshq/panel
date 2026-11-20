<?php

use App\Support\Docs\DocsMarkdown;

it('plays a video file written like an image', function () {
    $html = DocsMarkdown::render('![The new themes](/storage/blog/themes.mp4)');

    expect($html)->toContain('<video src="/storage/blog/themes.mp4" controls playsinline preload="metadata" aria-label="The new themes"></video>')
        ->not->toContain('<img');
});

it('knows a video by its extension, whatever the case or query string', function () {
    expect(DocsMarkdown::render('![](https://cdn.example.com/a.WEBM?x=1)'))->toContain('<video src="https://cdn.example.com/a.WEBM?x=1"')
        ->and(DocsMarkdown::render('![](/storage/changelog/b.mov)'))->toContain('<video')
        ->and(DocsMarkdown::render('![](/storage/blog/c.png)'))->toContain('<img src="/storage/blog/c.png"');
});

it('never plays an unsafe link', function () {
    expect(DocsMarkdown::render('![](javascript:alert(1)//a.mp4)'))->not->toContain('<video')
        ->not->toContain('javascript:');
});
