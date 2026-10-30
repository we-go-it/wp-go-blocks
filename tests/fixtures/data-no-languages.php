<?php

/**
 * A site with no languages plugin, for data-i18n-none.test.php: WP-Go
 * Languages left out of the active plugins for this request only (the
 * option itself isn't touched), and no WPML.
 */

WP_CLI::add_wp_hook('option_active_plugins', fn ($plugins) => array_values(array_filter(
    (array) $plugins,
    fn ($plugin) => !str_starts_with((string) $plugin, 'wp-go-languages/')
)));
