<?php

declare(strict_types=1);

namespace Segara\Menus;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Segara\Pages\LocalResource;

/**
 * @phpstan-type Menu array{id: string, name: string, created_at: string, updated_at: string}
 * @psalm-type Menu = array{id: string, name: string, created_at: string, updated_at: string}
 * @phpstan-type MenuRow array{id: string, menu_id: string, parent_id: ?string, target_type: string, page_id: ?string, custom_url: string, label: string, position: int}
 * @psalm-type MenuRow = array{id: string, menu_id: string, parent_id: ?string, target_type: string, page_id: ?string, custom_url: string, label: string, position: int}
 * @phpstan-type NavItem array{id: string, parent_id: ?string, label: string, url: string, position: int}
 * @psalm-type NavItem = array{id: string, parent_id: ?string, label: string, url: string, position: int}
 */
final class MenuRepository
{
    private const MAX_DEPTH = 5;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return Menu */
    public function createMenu(string $name): array
    {
        $name = $this->validateName($name);
        $id = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $statement = $this->pdo->prepare('INSERT INTO menus (id, name, created_at, updated_at) VALUES (:id, :name, :now, :now)');
        $statement->execute(['id' => $id, 'name' => $name, 'now' => $now]);

        $menu = $this->menuById($id);
        if ($menu === null) {
            throw new RuntimeException('Created menu could not be read back.');
        }

        return $menu;
    }

    /** @return list<Menu> */
    public function listMenus(): array
    {
        $statement = $this->pdo->prepare('SELECT id, name, created_at, updated_at FROM menus ORDER BY created_at ASC, name ASC');
        $statement->execute();
        $menus = [];
        while (($row = $statement->fetch()) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('Menu row has an invalid storage type.');
            }
            $menus[] = [
                'id' => $this->rowString($row, 'id'),
                'name' => $this->rowString($row, 'name'),
                'created_at' => $this->rowString($row, 'created_at'),
                'updated_at' => $this->rowString($row, 'updated_at'),
            ];
        }

