<?php

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CmsPageTranslation extends Model
{
    use HasUuids;

    protected $table = 'cms_page_translations';

    protected $guarded = ['id'];
}
