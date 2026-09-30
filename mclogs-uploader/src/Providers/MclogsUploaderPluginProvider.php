<?php

namespace Boy132\MclogsUploader\Providers;

use App\Enums\HeaderActionPosition;
use App\Filament\Server\Pages\Console;
use Boy132\MclogsUploader\Filament\Components\Actions\UploadLogsAction;
use Illuminate\Support\ServiceProvider;

class MclogsUploaderPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        Console::registerCustomHeaderActions(HeaderActionPosition::Before, UploadLogsAction::make());
    }

    public function boot(): void {}
}
