/**
 * Tailwind CSS v3.4.17 configuration for the compiled stylesheet
 * (resources/css/app.css -> public/css/app.css). Build instructions are in the
 * README ("Building the CSS").
 *
 * The theme is exactly the one the Tailwind Play CDN was configured with in
 * resources/views/marketing/partials/head-tokens.blade.php; every class in the
 * Blade views (including those toggled by inline JavaScript) is found by
 * scanning them.
 *
 * @type {import('tailwindcss').Config}
 */
module.exports = {
    content: ['./resources/views/**/*.blade.php'],
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
