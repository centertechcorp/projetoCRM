@extends('layouts.app')

@section('title', 'Relatório do dia')

@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div class="flex items-center gap-3">
        <a href="{{ route('painel.index') }}" class="text-sm text-slate-500 hover:text-slate-900">&larr; Voltar</a>
        <h1 class="text-lg font-semibold">Relatório — {{ $date->format('d/m/Y') }}</h1>
    </div>

    <form method="GET" class="flex items-center gap-2 text-sm">
        <input type="date" name="date" value="{{ $date->format('Y-m-d') }}" onchange="this.form.submit()"
            class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
        @if ($stores->isNotEmpty())
            <select name="store" onchange="this.form.submit()"
                class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
                <option value="">Todas as lojas</option>
                @foreach ($stores as $store)
                    <option value="{{ $store->id }}" @selected((string) $selectedStore === (string) $store->id)>{{ $store->code }}</option>
                @endforeach
            </select>
        @endif
    </form>
</div>

<div class="mb-6 flex flex-wrap gap-3">
    @forelse ($summary as $loja => $counts)
        <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
            <div class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $loja }}</div>
            <div class="mt-1 text-sm text-slate-700">
                <span class="font-semibold text-slate-900">{{ $counts['recebidas'] }}</span> recebidas ·
                <span class="font-semibold text-slate-900">{{ $counts['enviadas'] }}</span> enviadas
            </div>
        </div>
    @empty
        <p class="text-sm text-slate-500">Nenhuma mensagem individual nesse dia.</p>
    @endforelse
</div>

@if ($messages->isEmpty())
    <p class="rounded-lg border border-dashed border-slate-300 px-4 py-10 text-center text-sm text-slate-500">
        Nenhuma mensagem nesse dia.
    </p>
@else
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-3">Hora</th>
                    <th class="px-4 py-3">Loja</th>
                    <th class="px-4 py-3">Contato</th>
                    <th class="px-4 py-3">Direção</th>
                    <th class="px-4 py-3">Mensagem</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($messages as $message)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ $message->sent_at->format('H:i') }}</td>
                        <td class="whitespace-nowrap px-4 py-3 font-medium text-slate-900">{{ $message->account->store->code }}</td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <div class="text-slate-900">{{ $message->chat->display_name ?: '(sem nome)' }}</div>
                            <div class="text-slate-500">{{ $message->chat->phone ?? $message->chat->chat_key }}</div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            @if ($message->direction === 'in')
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Recebida</span>
                            @else
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">Enviada</span>
                            @endif
                        </td>
                        <td class="max-w-md px-4 py-3 text-slate-700">
                            {{ $message->body !== '' ? $message->body : '(' . $message->type . ', sem texto)' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
