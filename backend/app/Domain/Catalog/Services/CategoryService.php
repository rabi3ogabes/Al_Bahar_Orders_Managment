<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Models\Category;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Unlimited nested categories using a materialised path ("/rootId/childId/") for fast descendant queries.
 * Moving a category under itself or its descendants is rejected (no cycles).
 */
class CategoryService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array{parent_id?: ?string, slug?: string, translations: array<string, array{name: string, description?: ?string}>} $data */
    public function create(array $data): Category
    {
        return DB::transaction(function () use ($data) {
            $parent = ! empty($data['parent_id']) ? Category::findOrFail($data['parent_id']) : null;
            $category = Category::create([
                'parent_id' => $parent?->id,
                'slug' => $data['slug'] ?? $this->uniqueSlug($data['translations']['en']['name']),
                'image_path' => $data['image_path'] ?? null,
                'icon_path' => $data['icon_path'] ?? null,
                'banner_path' => $data['banner_path'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
                'status' => $data['status'] ?? 'active',
                'filter_attribute_ids' => $data['filter_attribute_ids'] ?? null,
                'depth' => $parent ? $parent->depth + 1 : 0,
                'path' => $parent ? $parent->path.$parent->id.'/' : '/',
            ]);
            $this->syncTranslations($category, $data['translations']);
            $this->audit->log('catalog.category_created', $category, [], ['slug' => $category->slug, 'parent_id' => $category->parent_id]);

            return $category;
        });
    }

    public function update(Category $category, array $data): Category
    {
        return DB::transaction(function () use ($category, $data) {
            if (array_key_exists('parent_id', $data) && $data['parent_id'] !== $category->parent_id) {
                $this->move($category, $data['parent_id']);
            }
            $category->fill(collect($data)->only(['slug', 'image_path', 'icon_path', 'banner_path', 'sort_order', 'status', 'filter_attribute_ids'])->all());
            $dirty = $category->getDirty();
            $category->save();
            if (isset($data['translations'])) {
                $this->syncTranslations($category, $data['translations']);
            }
            $this->audit->log('catalog.category_updated', $category, [], $dirty);

            return $category;
        });
    }

    public function move(Category $category, ?string $newParentId): void
    {
        if ($newParentId === $category->id) {
            throw DomainException::make('invalid_category_parent');
        }
        $newParent = $newParentId ? Category::lockForUpdate()->findOrFail($newParentId) : null;
        if ($newParent && str_contains($newParent->path.$newParent->id.'/', '/'.$category->id.'/')) {
            throw DomainException::make('invalid_category_parent');
        }
        $oldPrefix = $category->path.$category->id.'/';
        $newPath = $newParent ? $newParent->path.$newParent->id.'/' : '/';
        $newPrefix = $newPath.$category->id.'/';
        $depthDelta = ($newParent ? $newParent->depth + 1 : 0) - $category->depth;

        // Re-root all descendants in one statement.
        DB::update('UPDATE categories SET path = ? || substr(path, ?), depth = depth + ? WHERE path LIKE ?',
            [$newPrefix, strlen($oldPrefix) + 1, $depthDelta, $oldPrefix.'%']);
        $category->forceFill(['parent_id' => $newParent?->id, 'path' => $newPath, 'depth' => $category->depth + $depthDelta])->save();
    }

    public function delete(Category $category): void
    {
        if ($category->children()->exists()) {
            throw DomainException::make('category_has_children');
        }
        if ($category->products()->exists()) {
            throw DomainException::make('category_has_products');
        }
        $category->delete();
        $this->audit->log('catalog.category_deleted', 'Category', ['id' => $category->id, 'slug' => $category->slug]);
    }

    /** Full active tree for the storefront, one query. */
    public function tree(bool $activeOnly = true): \Illuminate\Support\Collection
    {
        $all = Category::with('translations')->when($activeOnly, fn ($q) => $q->active())->orderBy('depth')->orderBy('sort_order')->orderBy('created_at')->get();
        $byParent = $all->groupBy(fn ($c) => $c->parent_id ?? 'root');
        $build = function ($parentKey) use (&$build, $byParent) {
            return ($byParent[$parentKey] ?? collect())->map(function (Category $c) use ($build) {
                $c->setRelation('children', $build($c->id));

                return $c;
            })->values();
        };

        return $build('root');
    }

    private function syncTranslations(Category $category, array $translations): void
    {
        foreach ($translations as $locale => $t) {
            if (empty($t['name'])) {
                continue;
            }
            $category->translations()->updateOrCreate(['locale' => $locale], [
                'name' => $t['name'], 'description' => $t['description'] ?? null,
                'seo_title' => $t['seo_title'] ?? null, 'seo_description' => $t['seo_description'] ?? null,
            ]);
        }
        $category->unsetRelation('translations');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $i = 2;
        while (Category::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
