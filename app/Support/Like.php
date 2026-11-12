<?php

namespace App\Support;

class Like
{
    /** A LIKE pattern that matches the term literally anywhere in the column. */
    public static function contains(string $term): string
    {
        return '%'.addcslashes($term, '\\%_').'%';
    }
}
