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
 * Update a blog post — `admin.blog-posts.update`, `can:blog_posts.edit` plus `BlogPostPolicy::update()`
 * (your own post, or any post with `blog_posts.approve` — §4, §9.1.1, acceptance test 30).
 */
final class UpdateBlogPostRequest extends CmsFormRequest
{
    use DelegatesSeoRules;
    use NormalisesContentInput;
    use ValidatesBlogPost;
    use ValidatesContentImage;
    use ValidatesContentSlug;

    protected function permission(): string
    {
        return 'blog_posts.edit';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->blogPostRules(partial: true);
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
        $post = $this->route('post');

        return $post instanceof BlogPost ? $post : null;
    }
}
