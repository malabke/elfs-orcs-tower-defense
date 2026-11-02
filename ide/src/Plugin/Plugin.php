<?php

namespace JumPHP\Ide\Plugin;

/**
 * A plugin of the JumPHP IDE, written in PHP. Its class is named in its composer.json (extra.jumphp-ide.class);
 * the IDE makes one instance when the plugin loads and calls activate() once, where it adds what it brings:
 * actions (menus, Find Action, shortcuts), tool windows, run configurations, completion, problems, listeners.
 *
 *     final class TodoPlugin implements Plugin
 *     {
 *         public function activate(Api $ide): void
 *         {
 *             $ide->action('count', 'Count the TODOs', fn (EditorState $at) => $ide->notify(substr_count($at->text, 'TODO') . ' TODO'));
 *         }
 *     }
 *
 * Its code runs inside the IDE, on the IDE's frame: never block (no sleep, no long loops; work a slice at a time
 * with $ide->every()). An exception it throws is shown to the user and never takes the IDE down.
 */
interface Plugin
{
    public function activate(Api $ide): void;
}
