<?php

// What the host block's loader adds is the test's: a callable it sets, given
// the context, so each test draws what it needs from inside a render.
return isset($GLOBALS['dx_host_loader']) ? ($GLOBALS['dx_host_loader'])($context) : [];
