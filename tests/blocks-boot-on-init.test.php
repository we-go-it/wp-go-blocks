<?php
// @require fixtures/blocks-skip-theme.php
// @require fixtures/blocks-boot-init-10.php

/**
 * boot() on `init` itself, at 10 (blocks-boot-init-10.php): the blocks
 * register at 10, and a callback added at the priority running doesn't run,
 * so this is too late as after `init` is, and warns the same. (On `init`
 * under 10, blocks-register.test.php: no warning, the blocks register.)
 */

use function WpGo\Blocks\Core\registered;

same(
    ['WpGo\\Blocks\\boot(): called too late: the blocks register on `init` (priority 10), which has run, so none registers. Call it as the theme loads, from functions.php'],
    $GLOBALS['wgt_boot']['warnings'],
    'booted on init at 10: the too-late warning, and no other (the blocks folder is there)'
);
same([], registered(), 'and, as it says, no block registered');
check('the package booted anyway', WpGoBlocks::config('category') === 'wgt-blocks' && WpGoBlocks::renderer() !== null);
check('WordPress\'s registry has none of the fixture\'s blocks', !WP_Block_Type_Registry::get_instance()->is_registered('acf/wgt-plain'));
