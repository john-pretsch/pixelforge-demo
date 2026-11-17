<?php

// Only overrides what differs from the package defaults: the starter kit keeps
// pages in resources/js/pages (lowercase), which page assertions need to find.
return [

    'page_paths' => [
        resource_path('js/pages'),
    ],

    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [
            resource_path('js/pages'),
        ],
        'page_extensions' => ['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue'],
    ],

];
