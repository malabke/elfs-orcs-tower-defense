<?php

namespace JumPHP\Physics2d;

/** A segment balls bounce on (both sides). */
final class Wall
{
    public function __construct(public float $x1, public float $y1, public float $x2, public float $y2, public string $color = '#ffffff')
    {
    }
}
