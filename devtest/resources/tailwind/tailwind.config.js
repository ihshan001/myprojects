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
        // Sampled from the ClinicQueue reference: the primary button is
        // #0D7459, the active nav pill is a soft mint, not the old teal.
        brand: {
          50: '#ecfdf5', 100: '#d1fae5', 200: '#a7f3d0', 300: '#6ee7b7',
          400: '#34d399', 500: '#10b981', 600: '#059669', 700: '#0d7459',
          800: '#065f46', 900: '#064e3b', 950: '#022c22',
        },
        mint: {
          50: '#f0fbf7', 100: '#dff4ee', 200: '#bfe9dd',
        },
        // Full ramp. The earlier slate->ink sweep referenced 300/600/800 in
        // many views, but only four shades existed, so this class silently
        // generated nothing and the text fell back to its inherited colour.
        ink: {
          50: '#f8fafc', 100: '#f1f5f9', 200: '#e2e8f0', 300: '#cbd5e1',
          400: '#94a3b8', 500: '#64748b', 600: '#475569', 700: '#334155',
          800: '#1e293b', 900: '#0f172a',
        },
        canvas: '#f7f8fa',
        hairline: '#e8ecf1',

      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto',
               'Noto Sans Sinhala', 'Noto Sans Tamil', 'sans-serif'],
        mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'Consolas', 'monospace'],
      },
      boxShadow: {
        soft: '0 1px 2px rgba(15,23,42,0.04), 0 8px 24px -12px rgba(15,23,42,0.12)',
        card: '0 1px 2px rgba(15,23,42,0.03)',
        bar: '0 1px 3px rgba(15,23,42,0.04), 0 1px 2px rgba(15,23,42,0.03)',
        pop: '0 12px 32px -8px rgba(15,23,42,0.18), 0 2px 8px rgba(15,23,42,0.06)',
      },
      // BREAKPOINTS AT 90% — html { zoom: 0.9 } (resources/css/app.css)
      // renders every page at browser-90% proportions, but element zoom
      // does not resize the viewport media queries evaluate against
      // (real browser zoom divides it by 0.9). Every min-width is
      // therefore the stock Tailwind value × 0.9, so layouts flip at the
      // same PHYSICAL window widths as a browser at 90%:
      //   real: window 950 → viewport 1056 ≥ lg 1024 → lg on
      //   ours: window 950 → viewport 950  ≥ lg 921.6 → lg on
      // Phones are unaffected: 360/426 land on the same side of every
      // breakpoint as before, and the hand-written min-[380px] phone
      // breakpoints in the views stay un-retuned on purpose — they are
      // device thresholds, and desktop zoom does not run on devices.
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
