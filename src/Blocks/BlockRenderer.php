<?php

declare(strict_types=1);

namespace Segara\Blocks;

use Segara\Config;
use Segara\Media\MediaRepository;
use Segara\Menus\MenuRepository;
use Segara\Support\Html;

final class BlockRenderer
{
    /** @var array<string, string> accessible names for the 16 providers plus custom */
    private const PROVIDER_LABELS = [
        'x' => 'X',
        'github' => 'GitHub',
        'twitch' => 'Twitch',
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'threads' => 'Threads',
        'linkedin' => 'LinkedIn',
        'youtube' => 'YouTube',
        'tiktok' => 'TikTok',
        'reddit' => 'Reddit',
        'bluesky' => 'Bluesky',
        'mastodon' => 'Mastodon',
        'researchgate' => 'ResearchGate',
        'orcid' => 'ORCID',
        'padlet' => 'Padlet',
        'linktree' => 'Linktree',
        'custom' => 'Website',
    ];
    public function __construct(
        private readonly Config $config,
        private readonly MenuRepository $menus,
        private readonly ?MediaRepository $media = null,
    ) {
    }

    /** @param array{id: string, location: string, position: int, type: string, settings_json: string, revision: int} $block */
    public function render(array $block): string
    {
        $settings = json_decode($block['settings_json'], true);

        return match ($block['type']) {
            'menu' => $this->menu(is_array($settings) ? $settings : []),
            'images' => $this->images(is_array($settings) ? $settings : []),
            'rss' => $this->rss(is_array($settings) ? $settings : []),
            'social' => $this->social(is_array($settings) ? $settings : []),
            'external-links' => $this->externalLinks(is_array($settings) ? $settings : []),
            'quick-facts' => $this->quickFacts(is_array($settings) ? $settings : []),
            'custom-inline' => $this->customInline(is_array($settings) ? $settings : []),
            'copyright' => $this->copyright(is_array($settings) ? $settings : []),
            'address' => $this->address(is_array($settings) ? $settings : []),
            'blurb' => $this->blurb(is_array($settings) ? $settings : []),
            default => '',
        };
    }

    /** @param list<array{id: string, location: string, position: int, type: string, settings_json: string, revision: int}> $blocks */
    public function renderAll(array $blocks): string
    {
        $html = '';
        foreach ($blocks as $block) {
            $html .= $this->render($block);
        }

        return $html;
    }

    /** @param array<array-key, mixed> $settings */
    private function menu(array $settings): string
    {
        $menuId = $settings['menu_id'] ?? null;
        if (!is_string($menuId) || $menuId === '') {
            return '';
        }
        $items = $this->menus->navigation($menuId);
        if (count($items) === 0) {
            return '';
        }
        ob_start();
        require dirname(__DIR__, 2) . '/themes/shared/navigation.php';
        $html = ob_get_clean();
        // navigation.php returns $navHtml; an empty string means nothing was reachable.
        $markup = is_string($html) && trim($html) !== '' ? $html : (isset($navHtml) && is_string($navHtml) ? $navHtml : '');
        if (trim($markup) === '') {
            return '';
        }

        return '<nav class="block block--menu">' . $markup . '</nav>';
    }

    /** @param array<array-key, mixed> $settings */
    private function images(array $settings): string
    {
        $title = is_string($settings['title'] ?? null) ? $settings['title'] : '';
        $imageIds = $settings['image_ids'] ?? [];
        if (!is_array($imageIds) || count($imageIds) === 0) {
            return '';
        }
        $html = '<div class="block block--images">';
        if ($title !== '') {
            $html .= '<h2>' . Html::escape($title) . '</h2>';
        }
        foreach ($imageIds as $imageId) {
            if (!is_string($imageId)) {
                continue;
            }
            $alt = '';
            $image = $this->media?->getById($imageId);
            if (is_array($image) && $image['decorative'] !== 1) {
                $alt = trim($image['alt_text']);
            }
            $html .= '<img src="' . Html::escape($this->mediaUrl($imageId)) . '" alt="' . Html::escape($alt) . '" loading="lazy">';
        }
        $html .= '</div>';

        return $html;
    }

    /** @param array<array-key, mixed> $settings */
    private function rss(array $settings): string
    {
        $title = is_string($settings['title'] ?? null) && $settings['title'] !== '' ? $settings['title'] : 'RSS feed';
        $url = $this->config->basePath . '/rss.xml';

        return '<div class="block block--rss"><a class="rss-chicklet" href="' . Html::escape($url) . '">'
            . $this->icon('rss') . '<span>' . Html::escape($title) . '</span></a></div>';
    }

