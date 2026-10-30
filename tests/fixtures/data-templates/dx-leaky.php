<?php

// A template that prints around its render and leaves two buffers open.
ob_start();
echo 'printed before';
ob_start();
WpGoBlocks::render(['dx-leaky.twig'], ['post' => WpGoBlocks::get_post()]);
echo 'printed after';
