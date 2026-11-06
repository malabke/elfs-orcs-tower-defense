<?php

namespace JumPHP\Physics2d;

/** A fixed round obstacle balls bounce on from the outside. */
final class Peg
{
    public int $hits = 0;

    public function __construct(public float $x, public float $y, public float $radius = 4, public string $color = '#ffffff')
    {
    }
}
