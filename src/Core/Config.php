<?php

namespace WpGo\Blocks\Core;

use WpGo\Blocks\Assets\AssetResolver;
use WpGo\Blocks\Entities\Entities;
use WpGo\Blocks\Renderer\Renderer;

/**
 * The settings boot() resolved: the theme's wp-go-blocks.json, the options passed
 * in code, and the defaults for whatever neither names. Read-only after boot.
 */
final class Config
{
    private const DEFAULTS = [
        'category'        => '',
        'blocks'          => 'blocks',
        'entries'         => ['main' => 'src/main.js', 'editor' => 'src/editor.js'],
        'bundles'         => [],
        'icon'            => null,
        'category_title'  => null,
        'preview_name'    => 'block-preview-{slug}',
        'assets'          => null,
        'renderer'        => null,
        'entities'        => null,
        'init'            => null,
        'content_api'     => false,
        'head_priorities' => ['scripts' => 15, 'styles' => 25],
        'buffer'          => true,
    ];

    /** @var array<string,mixed> */
    private static array $settings = self::DEFAULTS;

    private static string $root = '';

    /**
     * @param array<string,mixed> $settings
     */
    public static function set(string $root, array $settings): void
    {
        self::$root     = rtrim(wp_normalize_path($root), '/');
        self::$settings = array_replace(self::DEFAULTS, $settings);

        self::$settings['entries'] = array_replace(self::DEFAULTS['entries'], (array) ($settings['entries'] ?? []));
        self::$settings['blocks']  = trim((string) self::$settings['blocks'], '/');
    }

    /**
     * @return mixed
     */
    public static function get(string $key)
    {
        return self::$settings[$key] ?? null;
    }

    /** The theme the paths are relative to: where wp-go-blocks.json is. */
    public static function root(): string
    {
        return self::$root;
    }

    /** The blocks folder, absolute. */
    public static function blocks_dir(): string
    {
        return self::$root . '/' . self::$settings['blocks'];
    }

    public static function renderer(): ?Renderer
    {
        return self::$settings['renderer'] instanceof Renderer ? self::$settings['renderer'] : null;
    }

    /**
     * Render with another renderer for a while — one API request rendering as
     * data while the site renders as HTML. Returns the one it replaces, to put
     * back.
     */
    public static function use_renderer(?Renderer $renderer): ?Renderer
    {
        $previous = self::renderer();

        self::$settings['renderer'] = $renderer;

        return $previous;
    }

    public static function entities(): ?Entities
    {
        return self::$settings['entities'] instanceof Entities ? self::$settings['entities'] : null;
    }

    public static function assets(): ?AssetResolver
    {
        return self::$settings['assets'] instanceof AssetResolver ? self::$settings['assets'] : null;
    }
}
