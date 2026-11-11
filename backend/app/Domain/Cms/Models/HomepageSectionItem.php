<?php

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HomepageSectionItem extends Model
{
    use HasUuids;

    protected $table = 'homepage_section_items';

    protected $guarded = ['id'];
}
