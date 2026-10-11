<?php

namespace PluggedHost\ThemeV2\Support;

use Filament\Support\Colors\Color;

/**
 * The accent knob: Admin › Plugins › Theme v2 › Settings, stored as THEME_V2_ACCENT.
 *
 * theme.css is written in blue: the --phv2-accent* properties and the --primary-* palette
 * Filament reads. Any other accent is a `:root:root` block printed after the theme
 * (STYLES_AFTER, and the accent view on the standalone pages) that overrides exactly those
 * properties. Blue, the default, prints nothing, so the panel stays what the stylesheet says.
 *
 * A preset is one of Tailwind's palettes (Filament ships them), mapped the way theme.css maps
 * blue: fill 700, hover 800, text/ring 500, glow 400, --primary-N one rung darker than N from
 * 200 up. A custom colour is the fill itself, with the rest of the scale generated around its
 * hue and saturation. Two guards keep the fill/text split the theme depends on (see the header
 * of theme.css): the fill darkens until white text on it reaches 4.5:1, and the text rung moves
 * lighter until it reaches 4.5:1 on the sidebar.
 */
final class Accent
{
    public const DEFAULT = 'blue';

    /** Tailwind's chromatic palettes. The greys are left out: a grey accent disappears into the surfaces. */
    public const PRESETS = [
        'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose', 'red', 'orange',
        'amber', 'yellow', 'lime', 'green', 'emerald', 'teal', 'cyan', 'sky',
    ];

    /** theme.css's own fill (blue-700), the default's swatch. */
    private const DEFAULT_FILL = '#1d4ed8';

    /** What accent text sits on most: the sidebar, --phv2-sidebar. */
    private const SURFACE = '#1c1e23';

    private const MIN_CONTRAST = 4.5;

    /** Chroma of the 700 rung in Filament's generated palette, the rung a custom colour stands in for. */
    private const GENERATED_CHROMA_700 = 0.14940909090909;

    /** The configured accent: a preset name or a #rrggbb colour. */
    public static function current(): string
    {
        return self::normalize((string) config('plugged-host-theme-v2.accent', self::DEFAULT));
    }

    /** A preset name or a lower-case #rrggbb; anything else is the default. */
    public static function normalize(string $value): string
    {
        $value = strtolower(trim($value));

        if (in_array($value, self::PRESETS, true) || preg_match('/^#[0-9a-f]{6}$/', $value)) {
            return $value;
        }

        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m)) {
            return "#{$m[1]}{$m[1]}{$m[2]}{$m[2]}{$m[3]}{$m[3]}";
        }

        return self::DEFAULT;
    }

    public static function isCustom(string $accent): bool
    {
        return str_starts_with($accent, '#');
    }

    /** The fill a preset paints buttons with, for the swatches in the settings. */
    public static function swatch(string $preset): string
    {
        return $preset === self::DEFAULT
            ? self::DEFAULT_FILL
            : Color::convertToHex(Color::all()[$preset][700]);
    }

    /** CSS overriding theme.css's accent properties, or '' for the default. */
    public static function css(?string $accent = null): string
    {
        $tokens = self::tokens($accent ?? self::current());

        if ($tokens === []) {
            return '';
        }

        $properties = '';
        foreach ($tokens as $name => $value) {
            $properties .= "--{$name}:{$value};";
        }

        // theme.css has one accent literal outside its tokens: the press flash of the table
        // toolbar's icon buttons, rgba(59, 130, 246, 0.18). Same selector and !important,
        // printed later, so this one wins without rebuilding the theme.
        return ":root:root{{$properties}}"
            . '.fi-ta-header-toolbar .fi-icon-btn:not(.fi-disabled):not([disabled]):active,'
            . '.fi-ta-header-toolbar [x-data*="isUploading"] .fi-btn:active'
            . '{background-color:' . self::alpha($tokens['phv2-accent-bright'], 0.18) . ' !important}';
    }

    /**
     * Every property theme.css sets from the accent, by name without the leading dashes.
     * Empty for the default.
     *
     * @return array<string, string>
     */
    public static function tokens(string $accent): array
    {
        $accent = self::normalize($accent);

        if ($accent === self::DEFAULT) {
            return [];
        }

        $palette = self::isCustom($accent) ? self::paletteAround($accent) : Color::all()[$accent];

        $fill = self::readableFill($palette[700]);
        $hover = $fill === $palette[700] ? $palette[800] : self::shift($fill, -0.065, 0.85);

        $text = 300;
        foreach ([500, 400] as $shade) {
            if (Color::calculateContrastRatio($palette[$shade], self::SURFACE) >= self::MIN_CONTRAST) {
                $text = $shade;
                break;
            }
        }
        $bright = $palette[$text];
        $glow = $palette[$text - 100];

        return [
            'phv2-accent' => $fill,
            'phv2-accent-hover' => $hover,
            'phv2-accent-bright' => $bright,
            'phv2-accent-glow' => $glow,
            'phv2-accent-soft' => self::alpha($fill, 0.1),
            'phv2-accent-tint' => self::alpha($fill, 0.15),
            'phv2-accent-edge' => self::alpha($fill, 0.2),
            'phv2-accent-icon' => self::alpha($bright, 0.5),
            'primary-50' => $palette[50],
            'primary-100' => $palette[100],
            'primary-200' => $palette[$text - 200],
            'primary-300' => $glow,
            'primary-400' => $bright,
            'primary-500' => $palette[600],
            'primary-600' => $fill,
            'primary-700' => $hover,
            'primary-800' => $palette[900],
            'primary-900' => $palette[950],
            'primary-950' => self::shift($palette[950], -0.08, 0.8),
        ];
    }

    /**
     * Filament's generated scale for the colour's hue, at the colour's own saturation, with the
     * colour itself as the fill (700) and its hover (800) derived from it.
     *
     * @return array<int, string>
     */
    private static function paletteAround(string $hex): array
    {
        $color = Color::convertToOklch($hex);
        [, $chroma] = sscanf($color, 'oklch(%f %f %f)');
        $saturation = min(1.6, $chroma / self::GENERATED_CHROMA_700);

        $palette = array_map(
            fn (string $shade): string => self::shift($shade, 0, $saturation),
            Color::generatePalette($hex),
        );

        $palette[700] = $color;
        $palette[800] = self::shift($color, -0.065, 0.85);

        return $palette;
    }

    /** The colour, darkened until white text on it is readable. */
    private static function readableFill(string $color): string
    {
        while (Color::calculateContrastRatio($color, '#ffffff') < self::MIN_CONTRAST) {
            [$lightness] = sscanf($color, 'oklch(%f %f %f)');

            if ($lightness <= 0.1) {
                break;
            }

            $color = self::shift($color, -0.01, 1);
        }

        return $color;
    }

    private static function shift(string $color, float $lightness, float $chroma): string
    {
        [$l, $c, $h] = sscanf($color, 'oklch(%f %f %f)');

        return 'oklch(' . round(max(0, $l + $lightness), 3) . ' ' . round(max(0, $c * $chroma), 3) . ' ' . round((float) $h, 3) . ')';
    }

    private static function alpha(string $color, float $alpha): string
    {
        return substr($color, 0, -1) . " / {$alpha})";
    }
}
