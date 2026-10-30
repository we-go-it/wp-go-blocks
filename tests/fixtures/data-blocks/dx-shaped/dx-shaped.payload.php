<?php

// The data tests' reshaped payload: some of the context, renamed, a null
// (left out), and a post (made data). Counts its runs, so a test sees it
// required once per render.
$GLOBALS['dx_shaped_runs'] = ($GLOBALS['dx_shaped_runs'] ?? 0) + 1;

return [
    'heading' => $context['title'] ?? null,
    'nothing' => null,
    'block'   => $context['block'] ?? null,
    'post'    => isset($context['dx_post']) ? get_post($context['dx_post']) : null,
];
