/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './resources/views/**/*.php',
    './public/assets/js/**/*.js',
    './resources/js/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          50: '#f0fdfa', 100: '#ccfbf1', 200: '#99f6e4', 300: '#5eead4',
          400: '#2dd4bf', 500: '#14b8a6', 600: '#0d9488', 700: '#0f766e',
          800: '#115e59', 900: '#134e4a', 950: '#042f2e',
        },
      },
      fontFamily: {
        sans: ['ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto',
               'Noto Sans Sinhala', 'Noto Sans Tamil', 'sans-serif'],
        mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'Consolas', 'monospace'],
      },
      boxShadow: {
        soft: '0 1px 2px rgba(15,23,42,0.04), 0 8px 24px -12px rgba(15,23,42,0.12)',
      },
      // BREAKPOINTS AT 90% — html { zoom: 0.9 } (resources/css/app.css)
      // renders every page at browser-90% proportions, but element zoom
      // does not resize the viewport media queries evaluate against
      // (real browser zoom divides it by 0.9). Every min-width is
      // therefore the stock Tailwind value × 0.9, so layouts flip at the
      // same PHYSICAL window widths as a browser at 90%:
      //   real: window 950 → viewport 1056 ≥ lg 1024 → lg on
      //   ours: window 950 → viewport 950  ≥ lg 921.6 → lg on
      // The homepage's hand-written layout uses only rem and %, so this
      // list mainly governs the tailwind responsive classes in the views.
      // Keep these in step with the zoom rule; change one, change both.
      // VIEWPORT HEIGHTS AT 90% — `vh` is resolved against the unzoomed
      // viewport and THEN scaled by `html { zoom: 0.9 }`, so `min-h-screen`
      // renders 10% short: a 900px window shows an 810px shell with a white
      // strip under it. Dividing by the zoom cancels it. Percentage heights
      // (`h-full`) already resolve against the zoomed layout box and need no
      // correction. No `vw` utility is used, so width needs nothing.
      // Same 0.9 as the zoom rule and the screens below — change all three.
      height: { screen: 'calc(100vh / 0.9)' },
      minHeight: { screen: 'calc(100vh / 0.9)' },
      screens: {
        sm: '576px',       // 640 × 0.9
        md: '691.2px',     // 768 × 0.9
        lg: '921.6px',     // 1024 × 0.9
        xl: '1152px',      // 1280 × 0.9
        '2xl': '1382.4px', // 1536 × 0.9
      },
    },
  },
  plugins: [],
};
