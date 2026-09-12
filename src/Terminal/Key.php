<?php

declare(strict_types=1);

namespace Sqli\Terminal;

/**
 * Logical key identities for non-printable keys. Printable characters are
 * carried as the literal string on KeyEvent instead of an enum case.
 */
enum Key: string
{
    case Enter     = 'enter';
    case Escape    = 'escape';
    case Backspace = 'backspace';
    case Delete    = 'delete';
    case Tab       = 'tab';

    case Up    = 'up';
    case Down  = 'down';
    case Left  = 'left';
    case Right = 'right';

    case Home     = 'home';
    case End      = 'end';
    case PageUp   = 'pageup';
    case PageDown = 'pagedown';

    case F1 = 'f1';
    case F2 = 'f2';

    case Char = 'char';
}
