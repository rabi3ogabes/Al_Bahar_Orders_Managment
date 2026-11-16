<?php

namespace App\Domain\Cms\Services;

use App\Domain\Settings\Services\Settings;
use App\Support\Contrast;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The calm picture behind every page of the web store and the customer app (docs/api/PAGE_BACKDROP.md): the built-in Doha
 * skyline, the admin's own picture, or nothing, plus its strength and the page colour. Set from the dashboard (Appearance >
 * Page background), stored as one plain array in the settings table (app.backdrop) and published inside the public theme
 * payload. Everything is checked against fixed allow-lists; unknown fields are dropped.
 *
 * Stored: style, image (a path on the media disk under backdrop/), fit, opacity (whole percent 2..50), color and tint
 * ("#RRGGBB" upper-case or null). Published: the same block with opacity as 0.02..0.50 and the picture as an absolute URL.
 */
class BackdropService
{
    public const SETTING = 'app.backdrop';

    /** Folder of the uploaded picture on the media disk. */
    public const FOLDER = 'backdrop';

    public const STYLES = ['skyline', 'image', 'none'];

    public const FITS = ['bottom', 'cover'];

    /** What the dashboard form edits. The picture path is never one of them: it comes from the server, never from the browser. */
    public const FIELDS = ['style', 'fit', 'opacity', 'color', 'tint'];

    public const OPACITY_MIN = 2;

    public const OPACITY_MAX = 50;

    public const OPACITY_DEFAULT = 8;

    public const IMAGE_MAX_KB = 4096;

    public const IMAGE_MIN_WIDTH = 600;

    /** Text colour assumed when no theme can be found (the default Al Bahar look). */
    public const FALLBACK_INK = '#2A1A20';

    public function __construct(private readonly Settings $settings, private readonly ThemeService $themes) {}

    /** @return array{style: string, image: string|null, fit: string, opacity: int, color: string|null, tint: string|null} */
    public function defaults(): array
    {
        return ['style' => 'skyline', 'image' => null, 'fit' => 'bottom', 'opacity' => self::OPACITY_DEFAULT, 'color' => null, 'tint' => null];
    }

    /**
     * What is stored: the saved background, or the default when nothing valid is saved. Plain values only.
     *
     * @return array{style: string, image: string|null, fit: string, opacity: int, color: string|null, tint: string|null}
     */
    public function config(): array
    {
        $saved = $this->settings->get(self::SETTING);
        if (! is_array($saved)) {
            return $this->defaults();
        }
        try {
            return $this->clean($saved);
        } catch (ValidationException) {
            return $this->defaults();
        }
    }

    /**
     * The public block inside /api/v1/theme. A picture that is gone from the disk falls back to the skyline, so the page never
     * shows an empty frame.
     *
     * @return array{style: string, image_url: string|null, fit: string, opacity: float, color: string|null, tint: string|null}
     */
    public function payload(): array
    {
        $c = $this->config();
        $style = $c['style'];
        $url = null;
        if ($style === 'image') {
            $url = $this->imageUrl($c['image']);
            if ($url === null) {
                $style = 'skyline';
            }
        }

        return [
            'style' => $style,
            'image_url' => $url,
            'fit' => $c['fit'],
            'opacity' => round(max(self::OPACITY_MIN, min(self::OPACITY_MAX, $c['opacity'])) / 100, 2),
            'color' => $c['color'],
            'tint' => $c['tint'],
        ];
    }

    /** Absolute URL of a stored picture, or null when there is none or the file is gone from the disk. */
    public function imageUrl(?string $path): ?string
    {
        if ($path === null || $path === '' || ! Storage::disk($this->disk())->exists($path)) {
            return null;
        }
        $url = Storage::disk($this->disk())->url($path);

        return str_starts_with($url, '/') ? url($url) : $url;
    }

    /**
     * Strict check of a background: allow-lists, strength 2 to 50, colours "#RRGGBB", and the page colour must keep the
     * theme's text readable (WCAG AA). Returns the cleaned array (unknown fields dropped) or throws a ValidationException.
     *
     * @param  array<string, mixed>  $input
     * @return array{style: string, image: string|null, fit: string, opacity: int, color: string|null, tint: string|null}
     */
    public function validate(array $input): array
    {
        $clean = $this->clean($input);
        $this->assertReadable($clean['color']);

        return $clean;
    }

    /**
     * Validates and stores the background and clears the public theme cache, so the store and the apps see it at once. The
     * picture path is never taken from $input: it stays the stored one, or becomes the file in $upload.
     *
     * @param  array<string, mixed>  $input
     * @return array{style: string, image: string|null, fit: string, opacity: int, color: string|null, tint: string|null}
     */
    public function save(array $input, ?UploadedFile $upload = null): array
    {
        $current = $this->config();
        $clean = $this->validate(['image' => $current['image']] + Arr::only($input, self::FIELDS) + $this->defaults());
        if ($clean['style'] === 'image' && ! $upload && $this->imageUrl($clean['image']) === null) {
            throw ValidationException::withMessages(['style' => __('backdrop_admin.err_image_missing')]);
        }

        $newPath = $upload ? $this->storeImage($upload) : null;
        if ($newPath !== null) {
            $clean['image'] = $newPath;
        }
        try {
            $this->settings->set(self::SETTING, $clean);
        } catch (Throwable $e) {
            $this->deleteFile($newPath);
            throw $e;
        }
        if ($newPath !== null && $current['image'] !== $newPath) {
            $this->deleteFile($current['image']);
        }
        $this->themes->forget();

        return $clean;
    }

