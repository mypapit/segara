<?php

declare(strict_types=1);

namespace Segara\Blocks;

/**
 * The block catalogue: one row per type, with the human wording and the
 * locations where the type makes sense. The admin form is built from this, so
 * a type cannot exist in the renderer without being describable here.
 */
final class BlockTypes
{
    /**
     * @return array<string, array{label: string, description: string, locations: list<string>}>
     */
    public static function all(): array
    {
        return [
            'menu' => [
                'label' => 'Menu',
                'description' => 'Repeats one of your menus.',
                'locations' => ['left', 'right'],
            ],
            'images' => [
                'label' => 'Images',
                'description' => 'A small grid of uploaded images.',
                'locations' => ['left', 'right'],
            ],
            'rss' => [
                'label' => 'RSS link',
                'description' => 'A subscribe button for this site’s feed.',
                'locations' => ['left', 'right'],
            ],
            'social' => [
                'label' => 'Social links',
                'description' => 'Icons linking to your profiles.',
                'locations' => ['left', 'right', 'footer'],
            ],
            'external-links' => [
                'label' => 'External links',
                'description' => 'A short list of links.',
                'locations' => ['left', 'right', 'footer'],
            ],
            'quick-facts' => [
                'label' => 'Quick facts',
                'description' => 'A short bulleted list.',
                'locations' => ['left', 'right'],
            ],
            'custom-inline' => [
                'label' => 'Short text',
                'description' => 'A sentence or two, with simple formatting only.',
                'locations' => ['left', 'right', 'footer'],
            ],
            'copyright' => [
                'label' => 'Copyright',
                'description' => 'Your own copyright wording.',
                'locations' => ['footer'],
            ],
            'address' => [
                'label' => 'Address',
                'description' => 'A physical address.',
                'locations' => ['footer'],
            ],
            'blurb' => [
                'label' => 'Biography',
                'description' => 'A short biography with an optional portrait.',
                'locations' => ['footer'],
            ],
        ];
    }

    public static function label(string $type): string
    {
        return self::all()[$type]['label'] ?? ucfirst($type);
    }

    public static function description(string $type): string
    {
        return self::all()[$type]['description'] ?? '';
    }

    /** @return list<string> */
    public static function forLocation(string $location): array
    {
        $types = [];
        foreach (self::all() as $type => $meta) {
            if (in_array($location, $meta['locations'], true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    public static function allows(string $type, string $location): bool
    {
        return in_array($type, self::forLocation($location), true);
    }
}
