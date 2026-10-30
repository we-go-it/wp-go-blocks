<?php

// A loader that fails, unless the block's `calm` field is set

if (empty($fields['calm'])) {
    throw new RuntimeException('rx-throw: thrown on purpose');
}

return [];
