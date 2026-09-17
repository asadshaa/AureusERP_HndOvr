<div class="space-y-6">
    <div>
        <h3 class="text-sm font-semibold text-gray-950 dark:text-white mb-2">Versions</h3>
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/5 text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2">#</th>
                        <th class="px-3 py-2">File</th>
                        <th class="px-3 py-2">Size</th>
                        <th class="px-3 py-2">Uploaded by</th>
                        <th class="px-3 py-2">Uploaded at</th>
                        <th class="px-3 py-2">Reason</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($record->versions as $version)
                        <tr @class(['bg-primary-50/50 dark:bg-primary-500/10' => $version->id === $record->current_version_id])>
                            <td class="px-3 py-2">{{ $version->version_number }} @if ($version->id === $record->current_version_id) <span class="text-xs text-primary-600 dark:text-primary-400">(current)</span> @endif</td>
                            <td class="px-3 py-2">{{ $version->original_filename }}</td>
                            <td class="px-3 py-2">{{ number_format($version->file_size / 1024, 1) }} KB</td>
                            <td class="px-3 py-2">{{ $version->uploader?->name ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $version->created_at->format('M j, Y H:i') }}</td>
                            <td class="px-3 py-2">{{ $version->change_reason ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div>
        <h3 class="text-sm font-semibold text-gray-950 dark:text-white mb-2">Activity</h3>
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/5 text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2">Action</th>
                        <th class="px-3 py-2">By</th>
                        <th class="px-3 py-2">When</th>
                        <th class="px-3 py-2">IP address</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($record->audits as $audit)
                        <tr @class(['bg-danger-50/50 dark:bg-danger-500/10' => $audit->action->value === 'access_denied'])>
                            <td class="px-3 py-2">{{ $audit->action->getLabel() }}</td>
                            <td class="px-3 py-2">{{ $audit->actor?->name ?? '—' }}</td>
                            <td class="px-3 py-2">{{ $audit->created_at->format('M j, Y H:i') }}</td>
                            <td class="px-3 py-2">{{ $audit->ip_address ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
