<?php

// The rendering tests' loader: what it had in scope, kept for the test to
// read in the context, and values for the merge order and the dropping of
// absent ones.

return [
    'override_me' => 'loader',
    'twice'       => 'loader',
    'loader_saw'  => [
        'post_id'    => $post_id,
        'is_preview' => $is_preview,
        'drawn'      => !empty($block['drawn']),
        'fields'     => implode(',', array_keys($fields)),
        'title'      => (string) ($context['title'] ?? ''),
        'rendering'  => \WpGo\Blocks\Core\rendering(),
        'previewing' => \WpGo\Blocks\Core\previewing(),
        'hand_offs'  => implode(',', array_keys(\WpGo\Blocks\Core\hand_offs())),
        'this'       => isset($this),
    ],
    'dropped_null'  => null,
    'dropped_empty' => '',
    'dropped_list'  => [],
    'kept_false'    => false,
    'kept_zero'     => 0,
    'nested'        => ['gone' => '', 'kept' => 'x', 'list' => [null, 'a', '']],
];
