<?php

namespace PluggedHost\Ads\Filament\Admin\Resources\AdBanners\Pages;

use Filament\Resources\Pages\ManageRecords;
use PluggedHost\Ads\Filament\Admin\Resources\AdBanners\AdBannerResource;

class ManageAdBanners extends ManageRecords
{
    protected static string $resource = AdBannerResource::class;

    public function getSubheading(): ?string
    {
        return trans('plugged-host-ads::strings.banners.subheading');
    }
}
