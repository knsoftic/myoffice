{{--
    Posts with one tag — site.blog.tag (phase-04 §8.11). A 404 before this view when the tag is inactive.

    Controller variables (Site\BlogController@tag, through ComposesContentPages::contentPage()):
      $site               the SitePayload (seo: the blog's own route SEO — tags carry no seo_meta row, D23)
      $page               array{title, slug}
      $tag                App\Models\Cms\BlogTag (public)
      $tagPath            string   the tag page's relative path
      $posts              LengthAwarePaginator<BlogPost> — public posts carrying this tag, category/author/featuredImage/tags loaded
      $sidebarCategories  Collection<BlogCategory> with public_posts_count
      $tagCloud           Collection<BlogTag> with public_posts_count
--}}

@extends('site.layouts.public')

@section('title', '#'.$tag->name)

@section('content')
    @include('site.marketing.partials.page-hero', [
        'title' => '#'.$tag->name,
        'subtitle' => 'Every post tagged '.$tag->name.'.',
        'eyebrow' => 'Tag',
        'current' => $tag->name,
        'crumbs' => [['label' => 'Blog', 'url' => route('site.blog.index')]],
    ])

    @include('site.blog.partials.listing', [
        'posts' => $posts,
        'featured' => null,
        'categories' => $sidebarCategories ?? collect(),
        'tags' => $tagCloud ?? collect(),
        'search' => null,
        'activeTag' => $tag,
        'companyName' => $companyName ?? null,
        'lazy' => $lazyImages ?? true,
    ])
@endsection
