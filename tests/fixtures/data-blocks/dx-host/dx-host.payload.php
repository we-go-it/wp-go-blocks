<?php

// The context, or what the test's callable makes of it: a block drawn from
// a `.payload.php` is drawn while the host renders, as from its loader.
return isset($GLOBALS['dx_host_payload']) ? ($GLOBALS['dx_host_payload'])($context) : $context;