    /**
     * Puts the original look back: skyline, original strength, theme colours. An uploaded picture stays available under "My
     * picture" (nothing is deleted).
     *
     * @return array{style: string, image: string|null, fit: string, opacity: int, color: string|null, tint: string|null}
     */
    public function reset(): array
    {
        $clean = array_replace($this->defaults(), ['image' => $this->config()['image']]);
        $this->settings->set(self::SETTING, $clean);
        $this->themes->forget();

        return $clean;
    }

    /**
     * Checks an uploaded picture (JPG, PNG or WebP, up to 4 MB, at least 600 px wide; SVG is refused because it can carry
     * script) and stores it on the media disk under backdrop/ with a random name. Returns the path. Does not change the setting.
     *
     * @throws ValidationException
     */
    public function storeImage(UploadedFile $file): string
    {
        Validator::make(['image' => $file], ['image' => self::imageRules()], [], ['image' => __('backdrop_admin.attr_picture')])->validate();

        $ext = strtolower((string) $file->guessExtension());
        $ext = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? $ext : 'jpg';
        $path = $file->storePubliclyAs(self::FOLDER, Str::uuid().'.'.$ext, $this->disk());
        if (! is_string($path)) {
            throw ValidationException::withMessages(['image' => __('backdrop_admin.err_store')]);
        }

        return $path;
    }

    /**
     * Deletes the stored picture for good. If it was the live background, the background becomes the skyline.
     *
     * @return array{style: string, image: string|null, fit: string, opacity: int, color: string|null, tint: string|null}
     */
    public function removeImage(): array
    {
        $clean = $this->config();
        $this->deleteFile($clean['image']);
        $clean['image'] = null;
        if ($clean['style'] === 'image') {
            $clean['style'] = 'skyline';
        }
        $this->settings->set(self::SETTING, $clean);
        $this->themes->forget();

        return $clean;
    }

    /** Validation rules for the picture file, shared by the service and the dashboard form. @return list<mixed> */
    public static function imageRules(): array
    {
        return ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::IMAGE_MAX_KB, 'dimensions:min_width='.self::IMAGE_MIN_WIDTH];
    }

    /** The text colour of the look that is live now (page colour must stay readable against it). */
    public function ink(): string
    {
        $colors = $this->themes->active()?->getAttribute('colors');
        $ink = is_array($colors) ? ($colors['ink'] ?? null) : null;

        return Contrast::isHex($ink) ? $ink : self::FALLBACK_INK;
    }

    /**
     * Shape check only (no contrast): what is allowed to be stored and read back.
     *
     * @param  array<string, mixed>  $input
     * @return array{style: string, image: string|null, fit: string, opacity: int, color: string|null, tint: string|null}
     */
    private function clean(array $input): array
    {
        $data = Validator::make(Arr::only($input, [...self::FIELDS, 'image']) + $this->defaults(), [
            'style' => ['required', Rule::in(self::STYLES)],
            'fit' => ['required', Rule::in(self::FITS)],
            'opacity' => ['required', 'integer', 'between:'.self::OPACITY_MIN.','.self::OPACITY_MAX],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'tint' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'image' => ['nullable', 'string', 'max:200', 'regex:#^'.self::FOLDER.'/[A-Za-z0-9][A-Za-z0-9._-]*$#'],
        ], [
            'opacity.*' => __('backdrop_admin.err_opacity', ['min' => self::OPACITY_MIN, 'max' => self::OPACITY_MAX]),
        ])->validate();

        return [
            'style' => $data['style'],
            'image' => ! empty($data['image']) ? $data['image'] : null,
            'fit' => $data['fit'],
            'opacity' => (int) $data['opacity'],
            'color' => ! empty($data['color']) ? strtoupper($data['color']) : null,
            'tint' => ! empty($data['tint']) ? strtoupper($data['tint']) : null,
        ];
    }

    /** @throws ValidationException when the page colour makes the theme's text hard to read */
    private function assertReadable(?string $color): void
    {
        if ($color === null) {
            return;
        }
        $ratio = Contrast::ratio($color, $this->ink());
        if ($ratio < Contrast::AA) {
            throw ValidationException::withMessages(['color' => __('backdrop_admin.err_contrast', ['ratio' => number_format($ratio, 1), 'min' => Contrast::AA])]);
        }
    }

    private function deleteFile(?string $path): void
    {
        if ($path !== null && str_starts_with($path, self::FOLDER.'/')) {
            Storage::disk($this->disk())->delete($path);
        }
    }

    private function disk(): string
    {
        return (string) config('platform.media.disk');
    }
}
