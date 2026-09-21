{{-- Shared design tokens for every public page: fonts, the Tailwind Play CDN and the
     brand palette/components layered on via an inline config. Included by the marketing
     layout and by standalone pages (payment) so there is one source of truth. --}}
    {{-- Plus Jakarta Sans for headings, Inter for body/UI. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">

    {{-- Same Tailwind Play CDN the rest of the app uses; the marketing palette and
         fonts are layered on via an inline config so no build step is introduced. --}}
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            violet: '#5423E7',   // Royal Violet — hero / brand
                            iris: '#7047EB',     // Electric Iris — shapes, emphasis
                            zest: '#FFC233',     // Lemon Zest — sparing accent
                            obsidian: '#121217', // primary buttons, headings
                            paper: '#FFFFFF',
                            fog: '#F7F7F8',
                            slate: '#6C6C89',
                            ash: '#D1D1DB',
                        },
                    },
                    fontFamily: {
                        display: ['"Plus Jakarta Sans"', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                    },
                    borderRadius: {
                        '4xl': '2rem', // 32px: large marketing surfaces
                    },
                    maxWidth: {
                        site: '80rem',
                    },
                },
            },
        };
    </script>
    <style type="text/tailwindcss">
        @layer base {
            html { scroll-padding-top: 5.5rem; }
            body { @apply font-sans text-brand-obsidian bg-brand-paper antialiased; }
            h1, h2, h3, h4 { @apply font-display; }
            @media (prefers-reduced-motion: reduce) {
                html { scroll-behavior: auto; }
                *, *::before, *::after { transition-duration: 0.01ms !important; animation: none !important; }
            }
        }
        @layer components {
            .container-x { @apply mx-auto w-full max-w-site px-4 sm:px-6 lg:px-8; }

            /* Buttons: 12px radius, 44–48px tall. Surface rule — on violet the primary is
               Lemon Zest + Obsidian text; on light surfaces it is Obsidian + white text. */
            .btn { @apply inline-flex items-center justify-center gap-2 rounded-xl px-6 min-h-[48px] text-base font-semibold whitespace-nowrap transition-colors focus:outline-none focus-visible:ring-4; }
            .btn-sm { @apply min-h-[44px] px-5 text-sm; }
            .btn-obsidian { @apply btn bg-brand-obsidian text-white hover:bg-[#2B2B36] focus-visible:ring-brand-obsidian/25; }
            .btn-outline { @apply btn border border-brand-ash bg-white text-brand-obsidian hover:border-brand-obsidian focus-visible:ring-brand-obsidian/20; }
            .btn-zest { @apply btn bg-brand-zest text-brand-obsidian hover:bg-[#FFD15C] focus-visible:ring-white/60; }
            .btn-outline-light { @apply btn border border-white/45 text-white hover:bg-white/10 focus-visible:ring-white/60; }

            .eyebrow { @apply inline-flex items-center rounded-full px-3.5 py-1.5 text-xs font-semibold uppercase tracking-[0.12em]; }
            .eyebrow-violet { @apply eyebrow bg-brand-violet/10 text-brand-violet; }
            .section-title { @apply mt-5 font-display text-3xl sm:text-4xl lg:text-5xl font-extrabold leading-[1.1] tracking-tight text-brand-obsidian; }
            .section-lead { @apply mt-4 text-lg text-brand-slate max-w-2xl; }

            .nav-link { @apply inline-flex items-center whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium text-brand-slate hover:text-brand-obsidian focus:outline-none focus-visible:ring-4 focus-visible:ring-brand-violet/30 transition-colors; }

            /* Form fields (registration and other public forms). */
            .field-label { @apply block text-sm font-semibold text-brand-obsidian; }
            .field-input { @apply mt-2 block w-full min-h-[48px] rounded-xl border border-brand-ash bg-white px-4 text-base text-brand-obsidian placeholder:text-brand-slate/60 focus:border-brand-violet focus:outline-none focus:ring-4 focus:ring-brand-violet/20; }
            .field-input-error { @apply border-red-500 focus:border-red-500 focus:ring-red-500/20; }
            .field-help { @apply mt-1.5 text-sm text-brand-slate; }
            .field-error { @apply mt-1.5 text-sm font-medium text-red-600; }
        }
    </style>
