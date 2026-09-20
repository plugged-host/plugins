<?php

namespace Boy132\Announcements;

use App\Livewire\AlertBanner;
use Boy132\Announcements\Models\Announcement;
use Filament\Contracts\Plugin;
use Filament\Panel;

class AnnouncementsPlugin implements Plugin
{
    public function getId(): string
    {
        return 'announcements';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverResources(plugin_path($this->getId(), "src/Filament/$id/Resources"), "Boy132\\Announcements\\Filament\\$id\\Resources");
    }

    public function boot(Panel $panel): void
    {
        foreach (Announcement::all() as $announcement) {
            if (!$announcement->shouldDisplay($panel)) {
                continue;
            }

            $alertBanner = AlertBanner::make('announcement_' . $announcement->id)
                ->title($announcement->title)
                ->body($announcement->body)
                ->status($announcement->type)
                ->icon($announcement->icon);

            // @phpstan-ignore function.alreadyNarrowedType
            if (method_exists($alertBanner, 'actions')) {
                $action = $announcement->getUrlAction($announcement->url_label, $announcement->url_link);
                $alertBanner->actions($action ? [$action] : []);
            }

            $alertBanner->send();
        }
    }
}
