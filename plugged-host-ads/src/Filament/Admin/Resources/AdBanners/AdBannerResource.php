<?php

namespace PluggedHost\Ads\Filament\Admin\Resources\AdBanners;

use App\Enums\TablerIcon;
use BackedEnum;
use Carbon\CarbonInterface;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use PluggedHost\Ads\Filament\Admin\Resources\AdBanners\Pages\ManageAdBanners;
use PluggedHost\Ads\Models\AdBanner;
use PluggedHost\Ads\Services\AdBannerService;
use PluggedHost\Ads\Services\AdsterraService;
use PluggedHost\Core\Filament\AdminNavigation;
use UnitEnum;

/**
 * Admin › Panel › Sponsored banners: the banners the owner sells himself, next to the ads
 * plugin's settings (Panel › Plugins). One page, create and edit in a modal.
 *
 * Whoever may change plugin settings may manage these (Pelican's `update plugin`), and the page
 * stays hidden until the migration has created the table.
 */
class AdBannerResource extends Resource
{
    protected static ?string $model = AdBanner::class;

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Ad;

    protected static ?string $slug = 'sponsored-banners';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return trans('plugged-host-ads::strings.banners.navigation');
    }

    public static function getModelLabel(): string
    {
        return trans('plugged-host-ads::strings.banners.model');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('plugged-host-ads::strings.banners.plural');
    }

    /** The Panel group, where the ads plugin's settings are (Plugins). */
    public static function getNavigationGroup(): ?string
    {
        return AdminNavigation::label('panel');
    }

    /** Every action (list, create, edit, delete) is the same question. */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        return (user()?->can('update plugin') ?? false) && AdBanner::ready()
            ? Response::allow()
            : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        $timezone = user()?->timezone ?? config('app.timezone', 'UTC');

        return $schema
            ->columns(2)
            ->components([
                FileUpload::make('image_path')
                    ->label(trans('plugged-host-ads::strings.banners.image'))
                    ->helperText(trans('plugged-host-ads::strings.banners.image_helper'))
                    ->disk(AdBanner::DISK)
                    ->directory(AdBanner::DIRECTORY)
                    ->visibility('public')
                    ->acceptedFileTypes(AdBanner::IMAGE_TYPES)
                    ->maxSize(AdBanner::MAX_KILOBYTES)
                    // The stored path comes back from the browser on save: only the record's own
                    // file may be kept, never a path someone typed in.
                    ->preventFilePathTampering()
                    // Checked on the uploaded bytes, not the extension or the browser's word.
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (AdBanner::measure($value) === null) {
                            $fail(trans('plugged-host-ads::strings.banners.image_unreadable'));
                        }
                    })
                    ->required()
                    ->columnSpanFull(),

                Radio::make('placement')
                    ->label(trans('plugged-host-ads::strings.banners.placement'))
                    ->options([
                        AdBanner::PLACEMENT_SLOTS => trans('plugged-host-ads::strings.banners.placement_slots'),
                        AdBanner::PLACEMENT_TOP => trans('plugged-host-ads::strings.banners.placement_top'),
                    ])
                    ->descriptions([
                        AdBanner::PLACEMENT_SLOTS => trans('plugged-host-ads::strings.banners.placement_slots_desc', [
                            'sizes' => implode(', ', AdsterraService::BANNER_SIZES),
                        ]),
                        AdBanner::PLACEMENT_TOP => trans('plugged-host-ads::strings.banners.placement_top_desc'),
                    ])
                    ->default(AdBanner::PLACEMENT_SLOTS)
                    ->required()
                    // The slots take an image of an Adsterra size, or twice it. Checked here
                    // rather than on the image, because the same file is fine as a top banner.
                    ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        if ($value !== AdBanner::PLACEMENT_SLOTS) {
                            return;
                        }

                        $size = AdBanner::measure($get('image_path'));

                        // No size: the image field reports that file itself.
                        if ($size !== null && AdBannerService::family($size[0], $size[1]) === null) {
                            $fail(trans('plugged-host-ads::strings.banners.placement_size', [
                                'size' => "{$size[0]}x{$size[1]}",
                                'sizes' => implode(', ', AdsterraService::BANNER_SIZES),
                            ]));
                        }
                    })
                    ->columnSpanFull(),

                TextInput::make('advertiser')
                    ->label(trans('plugged-host-ads::strings.banners.advertiser'))
                    ->helperText(trans('plugged-host-ads::strings.banners.advertiser_helper'))
                    ->required()
                    ->maxLength(100),

                TextInput::make('url')
                    ->label(trans('plugged-host-ads::strings.banners.url'))
                    ->helperText(trans('plugged-host-ads::strings.banners.url_helper'))
                    ->placeholder('https://')
                    ->required()
                    ->maxLength(2048)
                    ->rule('url:https'),

                DateTimePicker::make('starts_at')
                    ->label(trans('plugged-host-ads::strings.banners.starts_at'))
                    ->placeholder(trans('plugged-host-ads::strings.banners.starts_at_placeholder'))
                    ->nullable()
                    ->native(false)
                    ->seconds(false)
                    ->timezone($timezone),

                DateTimePicker::make('ends_at')
                    ->label(trans('plugged-host-ads::strings.banners.ends_at'))
                    ->placeholder(trans('plugged-host-ads::strings.banners.ends_at_placeholder'))
                    ->helperText(trans('plugged-host-ads::strings.banners.ends_at_helper'))
                    ->nullable()
                    ->native(false)
                    ->seconds(false)
                    ->timezone($timezone)
                    ->after(fn (Get $get): ?string => filled($get('starts_at')) ? 'starts_at' : null),

                Toggle::make('is_active')
                    ->label(trans('plugged-host-ads::strings.banners.is_active'))
                    ->helperText(trans('plugged-host-ads::strings.banners.is_active_helper'))
                    ->default(true)
                    ->inline(false)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('image_path')
                    ->label(trans('plugged-host-ads::strings.banners.image'))
                    ->disk(AdBanner::DISK)
                    ->visibility('public')
                    ->imageHeight(40)
                    ->alt(fn (AdBanner $record): string => trans('plugged-host-ads::strings.banners.alt', ['advertiser' => $record->advertiser])),
                TextColumn::make('advertiser')
                    ->label(trans('plugged-host-ads::strings.banners.advertiser'))
                    ->searchable()
                    ->description(fn (AdBanner $record): ?string => self::host($record->url)),
                TextColumn::make('placement')
                    ->label(trans('plugged-host-ads::strings.banners.placement'))
                    ->formatStateUsing(fn (string $state): string => $state === AdBanner::PLACEMENT_TOP
                        ? trans('plugged-host-ads::strings.banners.placement_top')
                        : trans('plugged-host-ads::strings.banners.placement_slots'))
                    ->description(fn (AdBanner $record): string => self::size($record)),
                TextColumn::make('status')
                    ->label(trans('plugged-host-ads::strings.banners.status'))
                    ->state(fn (AdBanner $record): string => $record->status())
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => trans("plugged-host-ads::strings.banners.status_{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        AdBanner::STATUS_LIVE => 'success',
                        AdBanner::STATUS_SCHEDULED => 'info',
                        default => 'gray',
                    })
                    ->description(fn (AdBanner $record): string => self::period($record)),
                TextColumn::make('clicks')
                    ->label(trans('plugged-host-ads::strings.banners.clicks'))
                    ->numeric()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->createAnother(false),
            ])
            ->emptyStateIcon(TablerIcon::Ad)
            ->emptyStateHeading(trans('plugged-host-ads::strings.banners.empty_heading'))
            ->emptyStateDescription(trans('plugged-host-ads::strings.banners.empty_description'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageAdBanners::route('/')];
    }

    private static function host(?string $url): ?string
    {
        $host = parse_url((string) $url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    /** Its pixels, and the slot size it fills when that is not the same (a 2x image). */
    private static function size(AdBanner $record): string
    {
        $pixels = "{$record->width}x{$record->height}";
        $family = AdBannerService::family((int) $record->width, (int) $record->height);

        return $record->placement === AdBanner::PLACEMENT_SLOTS && $family !== null && $family !== $pixels
            ? "{$pixels} ({$family})"
            : $pixels;
    }

    private static function period(AdBanner $record): string
    {
        $start = self::date($record->starts_at);
        $end = self::date($record->ends_at);

        return match (true) {
            $start !== null && $end !== null => trans('plugged-host-ads::strings.banners.period_between', ['start' => $start, 'end' => $end]),
            $start !== null => trans('plugged-host-ads::strings.banners.period_from', ['date' => $start]),
            $end !== null => trans('plugged-host-ads::strings.banners.period_until', ['date' => $end]),
            default => trans('plugged-host-ads::strings.banners.period_always'),
        };
    }

    /** In the viewer's own timezone, the way Pelican's date columns show dates. */
    private static function date(?CarbonInterface $date): ?string
    {
        return $date?->copy()
            ->setTimezone(user()?->timezone ?? config('app.timezone', 'UTC'))
            ->translatedFormat('j M Y, H:i');
    }
}
