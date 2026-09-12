<?php

declare(strict_types=1);

namespace Sqli\Terminal;

/**
 * A block of text delivered by the terminal's bracketed-paste mode (§4).
 *
 * When bracketed paste is enabled (ESC[?2004h), a terminal wraps pasted content
 * in ESC[200~ … ESC[201~. {@see InputReader} collects the whole block and emits
 * it as a single PasteEvent, so embedded newlines/escape bytes are inserted
 * literally rather than being decoded as Enter/arrow keypresses.
 */
final class PasteEvent
{
    public function __construct(
        public readonly string $text,
    ) {
    }
}
