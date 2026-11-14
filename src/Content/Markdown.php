<?php
declare(strict_types=1);

namespace Segara\Content;

use Parsedown;

final class Markdown
{
    public function __construct(private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer())
    {
    }

    public function render(string $source): string
    {
        $parser = new Parsedown();
        $parser->setSafeMode(true);
        $parser->setMarkupEscaped(true);
        $parsed = $parser->text($source);
        return is_string($parsed) ? $this->sanitizer->sanitize($parsed) : '';
    }
}
