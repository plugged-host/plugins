{{-- Installed plugins/mods list, embedded at the top of the ServerContent page (above the
     browse table). Reads the Livewire component's public $rows / identify() directly, so it
     works as a schema View component on the same page. --}}
<div>
    <h3 class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">
        {{ trans('plugged-host-minecraft::strings.mods.installed_heading') }}
    </h3>

    @if (count($this->rows) === 0)
        <div class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
            {{ trans('plugged-host-minecraft::strings.mods.empty') }}
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">{{ trans('plugged-host-minecraft::strings.mods.col_file') }}</th>
                        <th class="px-4 py-3">{{ trans('plugged-host-minecraft::strings.mods.col_platform') }}</th>
                        <th class="px-4 py-3">{{ trans('plugged-host-minecraft::strings.mods.col_match') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($this->rows as $row)
                        <tr class="{{ ($row['disabled'] ?? false) ? 'opacity-60' : '' }}">
                            <td class="px-4 py-3 font-mono text-xs text-gray-950 dark:text-white">
                                {{ $row['file'] ?? '' }}
                                @if ($row['disabled'] ?? false)
                                    <span class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-[0.65rem] uppercase text-gray-500 dark:bg-white/10 dark:text-gray-300">{{ trans('plugged-host-minecraft::strings.mods.disabled') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if (($row['platform'] ?? null) === 'modrinth')
                                    <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-500">Modrinth</span>
                                @elseif (($row['platform'] ?? null) === 'curseforge')
                                    <span class="rounded-full bg-orange-500/10 px-2 py-0.5 text-xs font-medium text-orange-500">CurseForge</span>
                                @else
                                    <span class="text-xs text-gray-400">{{ trans('plugged-host-minecraft::strings.mods.unknown') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                                {{ $row['title'] ?? trans('plugged-host-minecraft::strings.mods.unknown') }}
                                @if (($row['confidence'] ?? null) === 'name')
                                    <span class="text-xs text-gray-400">(~)</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" wire:click="identify(@js($row['file'] ?? ''))" class="text-xs font-medium text-primary-500 hover:underline">
                                    {{ trans('plugged-host-minecraft::strings.mods.identify') }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
