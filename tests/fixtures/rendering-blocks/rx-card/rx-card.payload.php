<?php

// Two files in one: on a post of type `payload` with TimberRenderer, the
// loader variant; with PayloadRenderer, the block's own template, which must
// not also run as a loader. Each run is counted, with whether a run before
// it had already merged its return into the context.

$GLOBALS['rx_payload_runs'][] = isset($context['from_payload_file']) ? 'after-a-loader-run' : 'first';

return ['from_payload_file' => true, 'title' => $context['title'] ?? null];
