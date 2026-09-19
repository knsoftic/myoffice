<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\NormalisesContentInput;
use App\Http\Requests\Cms\Concerns\ValidatesContentImage;
use App\Models\Cms\PortfolioItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Add images to a portfolio gallery — `admin.portfolio.images.store`, `can:portfolio.upload`
 * (phase-04 §6.3, §6.11 `StorePortfolioImagesRequest`, §8.3).
 *
 * Either new uploads (`images[]`, the §6.6 rules) **or** picks from the media library
 * (`media_asset_ids[]`, each an existing, non-trashed asset) — at least one of the two — and the
 * gallery may never exceed 20 attached images in total. A batch that would is refused whole; the service
 * repeats the count under its lock and rejects the batch if any file fails (§6.3 invariant 3).
 */
final class StorePortfolioImagesRequest extends CmsFormRequest
{
    use NormalisesContentInput;
    use ValidatesContentImage;

    /** §6.3 invariant 3 — the same ceiling `ValidatesPortfolioItem` applies to a create form. */
    private const MAX_GALLERY_IMAGES = 20;

    protected function permission(): string
    {
        return 'portfolio.upload';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $image = array_values(array_diff($this->imageRules('image')['image'], ['sometimes', 'nullable']));
        $max = self::MAX_GALLERY_IMAGES;

        return [
            'images' => ['bail', 'required_without:media_asset_ids', 'array', 'min:1', 'max:'.$max],
            'images.*' => $image,
            'media_asset_ids' => ['bail', 'required_without:images', 'array', 'min:1', 'max:'.$max],
            'media_asset_ids.*' => ['bail', 'integer', 'min:1', 'distinct', Rule::exists('media_assets', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $item = $this->portfolioItem();

                if ($item === null) {
                    return;
                }

                $attached = DB::table('portfolio_item_media')->where('portfolio_item_id', $item->getKey())->count();
                $incoming = count($this->uploads()) + count($this->mediaAssetIds());
                $max = self::MAX_GALLERY_IMAGES;

                if ($attached + $incoming > $max) {
                    $validator->errors()->add(
                        $this->has('images') ? 'images' : 'media_asset_ids',
                        sprintf('A project gallery holds at most %d images. This one has %d, so at most %d more can be added.', $max, $attached, max(0, $max - $attached)),
                    );
                }
            },
        ];
    }

    public function portfolioItem(): ?PortfolioItem
    {
        $item = $this->route('item');

        return $item instanceof PortfolioItem ? $item : null;
    }

    /**
     * @return list<UploadedFile>
     */
    public function uploads(): array
    {
        $files = $this->file('images');

        if (! is_array($files)) {
            return [];
        }

        return array_values(array_filter($files, static fn (mixed $file): bool => $file instanceof UploadedFile));
    }

    /**
     * @return list<int>
     */
    public function mediaAssetIds(): array
    {
        return $this->validatedIds('media_asset_ids');
    }
}
