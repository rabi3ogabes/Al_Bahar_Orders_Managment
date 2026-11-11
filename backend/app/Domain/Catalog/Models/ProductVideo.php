<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductVideo extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    public function publicUrl(): ?string
    {
        return $this->source === 'upload' && $this->path ? Storage::disk($this->disk)->url($this->path) : $this->url;
    }
}
