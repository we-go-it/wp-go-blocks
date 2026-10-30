<?php

// A page template for the content API tests: what WordPress's conditionals
// say while it runs, in the page's context. Which file ran is the test's
// spy on template_include to tell.
WpGoBlocks::render(['dx-flags.twig'], [
    'post'  => WpGoBlocks::get_post(),
    'flags' => [
        'front_page' => is_front_page(),
        'privacy'    => is_privacy_policy(),
        'page'       => is_page(),
        'single'     => is_single(),
        'singular'   => is_singular(),
        'queried'    => get_queried_object_id(),
    ],
]);
