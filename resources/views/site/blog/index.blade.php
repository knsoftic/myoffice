{{--
    The blog — site.blog.index (phase-04 §8.11, §9.2; requirement §15). A 404 before this view when the blog_posts module
    is disabled (site_module middleware).

    Controller variables (Site\BlogController@index, through ComposesContentPages::contentPage()):
      $site               the SitePayload (seo for route_key site.blog.index)
      $page               array{title, slug}
      $posts              LengthAwarePaginator<App\Models\Cms\BlogPost> — BlogPost::public() (published AND published_at <= now),
                          newest first, per page = website.blog_per_page; category, author, featuredImage and public tags
                          eager-loaded; filtered by ?search= (title, excerpt) when present
      $featuredPost       ?BlogPost   the newest featured public post — page 1 without a search only, excluded from $posts
      $sidebarCategories  Collection<BlogCategory>  BlogCategory::public() with public_posts_count
      $tagCloud           Collection<BlogTag>       active tags on at least one public post, with public_posts_count
      $search             ?string
      $companyName        optional ?string   byline when a post has no author
--}}

@extends('site.layouts.public')

@section('title', (string) data_get($page ?? null, 'title', 'Blog'))

@section('content')
    @include('site.marketing.partials.page-hero', [
        'title' => (string) data_get($page ?? null, 'title', 'Blog'),
        'subtitle' => $intro ?? 'Notes on building software, running projects and learning to code.',
    ])

    @include('site.blog.partials.listing', [
        'posts' => $posts,
        'featured' => $featuredPost ?? null,
        'categories' => $sidebarCategories ?? collect(),
        'tags' => $tagCloud ?? collect(),
        'search' => $search ?? null,
        'companyName' => $companyName ?? null,
        'lazy' => $lazyImages ?? true,
    ])
@endsection
