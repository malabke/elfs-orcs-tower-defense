<?php

namespace JumPHP\Ide\Debug;

/**
 * An element of a DBGp message (Xdebug's XML), read without any PHP extension: the IDE's own PHP (StaticPHP's micro)
 * has no SimpleXML, DOM nor ext-xml. Enough XML for DBGp: elements, attributes, text, CDATA, entities, comments and
 * the declaration. Names keep their prefix (xdebug:message); child() / children() also match the local name.
 */
final class XmlNode
{
    /** @var list<XmlNode> */
    public array $children = [];
    public string $text = '';

    /** @param array<string, string> $attributes */
    public function __construct(public string $name, public array $attributes = [])
    {
    }

    /** The root element of a document, or null when it is not well formed. */
    public static function parse(string $xml): ?self
    {
        $length = strlen($xml);
        $at = 0;
        /** @var list<XmlNode> $open */
        $open = [];
        $root = null;
        while ($at < $length) {
            $lt = strpos($xml, '<', $at);
            $text = substr($xml, $at, ($lt === false ? $length : $lt) - $at);
            if ($open && $text !== '') {
                $open[count($open) - 1]->text .= self::decode($text);
            }
            if ($lt === false) {
                break;
            }
            if (substr_compare($xml, '<![CDATA[', $lt, 9) === 0) {
                $end = strpos($xml, ']]>', $lt + 9);
                if ($end === false) {
                    return null;
                }
                if ($open) {
                    $open[count($open) - 1]->text .= substr($xml, $lt + 9, $end - $lt - 9);
                }
                $at = $end + 3;
                continue;
            }
            if (substr_compare($xml, '<!--', $lt, 4) === 0) {
                $end = strpos($xml, '-->', $lt + 4);
                if ($end === false) {
                    return null;
                }
                $at = $end + 3;
                continue;
            }
            if (($xml[$lt + 1] ?? '') === '?' || ($xml[$lt + 1] ?? '') === '!') {
                $end = strpos($xml, '>', $lt);
                if ($end === false) {
                    return null;
                }
                $at = $end + 1;
                continue;
            }
            if (($xml[$lt + 1] ?? '') === '/') {
                $end = strpos($xml, '>', $lt);
                if ($end === false || !$open) {
                    return null;
                }
                $name = trim(substr($xml, $lt + 2, $end - $lt - 2));
                $node = array_pop($open);
                if ($node->name !== $name) {
                    return null;
                }
                if (!$open) {
                    $root ??= $node;
                }
                $at = $end + 1;
                continue;
            }
            // a start tag: its name, its attributes (quoted values may hold '>'), maybe self-closing
            if (!preg_match('/\G<([^\s\/>]+)((?:\s+[^\s=\/>]+\s*=\s*(?:"[^"]*"|\'[^\']*\'))*)\s*(\/?)>/', $xml, $m, 0, $lt)) {
                return null;
            }
            $attributes = [];
            if (preg_match_all('/([^\s=]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $m[2], $pairs, PREG_SET_ORDER)) {
                foreach ($pairs as $pair) {
                    $attributes[$pair[1]] = self::decode(($pair[3] ?? '') !== '' ? $pair[3] : $pair[2]);
                }
            }
            $node = new self($m[1], $attributes);
            if ($open) {
                $open[count($open) - 1]->children[] = $node;
            }
            if ($m[3] === '/') {
                if (!$open) {
                    $root ??= $node;
                }
            } else {
                $open[] = $node;
            }
            $at = $lt + strlen($m[0]);
        }
        return $open ? null : $root;
    }

    public function localName(): string
    {
        $colon = strrpos($this->name, ':');
        return $colon === false ? $this->name : substr($this->name, $colon + 1);
    }

    public function attr(string $name, ?string $default = null): ?string
    {
        return $this->attributes[$name] ?? $default;
    }

    public function has(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    /** @return list<XmlNode> the children of that name (or local name) */
    public function children(string $name): array
    {
        return array_values(array_filter($this->children, fn (self $c) => $c->name === $name || $c->localName() === $name));
    }

    public function child(string $name): ?self
    {
        return $this->children($name)[0] ?? null;
    }

    private static function decode(string $text): string
    {
        if (!str_contains($text, '&')) {
            return $text;
        }
        return (string)preg_replace_callback('/&(#x[0-9a-fA-F]+|#\d+|lt|gt|amp|quot|apos);/', function (array $m): string {
            return match ($m[1]) {
                'lt' => '<', 'gt' => '>', 'amp' => '&', 'quot' => '"', 'apos' => "'",
                default => mb_chr($m[1][1] === 'x' ? (int)hexdec(substr($m[1], 2)) : (int)substr($m[1], 1), 'UTF-8') ?: '',
            };
        }, $text);
    }
}
