<?php

declare(strict_types=1);

namespace Segara\Blocks;

use DOMDocument;
use DOMElement;
use DOMNode;

final class InlineHtml
{
    private const ALLOWED = ['a', 'strong', 'em', 'b', 'i', 'u', 's', 'code', 'span', 'small', 'mark', 'sub', 'sup', 'br'];

    public static function sanitize(string $html): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="segara-inline">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $dom->getElementById('segara-inline');
        if (!$root instanceof DOMElement) {
            return '';
        }
        self::cleanChildren($root);
        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }

        return $result;
    }

    private static function cleanChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($node->tagName);
            if (!in_array($tag, self::ALLOWED, true)) {
                $parent->removeChild($node);
                continue;
            }
            $keep = [];
            if ($tag === 'a') {
                $href = $node->getAttribute('href');
                if (self::safeLink($href)) {
                    $keep['href'] = $href;
                }
                if ($node->getAttribute('title') !== '') {
                    $keep['title'] = $node->getAttribute('title');
                }
            }
            while ($node->attributes->length > 0) {
                $attribute = $node->attributes->item(0);
                if ($attribute === null) {
                    break;
                }
                $node->removeAttributeNode($attribute);
            }
            foreach ($keep as $name => $value) {
                $node->setAttribute($name, $value);
            }
            self::cleanChildren($node);
        }
    }

    private static function safeLink(string $url): bool
    {
        $decoded = rawurldecode($url);
        if ($decoded === '' || str_contains($decoded, '\\') || preg_match('/[\x00-\x20]/', $decoded) === 1) {
            return false;
        }
        if (str_starts_with($decoded, '/')) {
            return !str_starts_with($decoded, '//');
        }

        return preg_match('/^https?:\/\//i', $decoded) === 1;
    }
}
