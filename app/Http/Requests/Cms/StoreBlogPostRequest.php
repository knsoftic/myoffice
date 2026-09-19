<?php

declare(strict_types=1);

namespace App\Http\Requests\Cms;

use App\Http\Requests\Cms\Concerns\DelegatesSeoRules;
use App\Http\Requests\Cms\Concerns\NormalisesContentInput;
use App\Http\Requests\Cms\Concerns\ValidatesBlogPost;
use App\Http\Requests\Cms\Concerns\ValidatesContentImage;
use App\Http\Requests\Cms\Concerns\ValidatesContentSlug;
use App\Models\Cms\BlogPost;
use Illuminate\Validation\Validator;

/**
 * Create a blog post — `admin.blog-posts.store`, `can:blog_posts.create` (phase-04 §4: the post is owned
 * by its creator; §6.7, §6.11, §8.7).
 */
final class StoreBlogPostRequest extends CmsFormRequest
{
    use DelegatesSeoRules;
    use NormalisesContentInput;
    use ValidatesBlogPost;
    use ValidatesContentImage;
    use ValidatesContentSlug;

    protected function permission(): string
    {
        return 'blog_posts.create';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->blogPostRules(partial: false);
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->blogPostAfter($validator)];
    }

    protected function prepareForValidation(): void
    {
        $this->prepareBlogPostInput();
    }

    public function blogPost(): ?BlogPost
    {
        return null;
    }
}
