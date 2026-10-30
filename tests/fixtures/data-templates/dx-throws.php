<?php

// A template that opens a buffer and throws before its render.
ob_start();
echo 'half a page';

throw new RuntimeException('dx template threw');
