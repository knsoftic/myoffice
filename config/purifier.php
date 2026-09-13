<?php

// Mirrors App\Support\RichText::PROFILES (D25, ND-5). RichText builds its engine config from that
// constant and never reads this file; do not add a profile here — add it to RichText, reviewed as a
// security change.
return [
    'encoding' => 'UTF-8',
    'finalize' => true,
    'ignoreNonStrings' => false,
    'cachePath' => storage_path('app/purifier'),
    'cacheFileMode' => 0755,
    'settings' => [
        'cms' => [
            'HTML.Doctype' => 'HTML 4.01 Transitional',
            'HTML.Allowed' => 'p[title|class],br,strong,em,u,s,h2,h3,h4,ul,ol,li,blockquote,a[href|title|target|rel|class],img[src|alt|width|height|title|class],figure,figcaption,table[width|height],thead,tbody,tr,th[width|height],td[width|height],hr,span[title|class],iframe[src|width|height|title|class]',
            'HTML.SafeIframe' => true,
            'URI.SafeIframeRegexp' => '%^(https://www\.youtube\.com/embed/|https://player\.vimeo\.com/video/|https://www\.google\.com/maps/embed)%',
            'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true],
            'Attr.AllowedFrameTargets' => ['_blank', '_self'],
        ],
        'material' => [
            'HTML.Doctype' => 'HTML 4.01 Transitional',
            'HTML.Allowed' => 'p,br,strong,em,u,s,h1,h2,h3,h4,h5,h6,ul,ol,li,blockquote,a[href|target|rel],img[src|alt|width|height],figure,figcaption,table[width|height],thead,tbody,tr,th[width|height],td[width|height],hr,span,div,small,b,i,sub,sup,*[title|class|style|align]',
            'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true, 'tel' => true, 'data' => true],
            'Attr.AllowedFrameTargets' => ['_blank', '_self'],
        ],
    ],
];
