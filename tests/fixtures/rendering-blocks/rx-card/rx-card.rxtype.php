<?php

// The loader on an `rxtype` post, after rx-card.php: what it saw of the first

return [
    'twice'           => 'variant',
    'variant_saw'     => (string) ($context['twice'] ?? 'nothing'),
    'variant_post_id' => $post_id,
];
