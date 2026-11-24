<?php

namespace App\Support;

/**
 * Early-loading hints for the storefront shell. The shell loads one entry script, and a page's own code is a separate chunk the entry only asks for
 * once it runs: that is one more round trip before the page can draw. Because the server knows which page an address is, it can list that chunk (and
 * the two fonts the first paint needs) in the shell's <head>, so the browser fetches them in parallel with the entry. The file names come from the
 * build's manifest (public/store/.vite/manifest.json); without one (no build, or a test shell) nothing is added.
 */
class StorefrontAssetHints
{
    /** Address prefix => the page's source file in the build's manifest. */
    private const PAGES = [
        'products' => 'src/pages/Product.tsx', 'shop' => 'src/pages/Shop.tsx', 'cart' => 'src/pages/Cart.tsx', 'checkout' => 'src/pages/Checkout.tsx',
        'deals' => 'src/pages/Deals.tsx', 'auctions' => 'src/pages/Auctions.tsx', 'orders' => 'src/pages/Order.tsx',
    ];

    /** Fonts the first paint needs, by language: the body font and the heading font that is actually used. */
    private const FONTS = [
        'en' => ['inter-latin-400-normal.woff2', 'cormorant-garamond-latin-500-normal.woff2'],
        'ar' => ['ibm-plex-sans-arabic-arabic-400-normal.woff2', 'ibm-plex-sans-arabic-arabic-600-normal.woff2'],
    ];

    /** @var array<string, array<string, array<string, mixed>>> manifests by file path and modification time */
    private static array $manifests = [];

    /**
     * @return list<string> ready-to-write <link> tags
     */
    public function tags(string $shellFile, string $path, string $locale): array
    {
        $manifest = $this->manifest(dirname($shellFile).'/.vite/manifest.json');
        if ($manifest === []) {
            return [];
        }
        $tags = [];
        foreach (self::FONTS[$locale === 'ar' ? 'ar' : 'en'] as $font) {
            foreach ($manifest as $key => $entry) {
                if (str_ends_with((string) $key, '/'.$font) && isset($entry['file'])) {
                    $tags[] = '<link rel="preload" as="font" type="font/woff2" crossorigin href="/store/'.e($entry['file']).'" />';
                    break;
                }
            }
        }
        $first = explode('/', trim($path, '/'))[0];
        $page = self::PAGES[$first] ?? null;
        if ($page !== null && isset($manifest[$page])) {
            foreach ($this->chunkFiles($manifest, $page) as $file) {
                $tags[] = '<link rel="modulepreload" crossorigin href="/store/'.e($file).'" />';
            }
        }

        return $tags;
    }

    /**
     * A page chunk and the shared chunks it imports statically, except the entry itself (already loaded by the shell).
     *
     * @param  array<string, array<string, mixed>>  $manifest
     * @return list<string>
     */
    private function chunkFiles(array $manifest, string $key, array &$seen = []): array
    {
        if (isset($seen[$key]) || ! isset($manifest[$key]) || ($manifest[$key]['isEntry'] ?? false)) {
            return [];
        }
        $seen[$key] = true;
        $files = [(string) $manifest[$key]['file']];
        foreach ($manifest[$key]['imports'] ?? [] as $import) {
            $files = array_merge($files, $this->chunkFiles($manifest, (string) $import, $seen));
        }

        return array_values(array_unique($files));
    }

    /** @return array<string, array<string, mixed>> */
    private function manifest(string $file): array
    {
        $time = @filemtime($file);
        if ($time === false) {
            return [];
        }
        $key = $file.'@'.$time;
        if (! isset(self::$manifests[$key])) {
            $json = json_decode((string) file_get_contents($file), true);
            self::$manifests = [$key => is_array($json) ? $json : []];
        }

        return self::$manifests[$key];
    }
}
