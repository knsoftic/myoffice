{{--
    Posts in one category — site.blog.category (phase-04 §8.11). A 404 before this view when the category is inactive.

    Controller variables (Site\BlogController@category, through ComposesContentPages::contentPage()):
      $site               the SitePayload, seo = SeoService::for($category)
      $page               array{title, slug}
      $category           App\Models\Cms\BlogCategory (public)
      $posts              LengthAwarePaginator<BlogPost> — public posts of this category, category/author/featuredImage/tags loaded
      $sidebarCategories  Collection<BlogCategory> with public_posts_count
      $tagCloud           Collection<BlogTag> with public_posts_count
--}}

@extends('site.layouts.public')

@section('title', $category->name)

@section('content')
    @include('site.marketing.partials.page-hero', [
        'title' => $category->name,
        'subtitle' => $category->description,
        'eyebrow' => 'Category',
        'crumbs' => [['label' => 'Blog', 'url' => route('site.blog.index')]],
    ])

    @include('site.blog.partials.listing', [
        'posts' => $posts,
        'featured' => null,
        'categories' => $sidebarCategories ?? collect(),
        'tags' => $tagCloud ?? collect(),
        'search' => null,
        'activeCategory' => $category,
        'companyName' => $companyName ?? null,
        'lazy' => $lazyImages ?? true,
    ])
@endsection
