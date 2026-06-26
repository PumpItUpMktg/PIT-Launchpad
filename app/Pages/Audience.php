<?php

namespace App\Pages;

/**
 * Who is reading a page's state. The canonical vocabulary's client line is identical for both; only
 * the whose-move line flips camp, and ONLY for held/failed states (operator-truth vs client-calm).
 * The operator tail is appended for {@see self::Operator} only.
 */
enum Audience
{
    case Operator;
    case Client;
}
