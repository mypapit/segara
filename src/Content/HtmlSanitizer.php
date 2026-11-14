<?php
declare(strict_types=1);

namespace Segara\Content;

use DOMDocument;
use DOMElement;
use DOMNode;

final class HtmlSanitizer
{
    private const ALLOWED = ['p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'strong', 'em', 'del', 'blockquote', 'ul', 'ol', 'li', 'a', 'code', 'pre', 'img'];

    public function sanitize(string $html): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="segara-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $dom->getElementById('segara-root');
        if (!$root instanceof DOMElement) { return ''; }
        $this->cleanChildren($root);
        $result = '';
        foreach ($root->childNodes as $child) { $result .= $dom->saveHTML($child); }
        return $result;
    }

    private function cleanChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if (!$node instanceof DOMElement) { continue; }
            $tag = strtolower($node->tagName);
            if (!in_array($tag, self::ALLOWED, true)) { $parent->removeChild($node); continue; }
            $keep = [];
            if ($tag === 'a') {
                $href = $node->getAttribute('href');
                if ($this->safeLink($href)) { $keep['href'] = $href; }
                if ($node->getAttribute('title') !== '') { $keep['title'] = $node->getAttribute('title'); }
            } elseif ($tag === 'img') {
                $src = $node->getAttribute('src');
                if ($this->localPath($src)) { $keep['src'] = $src; }
                $keep['alt'] = $node->getAttribute('alt');
                if ($node->getAttribute('title') !== '') { $keep['title'] = $node->getAttribute('title'); }
            }
            while ($node->attributes->length > 0) {
                $attribute = $node->attributes->item(0);
                if ($attribute === null) { break; }
                $node->removeAttributeNode($attribute);
            }
            foreach ($keep as $name => $value) { $node->setAttribute($name, $value); }
            $this->cleanChildren($node);
        }
    }

    private function safeLink(string $url): bool
    {
        return $this->localPath($url) || (preg_match('/^https?:\/\//i', rawurldecode($url)) === 1 && !preg_match('/[\x00-\x20]/', $url));
    }

    private function localPath(string $url): bool
    {
        $decoded = rawurldecode($url);
        if ($decoded === '' || str_starts_with($decoded, '//') || str_contains($decoded, "\\") || preg_match('/[\x00-\x20]/', $decoded)) { return false; }
        if (str_starts_with($decoded, '/')) { return true; }
        return preg_match('/^(?:\.\.?\/)?[A-Za-z0-9._~-][A-Za-z0-9._~!$&()*+,;=:@%\/-]*(?:[?#][^\s]*)?$/', $decoded) === 1
            && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $decoded) !== 1;
    }
}
