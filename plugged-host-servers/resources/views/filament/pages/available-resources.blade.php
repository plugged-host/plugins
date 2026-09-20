<x-filament-panels::page>
    @if ($nodes->isEmpty())
        <x-filament::section>
            <div class="flex flex-col items-center justify-center gap-2 py-8 text-center">
                <x-filament::icon icon="tabler-device-desktop-analytics" class="h-10 w-10 text-gray-400" />
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white">No nodes available</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">There are no nodes available to display.</p>
            </div>
        </x-filament::section>
    @else
        <div class="space-y-6">
            <x-filament::section
                heading="Combined Resources"
                description="Live aggregate usage across all accessible nodes. The charts refresh automatically."
            >
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    @livewire(\PluggedHost\Servers\Filament\Admin\Widgets\CombinedCpuChart::class, key('plugged-host-core-combined-cpu'))
                    @livewire(\PluggedHost\Servers\Filament\Admin\Widgets\CombinedMemoryChart::class, key('plugged-host-core-combined-memory'))
                    <div class="lg:col-span-2">
                        @livewire(\PluggedHost\Servers\Filament\Admin\Widgets\CombinedStorageChart::class, key('plugged-host-core-combined-storage'))
                    </div>
                </div>
            </x-filament::section>

            <div class="space-y-6">
                @foreach ($nodes as $node)
                    <x-filament::section>
                        <x-slot name="heading">
                            @if (user()?->can('update', $node))
                                <a href="{{ \App\Filament\Admin\Resources\Nodes\Pages\EditNode::getUrl(['record' => $node]) }}" class="hover:text-primary-600 dark:hover:text-primary-400">
                                    {{ $node->name }}
                                </a>
                            @else
                                {{ $node->name }}
                            @endif
                        </x-slot>

                        <x-slot name="description">Node overview</x-slot>

                        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                            @livewire(\App\Filament\Admin\Resources\Nodes\Widgets\NodeCpuChart::class, ['node' => $node], key("available-resources-node-{$node->id}-cpu"))
                            @livewire(\App\Filament\Admin\Resources\Nodes\Widgets\NodeMemoryChart::class, ['node' => $node], key("available-resources-node-{$node->id}-memory"))
                            <div class="lg:col-span-2">
                                @livewire(\App\Filament\Admin\Resources\Nodes\Widgets\NodeStorageChart::class, ['node' => $node], key("available-resources-node-{$node->id}-storage"))
                            </div>
                        </div>
                    </x-filament::section>
                @endforeach
            </div>
        </div>
    @endif
</x-filament-panels::page>
