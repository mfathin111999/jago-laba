/** Builds the utility CSS the legacy Marketplace pages got from the Tailwind Play CDN (v3). Run: npm run css:tailwind */
module.exports = {
    content: ['../app/Modules/Marketplace/**/*.php', './resources/js/**/*.{vue,ts}'],
    theme: {
        extend: {
            fontFamily: { sans: ['"Inter"', '"Plus Jakarta Sans"', 'sans-serif'] },
            colors: { brand: { 50: '#fff7ed', 100: '#ffedd5', 500: '#f97316', 600: '#ea580c', 700: '#c2410c' } },
        },
    },
};
