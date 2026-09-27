@extends('layouts.app')

@section('title', 'Entrar')

@section('content')
<div class="mx-auto mt-16 max-w-sm">
    <h1 class="mb-6 text-xl font-semibold tracking-tight">Entrar no CRM</h1>

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        @csrf

        <div>
            <label for="email" class="mb-1 block text-sm font-medium text-slate-700">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
        </div>

        <div>
            <label for="password" class="mb-1 block text-sm font-medium text-slate-700">Senha</label>
            <input id="password" name="password" type="password" required
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
        </div>

        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="remember" class="rounded border-slate-300">
            Lembrar de mim
        </label>

        <button type="submit"
            class="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
            Entrar
        </button>
    </form>
</div>
@endsection
