<?php

namespace App\Support;

class SpreadsheetText
{
    public static function csv(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[\s]*[=+@-]/u', $value) ? "'".$value : $value;
    }
}
