<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'CRM') — CENTER TECH</title>
    <script>
        (function () {
            const stored = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

            if (stored === 'dark' || (!stored && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased dark:bg-slate-900 dark:text-slate-100">
    @auth
        <header class="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
                <a href="{{ route('painel.index') }}" class="font-semibold tracking-tight">CRM WhatsApp</a>
                <div class="flex items-center gap-4 text-sm text-slate-600 dark:text-slate-400">
                    <button type="button" id="themeToggle" aria-label="Alternar tema claro/escuro"
                        class="rounded-lg border border-slate-300 p-1.5 text-slate-600 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                        <svg class="h-4 w-4 dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-2.227-1.591 1.591M5.25 12H3m2.227-4.773L3.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                        </svg>
                        <svg class="hidden h-4 w-4 dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                        </svg>
                    </button>
                    <span>{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline dark:text-slate-400 dark:hover:text-slate-100">
                            Sair
                        </button>
                    </form>
                </div>
            </div>
        </header>
    @endauth

    <main class="mx-auto max-w-6xl px-4 py-6">
        <div id="flashStatus" class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200 {{ session('status') ? '' : 'hidden' }}">
            {{ session('status') }}
        </div>

        @yield('content')
    </main>

    <script>
        document.getElementById('themeToggle')?.addEventListener('click', function () {
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        });

        (function () {
            const flash = document.getElementById('flashStatus');
            if (flash && !flash.classList.contains('hidden')) {
                setTimeout(function () {
                    flash.classList.add('hidden');
                }, 20000);
            }
        })();
    </script>
</body>
</html>
