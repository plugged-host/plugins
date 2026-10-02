<?php

namespace PluggedHost\Ai\Tools\Server;

use App\Enums\SubuserPermission;
use InvalidArgumentException;
use PluggedHost\Ai\Support\SecretRedactor;
use PluggedHost\Ai\Tools\BaseServerTool;
use PluggedHost\Ai\Tools\ToolContext;
use PluggedHost\Ai\Tools\ToolResult;
use PluggedHost\Ai\Tools\ToolRisk;

final class EditFileTool extends BaseServerTool
{
    public function name(): string
    {
        return 'edit_file';
    }

    public function description(): string
    {
        return 'Replace an exact piece of text in a file. old_text must appear exactly once (copy it from read_file without the line-number prefix), unless replace_all is true. Masked •••• values cannot be edited.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'old_text' => ['type' => 'string', 'description' => 'Exact existing text to replace.'],
                'new_text' => ['type' => 'string', 'description' => 'Replacement text.'],
                'replace_all' => ['type' => 'boolean', 'description' => 'Replace every occurrence (default false).'],
            ],
            'required' => ['path', 'old_text', 'new_text'],
        ];
    }

    public function risk(array $arguments): ToolRisk
    {
        return ToolRisk::Write;
    }

    public function offeredWith(): array
    {
        return [SubuserPermission::FileCreate];
    }

    public function requires(array $arguments): array
    {
        return [SubuserPermission::FileReadContent, SubuserPermission::FileCreate];
    }

    public function summarize(array $arguments): string
    {
        return 'Edit ' . $this->path($arguments['path'] ?? null);
    }

    public function execute(ToolContext $context, array $arguments): ToolResult
    {
        $path = $this->path($arguments['path'] ?? null);
        $old = $this->string($arguments, 'old_text', 50000);
        $new = $arguments['new_text'] ?? null;

        if (!is_string($new)) {
            throw new InvalidArgumentException('"new_text" must be a string.');
        }

        if (SecretRedactor::containsMask($old) || SecretRedactor::containsMask($new)) {
            throw new InvalidArgumentException('Masked •••• values cannot be edited by neo; ask the user to change that value themselves.');
        }

        $content = FileSupport::readText($context, $path);
        $count = substr_count($content, $old);

        if ($count === 0) {
            throw new InvalidArgumentException("old_text was not found in {$path}. Read the file again and copy the text exactly.");
        }

        if ($count > 1 && empty($arguments['replace_all'])) {
            throw new InvalidArgumentException("old_text appears {$count} times in {$path}; include more surrounding text or set replace_all.");
        }

        $updated = str_replace($old, $new, $content);
        if (strlen($updated) > (int) config('plugged-host-ai.max_write_bytes', 262144) * 4) {
            throw new InvalidArgumentException('The edited file would be too large.');
        }

        $context->files()->putContent($path, $updated);
        $this->activity($context, 'file.edit')->property('file', $path)->log();

        return ToolResult::ok("Edited {$path}: replaced {$count} occurrence(s).", 'Edited ' . $path);
    }
}
