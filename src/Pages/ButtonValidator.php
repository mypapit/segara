<?php

declare(strict_types=1);

namespace Segara\Pages;

use InvalidArgumentException;

final class ButtonValidator
{
    /** @var list<string> */
    public const ICONS = [
        'arrow-right', 'download', 'external-link', 'play', 'image', 'document', 'link', 'menu',
    ];

    /** @var list<string> */
    private const TARGET_TYPES = ['menu', 'media', 'document', 'custom'];

    /**
     * @param mixed $buttons
     * @return list<array{icon: string, label: string, target_type: string, target: string}>
     */
    public function validate(mixed $buttons): array
    {
        if (!is_array($buttons) || !array_is_list($buttons)) {
            throw new InvalidArgumentException('Buttons must be a list.');
        }
        if (count($buttons) > 3) {
            throw new InvalidArgumentException('A page may have at most three buttons.');
        }

        $validated = [];
        foreach ($buttons as $button) {
            if (!is_array($button)) {
                throw new InvalidArgumentException('Each button must be an object.');
            }
            $validated[] = $this->validateButton($button);
        }

        return $validated;
    }

    /**
     * @param array<array-key, mixed> $button
     * @return array{icon: string, label: string, target_type: string, target: string}
     */
    private function validateButton(array $button): array
    {
        $icon = $button['icon'] ?? null;
        if (!is_string($icon) || !in_array($icon, self::ICONS, true)) {
            throw new InvalidArgumentException('Button icon is not in the fixed set.');
        }

        $label = $button['label'] ?? null;
        if (!is_string($label) || trim($label) === '' || !mb_check_encoding($label, 'UTF-8')
            || mb_strlen($label) > 100 || str_contains($label, "\0")
        ) {
            throw new InvalidArgumentException('Button label must be non-empty UTF-8 of at most 100 characters.');
        }

        $type = $button['target_type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TARGET_TYPES, true)) {
            throw new InvalidArgumentException('Button target type is not supported.');
        }

        $target = $button['target'] ?? null;
        if (!is_string($target)) {
            throw new InvalidArgumentException('Button target must be a string.');
        }
        $this->validateTarget($type, $target);

        return ['icon' => $icon, 'label' => $label, 'target_type' => $type, 'target' => $target];
    }

    private function validateTarget(string $type, string $target): void
    {
        match ($type) {
            'menu', 'media' => $this->validateId($target, $type),
            'document' => LocalResource::documentPath($target),
            'custom' => LocalResource::customUrl($target),
            default => throw new InvalidArgumentException('Button target type is not supported.'),
        };
    }

    private function validateId(string $target, string $type): void
    {
        if (preg_match('/\A[0-9a-f]{32}\z/D', $target) !== 1) {
            throw new InvalidArgumentException('Button ' . $type . ' target must be a valid identifier.');
        }
    }
}
