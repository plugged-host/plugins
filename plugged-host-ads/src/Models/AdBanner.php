<?php

namespace PluggedHost\Ads\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PluggedHost\Ads\Services\AdBannerService;
use Throwable;

/**
 * A sponsored banner: one the owner sold himself (usually on Discord) and uploaded in Admin ›
 * Sponsored banners, as opposed to an Adsterra zone.
 *
 * Two placements. `slots` puts it where Adsterra's banners go (page foot, the 300x250 slots, the
 * server-tools strip, the waiting rooms), in place of the Adsterra unit of its size, so its pixel
 * size has to be one Adsterra fills, or twice that (AdBannerService::family()). `top` puts it
 * full width above the page heading on every app and server page, any size.
 *
 * width/height are the image's own pixels, read from the file whenever image_path changes, never
 * typed in. clicks is counted by the redirect route (/ads/b/{id}), one UPDATE per click.
 * What renders is AdBannerService's cached list, which every save and delete clears.
 *
 * @property int $id
 * @property string $image_path
 * @property int $width
 * @property int $height
 * @property string $url
 * @property string $advertiser
 * @property string $placement
 * @property ?CarbonInterface $starts_at
 * @property ?CarbonInterface $ends_at
 * @property bool $is_active
 * @property int $clicks
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class AdBanner extends Model
{
    public const PLACEMENT_SLOTS = 'slots';

    public const PLACEMENT_TOP = 'top';

    public const PLACEMENTS = [self::PLACEMENT_SLOTS, self::PLACEMENT_TOP];

    public const STATUS_LIVE = 'live';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ENDED = 'ended';

    public const STATUS_OFF = 'off';

    /**
     * The public disk, served under /storage. storage/app/public is synced between the panel
     * boxes, so an image uploaded on one is there on the other.
     */
    public const DISK = 'public';

    public const DIRECTORY = 'plugged-host-ads/banners';

    /** No SVG: a same-origin SVG is a document that can run script when opened directly. */
    public const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    public const MAX_KILOBYTES = 4096;

    private const IMAGE_CONSTANTS = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF];

    private const READY_KEY = 'plugged-host-ads:banners:table-ready';

    protected $table = 'plugged_host_ad_banners';

    /** width, height and clicks are not here: the first two are measured, the last counted. */
    protected $fillable = [
        'image_path',
        'url',
        'advertiser',
        'placement',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected $attributes = [
        'placement' => self::PLACEMENT_SLOTS,
        'is_active' => true,
        'width' => 0,
        'height' => 0,
        'clicks' => 0,
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'clicks' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AdBanner $banner): void {
            if ($banner->isDirty('image_path') || $banner->width < 1 || $banner->height < 1) {
                [$banner->width, $banner->height] = self::measure($banner->image_path) ?? [0, 0];
            }
        });

        // A replaced image is not kept: nothing else points at it. Still checked, because
        // deleting a file another row shows would leave that banner a broken image.
        static::updated(function (AdBanner $banner): void {
            if ($banner->wasChanged('image_path')) {
                self::deleteImage((string) $banner->getRawOriginal('image_path'), $banner->getKey());
            }
        });

        // The cache is emptied on `saved`, not `updated`: the click counter is a plain query-builder
        // UPDATE that fires neither, and nothing a click changes is in the cache.
        static::saved(fn () => app(AdBannerService::class)->forget());

        static::deleted(function (AdBanner $banner): void {
            self::deleteImage((string) $banner->image_path, $banner->getKey());
            app(AdBannerService::class)->forget();
        });
    }

    /**
     * Whether the migration has run. The admin page stays hidden until then. Only a yes is
     * remembered, so the page appears as soon as the table exists.
     */
    public static function ready(): bool
    {
        try {
            if (Cache::get(self::READY_KEY) === true) {
                return true;
            }

            $ready = Schema::hasTable((new self())->getTable());

            if ($ready) {
                Cache::put(self::READY_KEY, true, 86400);
            }

            return $ready;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * An image's pixel size, or null when it is not a PNG, JPEG, WebP or GIF that can be read.
     *
     * Takes what a FileUpload holds: a fresh upload (Livewire's temporary file), a path on the
     * public disk, or the field's state array, whose first entry is either of those.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function measure(mixed $file): ?array
    {
        if (is_array($file)) {
            $file = reset($file);
        }

        try {
            $bytes = match (true) {
                $file instanceof TemporaryUploadedFile => $file->get(),
                is_string($file) && $file !== '' && Storage::disk(self::DISK)->exists($file) => Storage::disk(self::DISK)->get($file),
                default => null,
            };

            $size = is_string($bytes) && $bytes !== '' ? @getimagesizefromstring($bytes) : false;
        } catch (Throwable) {
            return null;
        }

        if (!is_array($size) || !in_array($size[2] ?? null, self::IMAGE_CONSTANTS, true)
            || (int) $size[0] < 1 || (int) $size[1] < 1) {
            return null;
        }

        return [(int) $size[0], (int) $size[1]];
    }

    /** off, scheduled, live or ended. Ended wins: a banner past its end stays ended when switched back on. */
    public function status(?CarbonInterface $now = null): string
    {
        $now ??= now();

        return match (true) {
            $this->ends_at !== null && $this->ends_at->lte($now) => self::STATUS_ENDED,
            !$this->is_active => self::STATUS_OFF,
            $this->starts_at !== null && $this->starts_at->gt($now) => self::STATUS_SCHEDULED,
            default => self::STATUS_LIVE,
        };
    }

    /**
     * The stored link if it is still an https URL, else null. The click route redirects to this
     * and to nothing else, so it can never be turned into an open redirect.
     */
    public function safeUrl(): ?string
    {
        $url = trim((string) $this->url);

        if (!preg_match('#^https://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false
            || !is_string(parse_url($url, PHP_URL_HOST))) {
            return null;
        }

        return $url;
    }

    protected static function deleteImage(string $path, mixed $exceptId): void
    {
        if ($path === '' || !str_starts_with($path, self::DIRECTORY . '/')) {
            return;
        }

        try {
            if (static::query()->where('image_path', $path)->whereKeyNot($exceptId)->exists()) {
                return;
            }

            Storage::disk(self::DISK)->delete($path);
        } catch (Throwable) {
            // A file left behind costs a few kilobytes; a failed save would cost the edit.
        }
    }
}
