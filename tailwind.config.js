/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'class',
  content: [
    './resources/**/*.blade.php',
    './resources/**/*.{js,jsx,ts,tsx,vue}',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Plus Jakarta Sans', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        heading: ['Plus Jakarta Sans', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        mono: ['IBM Plex Mono', 'ui-monospace', 'monospace'],
      },
      colors: {
        // Logbook brand tokens (light + dark via .dark)
        bg: {
          base: 'rgb(var(--bg-base) / <alpha-value>)',
          surface: 'rgb(var(--bg-surface) / <alpha-value>)',
          panel: 'rgb(var(--bg-panel) / <alpha-value>)',
          hover: 'rgb(var(--bg-hover) / <alpha-value>)',
        },
        border: {
          DEFAULT: 'rgb(var(--border) / <alpha-value>)',
        },
        text: {
          primary: 'rgb(var(--text-primary) / <alpha-value>)',
          secondary: 'rgb(var(--text-secondary) / <alpha-value>)',
        },
        brand: {
          DEFAULT: 'rgb(var(--brand) / <alpha-value>)',
          hover: 'rgb(var(--brand-hover) / <alpha-value>)',
          light: 'rgb(var(--brand-light) / <alpha-value>)',
          fill: 'rgb(var(--brand-fill) / <alpha-value>)',
          'fill-hover': 'rgb(var(--brand-fill-hover) / <alpha-value>)',
        },
        accent: {
          blue: 'rgb(var(--accent-blue) / <alpha-value>)',
          orange: 'rgb(var(--accent-orange) / <alpha-value>)',
          teal: 'rgb(var(--accent-teal) / <alpha-value>)',
          purple: 'rgb(var(--accent-purple) / <alpha-value>)',
        },
        sand: {
          DEFAULT: 'rgb(var(--sand) / <alpha-value>)',
          light: 'rgb(var(--sand-light) / <alpha-value>)',
        },
        status: {
          success: 'rgb(var(--status-success) / <alpha-value>)',
          danger: 'rgb(var(--status-danger) / <alpha-value>)',
          info: 'rgb(var(--status-info) / <alpha-value>)',
          pending: 'rgb(var(--status-pending) / <alpha-value>)',
        },
      },
      borderRadius: {
        card: '20px',
        control: '10px',
      },
      spacing: {
        card: '24px',
      },
      fontSize: {
        h1: ['28px', { lineHeight: '1.3', fontWeight: '700' }],
        h2: ['16px', { lineHeight: '1.4', fontWeight: '600' }],
        stat: ['32px', { lineHeight: '1.2', fontWeight: '700' }],
        label: ['12px', { lineHeight: '1.4', letterSpacing: '0.03em', fontWeight: '500' }],
        body: ['14px', { lineHeight: '1.6', fontWeight: '400' }],
        caption: ['12px', { lineHeight: '1.4', fontWeight: '400' }],
      },
    },
  },
  plugins: [],
};
