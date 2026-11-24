<?php

namespace App\Http\Controllers;

use App\Support\StorefrontAssetHints;
use App\Support\StorefrontSeo;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the storefront single-page application shell. The SPA itself is built from /storefront into public/store
 * (see docker/php/Dockerfile); without a build the web root falls back to the admin sign-in so the site never 404s.
 *
 * The shell is the same file for every address, so the page-specific search tags (title, description, canonical link, robots rule,
 * social preview, structured data) are written into its <head> here, from the real catalogue (see StorefrontSeo). A product or page that
 * does not exist gets the shell with a real 404 status, so search engines do not keep a dead address.
 */
class StorefrontController extends Controller
{
    public function show(Request $request, StorefrontSeo $seo, StorefrontAssetHints $hints): Response
    {
        // The built storefront's HTML shell. Tests point `platform.storefront_shell` at a throw-away file so parallel test processes never share it.
        $shell = (string) (config('platform.storefront_shell') ?? public_path('store/index.html'));
        if (! is_file($shell)) {
            return redirect('/admin');
        }
        $meta = $seo->for((string) $request->path(), $request->query(), $request->query('lang') === 'ar' ? 'ar' : 'en');

        // The shell is tiny and references hashed assets; never cache it so a release is picked up at once.
        $meta['hints'] = $hints->tags($shell, (string) $request->path(), $meta['locale']);

        return response($this->render((string) file_get_contents($shell), $meta), $meta['status'], [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    /** @param array<string, mixed> $m */
    private function render(string $html, array $m): string
    {
        $ar = $m['locale'] === 'ar';
        if ($ar) {
            // The inline script of the shell keeps `?lang=ar` (see storefront/index.html); the server writes the same language into the first HTML.
            $html = (string) preg_replace('#<html lang="en" dir="ltr">#', '<html lang="ar" dir="rtl">', $html, 1);
        }
        if ($m['title'] !== null) {
            $html = (string) preg_replace('#<title>.*?</title>#s', '<title>'.e($m['title']).'</title>', $html, 1, $count);
            if ($count === 0) {
                $html = str_replace('</head>', '<title>'.e($m['title']).'</title></head>', $html);
            }
        }
        $title = $m['title'] ?? $this->currentTitle($html);
        if ($m['description'] !== null) {
            $tag = '<meta name="description" content="'.e($m['description']).'" />';
            $html = (string) preg_replace('#<meta name="description"[^>]*>#', $tag, $html, 1, $count);
            if ($count === 0) {
                $html = str_replace('</head>', $tag.'</head>', $html);
            }
        }
        $description = $m['description'] ?? $this->currentDescription($html);

        $out = [];
        if ($m['robots'] !== null) {
            $out[] = '<meta name="robots" content="'.e($m['robots']).'" />';
        }
        if ($m['canonical'] !== null) {
            $out[] = '<link rel="canonical" href="'.e($m['canonical']).'" />';
        }
        if ($m['robots'] === null || ! str_contains($m['robots'], 'noindex')) {
            $og = ['og:site_name' => trans('storefront_seo.suffix', [], $m['locale']), 'og:type' => $m['type'], 'og:title' => $title, 'og:description' => $description, 'og:url' => $m['canonical'], 'og:image' => $m['image']];
            foreach ($og as $property => $value) {
                if ($value !== null && $value !== '') {
                    $out[] = '<meta property="'.$property.'" content="'.e($value).'" />';
                }
            }
            $out[] = '<meta property="og:locale" content="'.($ar ? 'ar_QA' : 'en_QA').'" />';
            $out[] = '<meta property="og:locale:alternate" content="'.($ar ? 'en_QA' : 'ar_QA').'" />';
            $out[] = '<meta name="twitter:card" content="'.($m['image'] ? 'summary_large_image' : 'summary').'" />';
            $out[] = '<meta name="twitter:title" content="'.e($title).'" />';
            if ($description !== null && $description !== '') {
                $out[] = '<meta name="twitter:description" content="'.e($description).'" />';
            }
            if ($m['image']) {
                $out[] = '<meta name="twitter:image" content="'.e($m['image']).'" />';
            }
            if ($m['product'] !== null) {
                $out[] = '<meta property="product:price:amount" content="'.e($m['product']['amount']).'" />';
                $out[] = '<meta property="product:price:currency" content="'.e($m['product']['currency']).'" />';
                $out[] = '<meta property="product:availability" content="'.e($m['product']['availability']).'" />';
            }
        }
        foreach ($m['hints'] ?? [] as $hint) {
            $out[] = $hint;
        }
        foreach ($m['alternates'] ?? [] as $hreflang => $href) {
            $out[] = '<link rel="alternate" hreflang="'.$hreflang.'" href="'.e($href).'" />';
        }
        foreach ($m['jsonld'] as $block) {
            $json = json_encode(array_filter($block, fn ($v) => $v !== null), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
            $out[] = '<script type="application/ld+json">'.$json.'</script>';
        }

        $html = $out === [] ? $html : str_replace('</head>', implode("\n    ", $out)."\n  </head>", $html);
        if ($m['content'] !== null) {
            // The page's real text for readers that do not run JavaScript (many AI crawlers); browsers with JavaScript never show it.
            $copy = "\n    <noscript>".$m['content'].'</noscript>';
            $html = str_contains($html, '<div id="root"></div>') ? str_replace('<div id="root"></div>', '<div id="root"></div>'.$copy, $html) : str_replace('</body>', $copy."\n  </body>", $html);
        }

        return $html;
    }

    private function currentTitle(string $html): ?string
    {
        return preg_match('#<title>(.*?)</title>#s', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5) : null;
    }

    private function currentDescription(string $html): ?string
    {
        return preg_match('#<meta name="description" content="([^"]*)"#', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5) : null;
    }
}
