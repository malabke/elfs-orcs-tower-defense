<?php

namespace JumPHP\Shorts;

// The captions moved into the engine (JumPHP\Renderer\Captions, JumPHP 0.5.9) so that they also work over any game (video:replay);
// this is the same class under the name the extension always had.
class_alias(\JumPHP\Renderer\Captions::class, 'JumPHP\\Shorts\\Captions');
