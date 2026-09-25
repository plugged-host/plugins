<?php

namespace PluggedHost\Neo\Tools;

enum ToolRisk: string
{
    // Looks, never changes anything: always runs without asking.
    case Read = 'read';

    // Changes the server: asks first unless the user turned on "make changes without asking".
    case Write = 'write';

    // Hard to undo (delete, kill): same as Write, plus the admin can force a prompt.
    case Destructive = 'destructive';
}
