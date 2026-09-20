{{--
    Redesigned Single-Page Server Settings View.
    Compatible with Theme v1 (Nord) and Theme v2.
    Features sleek floating navigation pills, smooth scrolling, and cohesive layout.
--}}
<x-filament-panels::page
    id="form"
    :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()">

    <div x-data="{
            active: 'general',
            sections: ['general', 'sftp', 'resources', 'startup', 'version', 'notifications', 'danger'],
            onScroll() {
                const scrollY = window.scrollY || document.documentElement.scrollTop;
                if (scrollY < 120) {
                    this.active = 'general';
                    return;
                }

                // If scrolled to bottom of page, highlight danger zone
                if ((window.innerHeight + scrollY) >= (document.documentElement.scrollHeight - 60)) {
                    this.active = 'danger';
                    return;
                }

                const offset = 180;
                let current = 'general';

                for (const id of this.sections) {
                    const el = document.getElementById(id);
                    if (!el) continue;
                    const rect = el.getBoundingClientRect();
                    if (rect.top <= offset) {
                        current = id;
                    }
                }
                this.active = current;
            },
            init() {
                this.$nextTick(() => {
                    this.onScroll();
                    window.addEventListener('scroll', () => this.onScroll(), { passive: true });
                });
            }
        }"
        class="flex flex-col gap-6">

        {{-- Sticky Navigation Pill Bar --}}
        <div class="sticky top-0 sm:top-2 z-20 flex flex-wrap items-center gap-1.5 p-1.5 rounded-2xl bg-white/85 dark:bg-gray-900/85 backdrop-blur-md border border-gray-200/80 dark:border-white/10 shadow-sm transition">
            <a href="#general" @click="active = 'general'"
               :class="active === 'general' ? 'bg-primary-500 text-white shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100/80 dark:hover:bg-white/5'"
               class="px-3.5 py-1.5 text-xs font-semibold rounded-xl transition flex items-center gap-1.5">
                <x-filament::icon icon="tabler-settings" class="size-4" />
                <span>{{ trans('server/setting.server_info.title') }}</span>
            </a>

            @if ($this->hasSftpAccess())
                <a href="#sftp" @click="active = 'sftp'"
                   :class="active === 'sftp' ? 'bg-primary-500 text-white shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100/80 dark:hover:bg-white/5'"
                   class="px-3.5 py-1.5 text-xs font-semibold rounded-xl transition flex items-center gap-1.5">
                    <x-filament::icon icon="tabler-folder-share" class="size-4" />
                    <span>{{ trans('server/setting.server_info.sftp.title') }}</span>
                </a>
            @endif

            @if ($this->canEditResources())
                <a href="#resources" @click="active = 'resources'"
                   :class="active === 'resources' ? 'bg-primary-500 text-white shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100/80 dark:hover:bg-white/5'"
                   class="px-3.5 py-1.5 text-xs font-semibold rounded-xl transition flex items-center gap-1.5">
                    <x-filament::icon icon="tabler-cpu" class="size-4" />
                    <span>{{ trans('plugged-host-servers::strings.server_settings.sections.resources') }}</span>
                </a>
            @endif

            @if ($this->canReadStartup())
                <a href="#startup" @click="active = 'startup'"
                   :class="active === 'startup' ? 'bg-primary-500 text-white shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100/80 dark:hover:bg-white/5'"
                   class="px-3.5 py-1.5 text-xs font-semibold rounded-xl transition flex items-center gap-1.5">
                    <x-filament::icon icon="tabler-player-play" class="size-4" />
                    <span>{{ trans('plugged-host-servers::strings.server_settings.sections.startup') }}</span>
                </a>
            @endif

            @if ($this->canChangeVersion())
                <a href="#version" @click="active = 'version'"
                   :class="active === 'version' ? 'bg-primary-500 text-white shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100/80 dark:hover:bg-white/5'"
                   class="px-3.5 py-1.5 text-xs font-semibold rounded-xl transition flex items-center gap-1.5">
                    <x-filament::icon icon="tabler-versions" class="size-4" />
                    <span>{{ trans('plugged-host-servers::strings.server_settings.sections.version') }}</span>
                </a>
            @endif

            <a href="#notifications" @click="active = 'notifications'"
               :class="active === 'notifications' ? 'bg-primary-500 text-white shadow-sm' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100/80 dark:hover:bg-white/5'"
               class="px-3.5 py-1.5 text-xs font-semibold rounded-xl transition flex items-center gap-1.5">
                <x-filament::icon icon="tabler-bell" class="size-4" />
                <span>{{ trans('server/setting.notifications.title') }}</span>
            </a>

            @if ($this->canAccessDangerZone())
                <a href="#danger" @click="active = 'danger'"
                   :class="active === 'danger' ? 'bg-danger-500 text-white shadow-sm' : 'text-danger-600 dark:text-danger-400 hover:bg-danger-50 dark:hover:bg-danger-950/30'"
                   class="px-3.5 py-1.5 text-xs font-semibold rounded-xl transition flex items-center gap-1.5 ms-auto">
                    <x-filament::icon icon="tabler-alert-triangle" class="size-4" />
                    <span>{{ trans('plugged-host-servers::strings.danger_zone') }}</span>
                </a>
            @endif
        </div>

        {{-- Form Content --}}
        <div class="flex flex-col gap-6">
            {{ $this->form }}
        </div>
    </div>
</x-filament-panels::page>
