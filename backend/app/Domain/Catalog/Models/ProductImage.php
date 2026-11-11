<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['variants' => 'array'];

    public function url(?int $width = null): string
    {
        $path = $width && isset($this->variants[(string) $width]) ? $this->variants[(string) $width] : $this->path;
        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return Storage::disk($this->disk)->url($path);
    }

    public function alt(?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();

        return $this->getAttribute('alt_'.$locale) ?: $this->alt_en;
    }
}
