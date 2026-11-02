<?php

namespace JumphpPlugins\HelloPlugin;

/** The TODO / FIXME comments of a text (plain PHP: it knows nothing of the IDE, easy to test). */
final class Todos
{
    /** @return list<array{line: int, column: int, tag: string, text: string}> */
    public static function in(string $text): array
    {
        $found = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $i => $line) {
            // a comment (// # * <!--), then TODO: or FIXME: (or @todo), then its text
            if (preg_match('/(?:\/\/|#|\*|<!--)\s*(?:@(todo|fixme)\b|(TODO|FIXME):)\s*(.*?)\s*(?:-->|\*\/)?$/i', $line, $m, PREG_OFFSET_CAPTURE)) {
                $tag = $m[1][0] !== '' ? $m[1] : $m[2];
                $found[] = ['line' => $i + 1, 'column' => $tag[1] + 1, 'tag' => strtoupper($tag[0]), 'text' => $m[3][0] !== '' ? $m[3][0] : '(no text)'];
            }
        }
        return $found;
    }
}
