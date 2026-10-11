{{--
    Single-page server settings. A sticky jump bar (styled like the panel's tabs: text-only
    items, the active one on the raised fill) above the settings form. Scoped plain CSS on
    the theme tokens, so it needs no asset build.
--}}
<x-filament-panels::page
    id="form"
    :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()">

    <style>
        .phsf-nav { position: sticky; top: 0; z-index: 20; display: flex; align-items: center; gap: .25rem; padding: .5rem; border-radius: 1rem; background: var(--phv2-modal); overflow-x: auto; scrollbar-width: none; }
        .phsf-nav::-webkit-scrollbar { display: none; }
        .phsf-item { flex: none; padding: .5rem .75rem; border-radius: .75rem; font-size: .875rem; font-weight: 500; line-height: 1.25rem; white-space: nowrap; color: var(--phv2-text-nav); transition: background-color 150ms ease, color 150ms ease; }
        .phsf-item:hover, .phsf-item:focus-visible, .phsf-item.is-active { background: var(--phv2-raise-2); color: var(--phv2-text); }
        .phsf-item-danger { margin-inline-start: auto; color: var(--danger-400); }
        .phsf-item-danger:hover, .phsf-item-danger:focus-visible, .phsf-item-danger.is-active { color: var(--danger-400); }
        @media (min-width: 640px) { .phsf-nav { top: .5rem; } }
    </style>

    <div x-data="{
            active: 'general',
            sections: ['general', 'sftp', 'vscode', 'resources', 'startup', 'version', 'notifications', 'danger'],
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

        {{-- Sticky jump bar --}}
        <nav class="phsf-nav">
            <a href="#general" @click="active = 'general'"
               :class="active === 'general' ? 'is-active' : ''"
               class="phsf-item">
                {{ trans('server/setting.server_info.title') }}
            </a>

            @if ($this->hasSftpAccess())
                <a href="#sftp" @click="active = 'sftp'"
                   :class="active === 'sftp' ? 'is-active' : ''"
                   class="phsf-item">
                    {{ trans('server/setting.server_info.sftp.title') }}
                </a>
            @endif

            @if ($this->hasDevbox())
                <a href="#vscode" @click="active = 'vscode'"
                   :class="active === 'vscode' ? 'is-active' : ''"
                   class="phsf-item">
                    {{ trans('plugged-host-servers::strings.server_settings.vscode.title') }}
                </a>
            @endif

            @if ($this->canEditResources())
                <a href="#resources" @click="active = 'resources'"
                   :class="active === 'resources' ? 'is-active' : ''"
                   class="phsf-item">
                    {{ trans('plugged-host-servers::strings.server_settings.sections.resources') }}
                </a>
            @endif

            @if ($this->canReadStartup())
                <a href="#startup" @click="active = 'startup'"
                   :class="active === 'startup' ? 'is-active' : ''"
                   class="phsf-item">
                    {{ trans('plugged-host-servers::strings.server_settings.sections.startup') }}
                </a>
            @endif

            @if ($this->canChangeVersion())
                <a href="#version" @click="active = 'version'"
                   :class="active === 'version' ? 'is-active' : ''"
                   class="phsf-item">
                    {{ trans('plugged-host-servers::strings.server_settings.sections.version') }}
                </a>
            @endif

            <a href="#notifications" @click="active = 'notifications'"
               :class="active === 'notifications' ? 'is-active' : ''"
               class="phsf-item">
                {{ trans('server/setting.notifications.title') }}
            </a>

            @if ($this->canAccessDangerZone())
                <a href="#danger" @click="active = 'danger'"
                   :class="active === 'danger' ? 'is-active' : ''"
                   class="phsf-item phsf-item-danger">
                    {{ trans('plugged-host-servers::strings.danger_zone') }}
                </a>
            @endif
        </nav>

        {{-- Form Content --}}
        <div class="flex flex-col gap-6">
            {{ $this->form }}
        </div>
    </div>
</x-filament-panels::page>
