<?php

declare(strict_types=1);

namespace Segara\Feeds;

use InvalidArgumentException;
use XMLWriter;

final class Xml
{
    private XMLWriter $writer;

    public function __construct()
    {
        $this->writer = new XMLWriter();
        $this->writer->openMemory();
        $this->writer->startDocument('1.0', 'UTF-8');
    }

    /**
     * Declare a browser stylesheet. It must be written before the root element:
     * an xml-stylesheet declaration anywhere else is ignored, and the reader is
     * left looking at raw angle brackets.
     */
    public function stylesheet(string $href): self
    {
        if (preg_match('/\A[A-Za-z0-9._\/-]+\z/D', $href) !== 1) {
            throw new InvalidArgumentException('Stylesheet href must be a plain relative path.');
        }
        $this->writer->writePi('xml-stylesheet', 'type="text/xsl" href="' . $href . '"');

        return $this;
    }

    public function start(string $name): self
    {
        $this->writer->startElement($name);

        return $this;
    }

    public function end(): self
    {
        $this->writer->endElement();

        return $this;
    }

    public function attribute(string $name, string $value): self
    {
        $this->writer->writeAttribute($name, $value);

        return $this;
    }

    public function element(string $name, string $content): self
    {
        $this->writer->writeElement($name, $content);

        return $this;
    }

    public function text(string $content): self
    {
        $this->writer->text($content);

        return $this;
    }

    public function cdata(string $content): self
    {
        if ($content === '') {
            return $this;
        }
        if (str_contains($content, ']]>')) {
            $this->writer->text($content);

            return $this;
        }
        $this->writer->writeCdata($content);

        return $this;
    }

    public function toString(): string
    {
        $this->writer->endDocument();

        return $this->writer->outputMemory();
    }
}
