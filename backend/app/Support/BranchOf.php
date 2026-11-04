<?php

namespace App\Support;

use App\Domain\Branches\Models\Branch;
use Illuminate\Database\Eloquent\Model;

/** Resolves the owning branch of any model for authorization and audit, without tripping strict mode. */
final class BranchOf
{
    public static function model(Model $model): ?string
    {
        if ($model instanceof Branch) {
            return $model->getKey();
        }
        if (method_exists($model, 'authorizationBranchId')) {
            return $model->authorizationBranchId();
        }
        $attributes = $model->getAttributes();

        return $attributes['branch_id'] ?? null;
    }
}
