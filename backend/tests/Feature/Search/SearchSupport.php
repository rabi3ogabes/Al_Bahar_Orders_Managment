<?php

/**
 * The data a page hands to a browser component: `x-data="name(JSON.parse('...'))"` (Blade's @js). The JavaScript string literal is read the way a browser
 * reads it (\uXXXX and \\ escapes), then the JSON inside it.
 *
 * @return array<string, mixed>
 */
function bootOf(string $html, string $component): array
{
    if (preg_match('/'.preg_quote($component, '/')."\\(JSON\\.parse\\('(.*?)'\\)\\)/s", $html, $m) !== 1) {
        return [];
    }
    $json = preg_replace_callback('/\\\\(u[0-9a-fA-F]{4}|.)/s', fn (array $x) => str_starts_with($x[1], 'u') && strlen($x[1]) === 5 ? mb_chr((int) hexdec(substr($x[1], 1))) : $x[1], $m[1]);

    return json_decode((string) $json, true) ?? [];
}
