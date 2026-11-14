<?php

declare(strict_types=1);

namespace Segara\Blocks;

use Segara\Menus\MenuRepository;
use Segara\Settings\SettingsRepository;
use Throwable;

/**
 * Gives a fresh installation something to show in its sidebar and footer.
 *
 * The seeder is deliberately conservative: it only runs against an empty blocks
 * table, and it only creates blocks whose content can be correct without asking
 * the owner anything — no invented media ids, no social links pointing nowhere.
 */
final class BlockSeeder
{
    public function __construct(
        private readonly BlockRepository $blocks,
        private readonly ?MenuRepository $menus = null,
        private readonly string $siteName = 'Segara',
        private readonly ?int $year = null,
        private readonly ?SettingsRepository $settings = null,
    ) {
    }

    /**
     * All four blocks or none: a half-seeded store would look complete to the
     * isEmpty guard above and could never be finished by a second run.
     *
     * @return list<string> the ids created, or [] when the store already has blocks
     */
    public function seed(): array
    {
        if (count($this->blocks->listAll()) > 0) {
            return [];
        }

        $ids = [];
        try {
            $menuId = $this->menuId();
            if ($menuId !== null) {
                $ids[] = $this->blocks->create([
                    'location' => 'left',
                    'type' => 'menu',
                    'settings' => ['menu_id' => $menuId],
                ])['id'];
            }

            $ids[] = $this->blocks->create([
                'location' => 'left',
                'type' => 'quick-facts',
                'settings' => [
                    'title' => 'Quick facts',
                    'facts' => [
                        ['text' => 'Founded in ' . $this->currentYear(), 'bullet' => '★'],
                        ['text' => 'Based in your city', 'bullet' => '→'],
                        ['text' => 'Contact: you@example.com', 'bullet' => '·'],
                    ],
                ],
            ])['id'];

            $ids[] = $this->blocks->create([
                'location' => 'left',
                'type' => 'rss',
                'settings' => ['title' => 'Subscribe'],
            ])['id'];

            $ids[] = $this->blocks->create([
                'location' => 'footer',
                'type' => 'copyright',
                'settings' => ['text' => '© ' . $this->currentYear() . ' ' . $this->siteName . '. All rights reserved.'],
            ])['id'];
        } catch (Throwable $exception) {
            // All four or none: a half-seeded store looks complete to the guard
            // above, so no second run could ever finish it.
            foreach ($ids as $id) {
                $this->blocks->delete($id);
            }

            throw $exception;
        }

        return $ids;
    }

    private function currentYear(): int
    {
        return $this->year ?? (int) gmdate('Y');
    }

    /**
     * A menu block without a menu renders nothing, so only seed one when a menu
     * exists. Prefer the menu the header actually shows, then the oldest.
     */
    private function menuId(): ?string
    {
        if ($this->menus === null) {
            return null;
        }
        $primary = $this->settings?->get('primary_menu_id');
        if (is_string($primary) && $primary !== '') {
            foreach ($this->menus->listMenus() as $menu) {
                if ($menu['id'] === $primary) {
                    return $primary;
                }
            }
        }
        $menus = $this->menus->listMenus();

        return $menus === [] ? null : $menus[0]['id'];
    }
}
