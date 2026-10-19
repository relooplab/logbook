@extends('layouts.app')

@section('title', 'Kelola Hak Akses')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-heading font-bold text-2xl text-text-primary">Kelola Hak Akses</h1>
            <p class="text-sm text-text-secondary mt-0.5">Atur permission per role & fitur paket</p>
        </div>
        <a href="{{ route('dashboard') }}" class="px-4 py-2 rounded-xl bg-bg-hover text-text-primary text-sm font-medium hover:bg-border">← Dashboard</a>
    </div>

    {{-- ===== Matrix Permission per Role ===== --}}
    <div class="card p-6">
        <h2 class="font-heading font-semibold text-text-primary mb-1">Matrix Permission per Role</h2>
        <p class="text-sm text-text-secondary mb-4">Centang permission yang dimiliki setiap role. Perubahan berlaku global untuk semua user dengan role tersebut.</p>

        <form method="POST" action="{{ route('admin.system.permissions.update') }}">
            @csrf
            <div class="overflow-x-auto">
                <table class="w-full text-sm border border-border">
                    <thead>
                        <tr class="bg-bg-panel text-left text-text-secondary">
                            <th class="py-2 px-3 border-b border-border">Permission</th>
                            @foreach ($roles as $role)
                                <th class="py-2 px-3 border-b border-border text-center">{{ $role->name }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @php $currentGroup = null; @endphp
                        @foreach ($permissions as $permission)
                            @php
                                $group = explode('.', $permission->name)[0];
                                $isNewGroup = $group !== $currentGroup;
                                $currentGroup = $group;
                            @endphp
                            @if ($isNewGroup)
                                <tr class="bg-bg-panel/50">
                                    <td colspan="{{ $roles->count() + 1 }}" class="py-1.5 px-3 text-[10px] uppercase tracking-widest text-text-secondary font-semibold">{{ $group }}</td>
                                </tr>
                            @endif
                            <tr class="border-b border-border">
                                <td class="py-1.5 px-3">{{ $permission->name }}</td>
                                @foreach ($roles as $role)
                                    <td class="py-1.5 px-3 text-center">
                                        <input type="checkbox" name="permissions[{{ $role->id }}][]" value="{{ $permission->name }}"
                                            @checked($role->hasPermissionTo($permission->name))
                                            class="rounded bg-bg-surface border-border">
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">
                <button type="submit" class="px-4 py-2 rounded-xl bg-brand text-[#0b1420] text-sm font-medium hover:opacity-90">Simpan Hak Akses</button>
            </div>
        </form>
    </div>
</div>
@endsection
