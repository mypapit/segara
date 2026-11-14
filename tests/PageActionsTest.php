<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PDO;
use Segara\Pages\ButtonValidator;
use Segara\Pages\LocalResource;
use Segara\Pages\PageRepository;
use Segara\Storage\Database;
use Segara\Storage\Migrator;
use Tests\Support\TestCase;

final class PageActionsTest extends TestCase
{
    private PDO $pdo;
    private PageRepository $pages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = Database::connect($this->sqlitePath);
        (new Migrator($this->pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $this->pages = new PageRepository($this->pdo);
    }

    public function testButtonValidatorAcceptsUpToThreeValidButtons(): void
    {
        $validator = new ButtonValidator();
        $buttons = [
            ['icon' => 'arrow-right', 'label' => 'Read more', 'target_type' => 'custom', 'target' => 'https://example.com/read'],
            ['icon' => 'download', 'label' => 'Brochure', 'target_type' => 'document', 'target' => '/documents/brochure.pdf'],
            ['icon' => 'image', 'label' => 'Gallery', 'target_type' => 'media', 'target' => str_repeat('a', 32)],
        ];

        $validated = $validator->validate($buttons);

        self::assertCount(3, $validated);
        self::assertSame('Read more', $validated[0]['label']);
    }

    public function testButtonValidatorRejectsFourthButton(): void
    {
        $buttons = [];
        for ($i = 0; $i < 4; $i++) {
            $buttons[] = ['icon' => 'link', 'label' => "Button $i", 'target_type' => 'custom', 'target' => '/path'];
        }

        try {
            (new ButtonValidator())->validate($buttons);
            self::fail('A fourth button was accepted.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('three', $e->getMessage());
        }
    }

    public function testButtonValidatorRejectsUnsafeTargetsAndInvalidIcons(): void
    {
        $invalid = [
            [['icon' => 'evil', 'label' => 'x', 'target_type' => 'custom', 'target' => '/ok']],
            [['icon' => 'link', 'label' => 'x', 'target_type' => 'bogus', 'target' => '/ok']],
            [['icon' => 'link', 'label' => 'x', 'target_type' => 'custom', 'target' => 'javascript:alert(1)']],
            [['icon' => 'link', 'label' => 'x', 'target_type' => 'custom', 'target' => 'data:text/html;base64,xx']],
            [['icon' => 'link', 'label' => 'x', 'target_type' => 'document', 'target' => '../secret.pdf']],
            [['icon' => 'link', 'label' => 'x', 'target_type' => 'document', 'target' => '/a/../b']],
            [['icon' => 'link', 'label' => 'x', 'target_type' => 'media', 'target' => 'not-an-id']],
            [['icon' => 'link', 'label' => 'x', 'target_type' => 'menu', 'target' => 'not-an-id']],
            [['icon' => 'link', 'label' => '', 'target_type' => 'custom', 'target' => '/ok']],
        ];

        $rejected = 0;
        foreach ($invalid as $buttons) {
            try {
                (new ButtonValidator())->validate($buttons);
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }
        self::assertSame(count($invalid), $rejected);
    }

    public function testLocalResourceAcceptsVideoAndRejectsTraversalAndWrongType(): void
    {
        self::assertSame('/videos/intro.mp4', LocalResource::videoPath('/videos/intro.mp4'));
        self::assertSame('/videos/clip.webm', LocalResource::videoPath('/videos/clip.webm'));
        self::assertNull(LocalResource::videoPath(''));
        self::assertNull(LocalResource::videoPath(null));

        $rejected = 0;
        foreach (['/videos/intro.avi', '/videos/../secret.mp4', 'C:\\videos\\x.mp4', '/videos//x.mp4', '/videos/x?raw=1'] as $bad) {
            try {
                LocalResource::videoPath($bad);
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }
        self::assertSame(5, $rejected);
    }

    public function testRepositoryPersistsButtonsAndVideoAndRejectsInvalidInput(): void
    {
        $buttons = [
            ['icon' => 'play', 'label' => 'Watch', 'target_type' => 'custom', 'target' => 'https://example.com/watch'],
        ];
        $page = $this->pages->create([
            'title' => 'With Actions',
            'state' => 'published',
            'author_name' => 'Site Administrator',
            'buttons' => $buttons,
            'video_path' => '/videos/intro.mp4',
        ]);

        self::assertSame('/videos/intro.mp4', $page['video_path']);
        self::assertSame($buttons, json_decode($page['buttons_json'], true));

        $thrown = false;
        try {
            $this->pages->create([
                'title' => 'Bad Buttons',
                'state' => 'published',
                'author_name' => 'Site Administrator',
                'buttons' => [['icon' => 'nope', 'label' => 'x', 'target_type' => 'custom', 'target' => '/x']],
            ]);
        } catch (InvalidArgumentException) {
            $thrown = true;
        }
        self::assertTrue($thrown);
    }

    public function testUpdatePreservesButtonsAndVideoWhenNotChanged(): void
    {
        $buttons = [['icon' => 'link', 'label' => 'Visit', 'target_type' => 'custom', 'target' => 'https://example.com']];
        $page = $this->pages->create([
            'title' => 'Original',
            'state' => 'draft',
            'author_name' => 'Site Administrator',
            'buttons' => $buttons,
            'video_path' => '/videos/a.mp4',
        ]);

        $updated = $this->pages->update($page['id'], 1, ['title' => 'Renamed']);

        self::assertSame($buttons, json_decode($updated['buttons_json'], true));
        self::assertSame('/videos/a.mp4', $updated['video_path']);
    }
}