    /** @param array<array-key, mixed> $settings */
    private function social(array $settings): string
    {
        $title = is_string($settings['title'] ?? null) ? $settings['title'] : '';
        $networks = $settings['networks'] ?? [];
        if (!is_array($networks) || count($networks) === 0) {
            return '';
        }
        $html = '<div class="block block--social">';
        if ($title !== '') {
            $html .= '<h2>' . Html::escape($title) . '</h2>';
        }
        $html .= '<ul>';
        foreach ($networks as $network) {
            if (!is_array($network)) {
                continue;
            }
            $provider = is_string($network['provider'] ?? null) ? $network['provider'] : 'custom';
            $url = is_string($network['url'] ?? null) ? $network['url'] : '#';
            $label = self::PROVIDER_LABELS[$provider] ?? ucfirst($provider);
            // rel="me" is the identity signal for a profile; a plain website link is not one.
            $rel = $provider === 'custom' ? '' : ' rel="me"';
            $html .= '<li><a href="' . Html::escape($url) . '" aria-label="' . Html::escape($label) . '"' . $rel . '>'
                . $this->icon($provider) . '</a></li>';
        }
        $html .= '</ul></div>';

        return $html;
    }

    /** @param array<array-key, mixed> $settings */
    private function externalLinks(array $settings): string
    {
        $title = is_string($settings['title'] ?? null) ? $settings['title'] : '';
        $links = $settings['links'] ?? [];
        if (!is_array($links) || count($links) === 0) {
            return '';
        }
        $html = '<div class="block block--links">';
        if ($title !== '') {
            $html .= '<h2>' . Html::escape($title) . '</h2>';
        }
        $html .= '<ul>';
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $label = is_string($link['label'] ?? null) ? $link['label'] : '';
            $url = is_string($link['url'] ?? null) ? $link['url'] : '#';
            $html .= '<li><a href="' . Html::escape($url) . '">' . Html::escape($label) . '</a></li>';
        }
        $html .= '</ul></div>';

        return $html;
    }

    /** @param array<array-key, mixed> $settings */
    private function quickFacts(array $settings): string
    {
        $title = is_string($settings['title'] ?? null) ? $settings['title'] : '';
        $facts = $settings['facts'] ?? [];
        if (!is_array($facts) || count($facts) === 0) {
            return '';
        }
        $html = '<div class="block block--facts">';
        if ($title !== '') {
            $html .= '<h2>' . Html::escape($title) . '</h2>';
        }
        $html .= '<ul>';
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $text = is_string($fact['text'] ?? null) ? $fact['text'] : '';
            $bullet = is_string($fact['bullet'] ?? null) ? $fact['bullet'] : '';
            $html .= '<li' . ($bullet !== '' ? ' data-bullet="' . Html::escape($bullet) . '"' : '') . '>' . Html::escape($text) . '</li>';
        }
        $html .= '</ul></div>';

        return $html;
    }

    /** @param array<array-key, mixed> $settings */
    private function customInline(array $settings): string
    {
        $title = is_string($settings['title'] ?? null) ? $settings['title'] : '';
        $html = is_string($settings['html'] ?? null) ? $settings['html'] : '';
        if ($html === '') {
            return '';
        }
        $out = '<div class="block block--custom">';
        if ($title !== '') {
            $out .= '<h2>' . Html::escape($title) . '</h2>';
        }
        $out .= $html . '</div>';

        return $out;
    }

    /** @param array<array-key, mixed> $settings */
    private function copyright(array $settings): string
    {
        $text = is_string($settings['text'] ?? null) ? $settings['text'] : '';
        if ($text === '') {
            return '';
        }

        return '<div class="block block--copyright">' . Html::escape($text) . '</div>';
    }

    /** @param array<array-key, mixed> $settings */
    private function address(array $settings): string
    {
        $text = is_string($settings['text'] ?? null) ? $settings['text'] : '';
        if ($text === '') {
            return '';
        }

        return '<address class="block block--address">' . Html::escape($text) . '</address>';
    }

    /** @param array<array-key, mixed> $settings */
    private function blurb(array $settings): string
    {
        $title = is_string($settings['title'] ?? null) ? $settings['title'] : '';
        $text = is_string($settings['text'] ?? null) ? $settings['text'] : '';
        $imageId = is_string($settings['image_id'] ?? null) ? $settings['image_id'] : '';
        if ($text === '') {
            return '';
        }
        $html = '<div class="block block--blurb">';
        if ($title !== '') {
            $html .= '<h2>' . Html::escape($title) . '</h2>';
        }
        if ($imageId !== '' && $this->media !== null) {
            $image = $this->media->getById($imageId);
            // An image nobody can describe is worse than no image: skip it.
            if (is_array($image)) {
                $alt = trim($image['alt_text']);
                if ($alt !== '' || $image['decorative'] === 1) {
                    $html .= '<figure class="block__figure"><img src="' . Html::escape($this->mediaUrl($imageId))
                        . '" alt="' . Html::escape($alt) . '" width="72" height="72" loading="lazy"></figure>';
                }
            }
        }
        $html .= '<p>' . Html::escape($text) . '</p></div>';

        return $html;
    }

    private function mediaUrl(string $id): string
    {
        return $this->config->basePath . '/media.php?id=' . $id;
    }

    private function icon(string $provider): string
    {
        $href = $this->config->basePath . '/assets/icons.svg#icon-' . $provider;

        return '<svg class="icon" aria-hidden="true" focusable="false"><use href="' . Html::escape($href) . '"></use></svg>';
    }
}
