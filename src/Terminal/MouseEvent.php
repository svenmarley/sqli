<?php

declare(strict_types=1);

namespace Sqli2\Terminal;

/**
 * A decoded mouse event with 0-based (x, y) cell coordinates.
 *
 * Only the events the spec cares about are modeled: left-button press,
 * left-button drag (motion with the button held — used for dragging the
 * scrollbar thumb, §6), and scroll up/down. Bare movement and other buttons
 * are ignored by the decoder so they never reset the screensaver idle timer
 * (Section 14).
 */
final class MouseEvent
{
    public const LEFT_DOWN   = 'left_down';
    public const LEFT_DRAG   = 'left_drag';
    public const SCROLL_UP   = 'scroll_up';
    public const SCROLL_DOWN = 'scroll_down';

    public function __construct(
        public readonly string $kind,
        public readonly int $x,
        public readonly int $y,
    ) {
    }
}
