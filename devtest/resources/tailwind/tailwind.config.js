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
    },
  },
  plugins: [],
};
