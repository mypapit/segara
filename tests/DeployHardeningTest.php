<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The deploy templates are the only thing between a wrong DocumentRoot and the
 * SQLite database. These tests read the shipped files, extract the deny
 * patterns, and run them: a broken regex fails here instead of on a server.
 */
final class DeployHardeningTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    private const DATA_FILES = [
        'store/segara.sqlite',
        'store/segara.sqlite-wal',
        'store/segara.sqlite-shm',
        'store/segara.sqlite-journal',
        'store/backups/segara-2026-10-25.db',
        'uploads/backup.db3',
    ];

    private const PUBLIC_FILES = [
        'index.php',
        'admin.php',
        'media.php',
        'assets/admin.css',
        'assets/site.js',
        'rss.xml',
        'sitemap.xml',
        'uploads-notice.md',
        'data.json',
    ];

    public function testTheNginxTemplateDeniesDatabaseFilesByPattern(): void
    {
        $pattern = $this->nginxPattern();

        foreach (self::DATA_FILES as $file) {
            self::assertMatchesRegularExpression($pattern, $file, $file . ' must be denied');
        }
        foreach (self::PUBLIC_FILES as $file) {
            self::assertDoesNotMatchRegularExpression($pattern, $file, $file . ' must stay servable');
        }
    }

    public function testBothTemplatesCarryTheSameExtensionPattern(): void
    {
        $nginx = substr($this->nginxPattern(), 1, -2);          // strip the "#...#i" delimiters
        $apache = str_replace('(?i)', '', $this->apacheFilesMatchPattern());

        self::assertSame($nginx, $apache, 'the nginx and Apache templates must not drift apart');
    }

    public function testTheNginxTemplateDeniesTheDataDirectories(): void
    {
        $conf = $this->read('deploy/nginx-php-fpm.conf');

        foreach (['/store/', '/uploads/', '/settings/'] as $prefix) {
            $block = 'location ^~ ' . $prefix . ' {';
            self::assertStringContainsString($block, $conf, $prefix . ' must have a location block');
            $after = substr($conf, (int) strpos($conf, $block) + strlen($block), 80);
            self::assertStringContainsString('return 404;', $after, $prefix . ' must return 404');
        }
    }

    public function testTheApacheTemplateDeniesTheDataDirectories(): void
    {
        $conf = $this->read('deploy/apache-vhost.conf');

        self::assertStringContainsString(
            '<DirectoryMatch "^/var/www/segara/(store|uploads|settings)(/|$)">',
            $conf,
            'the Apache template must deny the three data directories',
        );
        self::assertStringContainsString('Require all denied', $conf);
        self::assertStringContainsString('Options -Indexes', $conf);
    }

    public function testTheDataDirectoryHtaccessDeniesEverything(): void
    {
        $htaccess = $this->read('deploy/apache-dir-deny.htaccess');

        self::assertStringContainsString('Require all denied', $htaccess, 'Apache 2.4');
        self::assertStringContainsString('Deny from all', $htaccess, 'Apache 2.2 fallback');
        self::assertStringContainsString('Options -Indexes', $htaccess);
    }

    public function testTheAppRootHtaccessDeniesOnlyDatabaseFiles(): void
    {
        $htaccess = $this->read('deploy/apache-data-deny.htaccess');

        self::assertStringContainsString('\.(?:sqlite3?|db3?)(?:-(?:wal|shm|journal))?$', $htaccess);
        // A bare `Require all denied` in the app root would take the whole site down.
        self::assertDoesNotMatchRegularExpression(
            '/<IfModule mod_authz_core\.c>\s*Require all denied/',
            $htaccess,
            'the root .htaccess must not deny the site, only the data',
        );
    }

    public function testInstallWritesTheGuardsAfterTheOwnershipFix(): void
    {
        $script = $this->read('deploy/install.sh');

        foreach (['$DST/.htaccess', '$DST/store/.htaccess', '$DST/uploads/.htaccess'] as $target) {
            self::assertStringContainsString($target, $script, $target . ' must be installed');
        }

        // Written after the chown, so they stay root-owned and the web server,
        // which owns uploads/, cannot rewrite the rule that guards it.
        $chown = strpos($script, 'chown -R');
        $write = strpos($script, '$DST/uploads/.htaccess');
        self::assertIsInt($chown);
        self::assertIsInt($write);
        self::assertLessThan($write, $chown, 'the guards must be written after the chown');
    }

    public function testTheNginxGuideDocumentsTheDenyRules(): void
    {
        $guide = $this->read('deploy/nginx.md');

        self::assertStringContainsString('Deny the data directories', $guide);
        self::assertStringContainsString('/store/segara.sqlite', $guide);
        self::assertStringContainsString('deploy/security.md', $guide);
    }

    public function testTheAdvisoryExplainsTheThreatAndBothServers(): void
    {
        $advisory = $this->read('deploy/security.md');

        self::assertStringContainsString('DocumentRoot', $advisory);
        self::assertStringContainsString('store/segara.sqlite', $advisory);
        self::assertStringContainsString('nginx', $advisory);
        self::assertStringContainsString('Apache', $advisory);
        self::assertStringContainsString('return 404', $advisory);
    }

    private function nginxPattern(): string
    {
        foreach (explode("\n", $this->read('deploy/nginx-php-fpm.conf')) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'location ~* \\')) {
                return '#' . rtrim(substr($line, strlen('location ~* ')), ' {') . '#i';
            }
        }

        self::fail('deploy/nginx-php-fpm.conf carries no database deny regex.');
    }

    private function apacheFilesMatchPattern(): string
    {
        if (preg_match('/<FilesMatch "([^"]+)">/', $this->read('deploy/apache-vhost.conf'), $match) !== 1) {
            self::fail('deploy/apache-vhost.conf carries no FilesMatch deny rule.');
        }

        return $match[1];
    }

    private function read(string $relative): string
    {
        $path = self::ROOT . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