        return $menus;
    }

    /**
     * @param array<string, mixed> $input
     * @return MenuRow
     */
    public function addItem(array $input): array
    {
        $menuId = $this->id($input['menu_id'] ?? null, 'Menu');
        $label = $this->validateLabel($input['label'] ?? null);
        $targetType = $input['target_type'] ?? null;
        $position = $this->position($input['position'] ?? 0);

        $pageId = null;
        $customUrl = '';
        if ($targetType === 'page') {
            $pageId = $this->id($input['page_id'] ?? null, 'Page');
            if (!$this->pageExists($pageId)) {
                throw new InvalidArgumentException('The selected page does not exist.');
            }
        } elseif ($targetType === 'custom') {
            $customUrl = $this->validateCustomUrl($input['custom_url'] ?? null);
        } else {
            throw new InvalidArgumentException('Menu item target must be a page or a custom URL.');
        }

        $parentId = null;
        if (array_key_exists('parent_id', $input) && $input['parent_id'] !== null && $input['parent_id'] !== '') {
            $parentId = $this->id($input['parent_id'], 'Parent');
            $this->assertCanBeChild($menuId, $parentId);
        }

        $id = bin2hex(random_bytes(16));
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO menu_items (id, menu_id, parent_id, target_type, page_id, custom_url, label, position) '
                . 'VALUES (:id, :menu_id, :parent_id, :target_type, :page_id, :custom_url, :label, :position)'
            );
            $statement->execute([
                'id' => $id,
                'menu_id' => $menuId,
                'parent_id' => $parentId,
                'target_type' => $targetType,
                'page_id' => $pageId,
                'custom_url' => $customUrl,
                'label' => $label,
                'position' => $position,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $item = $this->itemById($id);
        if ($item === null) {
            throw new RuntimeException('Created menu item could not be read back.');
        }

        return $item;
    }

    public function moveItem(string $id, ?string $newParentId, int $position): void
    {
        $id = $this->id($id, 'Item');
        $item = $this->itemById($id);
        if ($item === null) {
            throw new InvalidArgumentException('Menu item not found.');
        }
        if ($newParentId !== null && $newParentId !== '') {
            $newParentId = $this->id($newParentId, 'Parent');
            $parent = $this->itemById($newParentId);
            if ($parent === null) {
                throw new InvalidArgumentException('Parent menu item not found.');
            }
            if ($parent['menu_id'] !== $item['menu_id']) {
                throw new InvalidArgumentException('Parent must belong to the same menu.');
            }
            if ($newParentId === $id || $this->isDescendant($newParentId, $id)) {
                throw new InvalidArgumentException('A menu item cannot be moved under itself or its own descendant.');
            }
            if ($this->depthOf($newParentId) >= self::MAX_DEPTH) {
                throw new InvalidArgumentException('Menu nesting cannot exceed five levels.');
            }
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'UPDATE menu_items SET parent_id = :parent_id, position = :position WHERE id = :id'
            );
            $statement->execute(['parent_id' => $newParentId, 'position' => $position, 'id' => $id]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return MenuRow
     */
    public function updateItem(string $id, array $input): array
    {
        $id = $this->id($id, 'Item');
        $existing = $this->itemById($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Menu item not found.');
        }

        $label = $input['label'] ?? null;
        if (!is_string($label) || trim($label) === '') {
            throw new InvalidArgumentException('Label is required.');
        }
        if (mb_strlen($label) > 200) {
            throw new InvalidArgumentException('Label must not exceed 200 characters.');
        }

        $targetType = $input['target_type'] ?? null;
        if ($targetType !== 'page' && $targetType !== 'custom') {
            throw new InvalidArgumentException('Target type must be page or custom.');
        }

        $pageId = null;
        $customUrl = '';
        if ($targetType === 'page') {
            $candidate = $input['page_id'] ?? null;
            if (!is_string($candidate) || preg_match('/\A[0-9a-f]{32}\z/D', $candidate) !== 1) {
                throw new InvalidArgumentException('Choose a page for this menu item.');
            }
            if (!$this->pageIsLinkable($candidate)) {
                throw new InvalidArgumentException('That page cannot be linked: it does not exist or is not published.');
            }
            $pageId = $candidate;
        } else {
            $candidate = $input['custom_url'] ?? null;
            if (!is_string($candidate) || trim($candidate) === '') {
                throw new InvalidArgumentException('Enter a URL for this menu item.');
            }
            $customUrl = LocalResource::customUrl(trim($candidate));
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'UPDATE menu_items SET label = :label, target_type = :target_type, page_id = :page_id, custom_url = :custom_url '
                . 'WHERE id = :id'
            );
            $statement->execute([
                'label' => $label,
                'target_type' => $targetType,
                'page_id' => $pageId,
                'custom_url' => $customUrl,
                'id' => $id,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $updated = $this->itemById($id);
        if ($updated === null) {
            throw new RuntimeException('Updated menu item could not be read back.');
        }

        return $updated;
    }

    /** @return MenuRow|null */
    public function item(string $id): ?array
    {
        return $this->itemById($this->id($id, 'Item'));
    }

    /** A menu link may only point at a page readers can actually open. */
    private function pageIsLinkable(string $pageId): bool
    {
        $statement = $this->pdo->prepare('SELECT state FROM pages WHERE id = :id');
        $statement->execute(['id' => $pageId]);
        $state = $statement->fetchColumn();

        return $state === 'published';
    }

    /**
     * Move an item one place among its own siblings, renumbering that group.
     *
     * Positions are per (menu, parent). Swapping raw numbers across the whole
     * menu would let a child trade places with an unrelated branch, so the
     * sibling list is rewritten inside one transaction instead.
     */
    public function moveWithinSiblings(string $id, int $delta): void
    {
        $id = $this->id($id, 'Item');
        $item = $this->itemById($id);
        if ($item === null) {
            throw new InvalidArgumentException('Menu item not found.');
        }
        if ($delta !== -1 && $delta !== 1) {
            throw new InvalidArgumentException('Menu item can only move one place.');
        }

        $siblings = [];
        foreach ($this->listItems($item['menu_id']) as $candidate) {
            if ($candidate['parent_id'] === $item['parent_id']) {
                $siblings[] = $candidate['id'];
            }
        }
        $index = array_search($id, $siblings, true);
        if ($index === false) {
            throw new RuntimeException('Menu item is missing from its sibling list.');
        }
        $target = $index + $delta;
        if ($target < 0 || $target >= count($siblings)) {
            return;
        }
        $siblings[$index] = $siblings[$target];
        $siblings[$target] = $id;

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('UPDATE menu_items SET position = :position WHERE id = :id');
            foreach ($siblings as $position => $siblingId) {
                $statement->execute(['position' => $position, 'id' => $siblingId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function deleteItem(string $id): void
    {
        $id = $this->id($id, 'Item');
        $statement = $this->pdo->prepare('DELETE FROM menu_items WHERE id = :id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() !== 1) {
            throw new InvalidArgumentException('Menu item not found.');
        }
    }

    /**
     * Public navigation items for a menu: page targets resolved to their slug and
     * non-published (draft/private/trash) pages omitted.
     *
     * @return list<NavItem>
     */
    public function navigation(string $menuId): array
    {
        $menuId = $this->id($menuId, 'Menu');
        $statement = $this->pdo->prepare(
            'SELECT mi.id, mi.parent_id, mi.label, mi.target_type, mi.custom_url, mi.position, p.slug, p.state '
            . 'FROM menu_items mi LEFT JOIN pages p ON p.id = mi.page_id '
            . 'WHERE mi.menu_id = :menu_id ORDER BY mi.position ASC, mi.id ASC'
        );
        $statement->execute(['menu_id' => $menuId]);
        $items = [];
        while (($row = $statement->fetch()) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('Menu item row has an invalid storage type.');
            }
            $targetType = $this->rowString($row, 'target_type');
            if ($targetType === 'page') {
                $state = $row['state'] ?? null;
                $slug = $row['slug'] ?? null;
                if (!is_string($slug) || $state !== 'published') {
                    continue;
                }
                $url = '/' . $slug;
            } else {
                $url = $this->rowString($row, 'custom_url');
            }
            $parentId = $row['parent_id'] ?? null;
            $items[] = [
                'id' => $this->rowString($row, 'id'),
                'parent_id' => is_string($parentId) && $parentId !== '' ? $parentId : null,
                'label' => $this->rowString($row, 'label'),
                'url' => $url,
                'position' => $this->rowInt($row, 'position'),
            ];
        }

        return $items;
    }

    /** @return MenuRow|null */
    private function itemById(string $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, menu_id, parent_id, target_type, page_id, custom_url, label, position FROM menu_items WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new RuntimeException('Menu item row has an invalid storage type.');
        }
        $parentId = $row['parent_id'] ?? null;
        $pageId = $row['page_id'] ?? null;

        return [
            'id' => $this->rowString($row, 'id'),
            'menu_id' => $this->rowString($row, 'menu_id'),
            'parent_id' => is_string($parentId) && $parentId !== '' ? $parentId : null,
            'target_type' => $this->rowString($row, 'target_type'),
            'page_id' => is_string($pageId) && $pageId !== '' ? $pageId : null,
            // Historical rows may hold NULL for a page target; treat it as ''.
            'custom_url' => is_string($row['custom_url'] ?? null) ? $row['custom_url'] : '',
            'label' => $this->rowString($row, 'label'),
            'position' => $this->rowInt($row, 'position'),
        ];
    }

    /** @return list<MenuRow> */
    public function listItems(string $menuId): array
    {
        $menuId = $this->id($menuId, 'Menu');
        $statement = $this->pdo->prepare(
            'SELECT id, menu_id, parent_id, target_type, page_id, custom_url, label, position '
            . 'FROM menu_items WHERE menu_id = :menu_id ORDER BY position ASC, id ASC'
        );
        $statement->execute(['menu_id' => $menuId]);
        $items = [];
        while (($row = $statement->fetch()) !== false) {
            if (!is_array($row)) {
                throw new RuntimeException('Menu item row has an invalid storage type.');
            }
            $parentId = $row['parent_id'] ?? null;
            $pageId = $row['page_id'] ?? null;
            $items[] = [
                'id' => $this->rowString($row, 'id'),
                'menu_id' => $this->rowString($row, 'menu_id'),
                'parent_id' => is_string($parentId) && $parentId !== '' ? $parentId : null,
                'target_type' => $this->rowString($row, 'target_type'),
                'page_id' => is_string($pageId) && $pageId !== '' ? $pageId : null,
                'custom_url' => is_string($row['custom_url'] ?? null) ? $row['custom_url'] : '',
                'label' => $this->rowString($row, 'label'),
                'position' => $this->rowInt($row, 'position'),
            ];
        }

        return $items;
    }

    /** @return Menu|null */
    private function menuById(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name, created_at, updated_at FROM menus WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        if (!is_array($row)) {
            throw new RuntimeException('Menu row has an invalid storage type.');
        }

        return [
            'id' => $this->rowString($row, 'id'),
            'name' => $this->rowString($row, 'name'),
            'created_at' => $this->rowString($row, 'created_at'),
            'updated_at' => $this->rowString($row, 'updated_at'),
        ];
    }

    private function assertCanBeChild(string $menuId, string $parentId): void
    {
        $parent = $this->itemById($parentId);
        if ($parent === null) {
            throw new InvalidArgumentException('Parent menu item not found.');
        }
        if ($parent['menu_id'] !== $menuId) {
            throw new InvalidArgumentException('Parent must belong to the same menu.');
        }
        if ($this->depthOf($parentId) >= self::MAX_DEPTH) {
            throw new InvalidArgumentException('Menu nesting cannot exceed five levels.');
        }
    }

    private function depthOf(string $itemId): int
    {
        $depth = 0;
        $current = $itemId;
        $seen = [];
        while ($current !== null) {
            if (isset($seen[$current]) || $depth > self::MAX_DEPTH) {
                return self::MAX_DEPTH;
            }
            $seen[$current] = true;
            $statement = $this->pdo->prepare('SELECT parent_id FROM menu_items WHERE id = :id');
            $statement->execute(['id' => $current]);
            $parent = $statement->fetchColumn();
            $current = is_string($parent) && $parent !== '' ? $parent : null;
            $depth++;
        }

        return $depth;
    }

    private function isDescendant(string $itemId, string $ancestorId): bool
    {
        $current = $itemId;
        $seen = [];
        while ($current !== null) {
            if (isset($seen[$current])) {
                return false;
            }
            $seen[$current] = true;
            $statement = $this->pdo->prepare('SELECT parent_id FROM menu_items WHERE id = :id');
            $statement->execute(['id' => $current]);
            $parent = $statement->fetchColumn();
            if ($parent === $ancestorId) {
                return true;
            }
            $current = is_string($parent) && $parent !== '' ? $parent : null;
        }

        return false;
    }

    private function pageExists(string $id): bool
    {
        $statement = $this->pdo->prepare('SELECT id FROM pages WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn() !== false;
    }

    private function id(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/\A[0-9a-f]{32}\z/D', $value) !== 1) {
            throw new InvalidArgumentException($label . ' must be a valid identifier.');
        }

        return $value;
    }

    private function validateName(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || !mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value) > 200 || str_contains($value, "\0")
        ) {
            throw new InvalidArgumentException('Menu name must be non-empty UTF-8 of at most 200 characters.');
        }

        return $value;
    }

    private function validateLabel(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || !mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value) > 200 || str_contains($value, "\0")
        ) {
            throw new InvalidArgumentException('Menu item label must be non-empty UTF-8 of at most 200 characters.');
        }

        return $value;
    }

    private function validateCustomUrl(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('Custom URL is required for a custom target.');
        }

        return LocalResource::customUrl($value);
    }

    private function position(mixed $value): int
    {
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidArgumentException('Position must be an integer.');
        }
        $position = (int) $value;
        if ($position < 0 || $position > 100000) {
            throw new InvalidArgumentException('Position must be between 0 and 100000.');
        }

        return $position;
    }

    /** @param array<array-key, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('Menu column has an invalid storage type.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $row */
    private function rowInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new RuntimeException('Menu column has an invalid storage type.');
        }

        return (int) $value;
    }
}
