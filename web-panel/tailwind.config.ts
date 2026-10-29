import type { Config } from 'tailwindcss';

/**
 * A colour that is a CSS variable (`--color-<name>`, space-separated RGB channels) so the Light and Dark themes can switch it, while Tailwind's
 * opacity utilities (`bg-primary/20`, `border-danger/40`) keep working. The values live in src/index.css: the Light values are exactly the
 * approved brand colours that used to be written here (Issue #11: Primary #0E339B, Secondary #00B9B3, White #FFFFFF).
 */
const color = (name: string) => `rgb(var(--color-${name}) / <alpha-value>)`;

export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      // Fills, borders, rings and every other non-text use of a colour.
      colors: {
        primary: {
          DEFAULT: color('primary'),
          ink: color('primary-ink'),
          soft: color('primary-soft'),
        },
        secondary: {
          DEFAULT: color('secondary'),
          soft: color('secondary-soft'),
        },
        surface: {
          DEFAULT: color('surface'),
          muted: color('surface-muted'),
          alt: color('surface-alt'),
        },
        // "ink" is the text colour, but as a background it is the modal scrim and the grey chart bars - those must not turn light in Dark Mode.
        ink: {
          DEFAULT: color('ink-overlay'),
          muted: color('ink-muted-fill'),
          faint: color('ink-faint-fill'),
        },
        border: {
          DEFAULT: color('border'),
        },
        success: { DEFAULT: color('success'), soft: color('success-soft') },
        warning: { DEFAULT: color('warning'), soft: color('warning-soft') },
        danger: { DEFAULT: color('danger'), soft: color('danger-soft') },
        sidebar: {
          DEFAULT: color('sidebar'),
          hover: color('sidebar-hover'),
        },
        // The seven fills of report charts (bars, ring slices): bg-chart-1 ... bg-chart-7, stroke-chart-1 ... Light and Dark values live in index.css.
        chart: {
          1: color('chart-1'),
          2: color('chart-2'),
          3: color('chart-3'),
          4: color('chart-4'),
          5: color('chart-5'),
          6: color('chart-6'),
          7: color('chart-7'),
        },
      },
      // A bare `border` / `divide-y` (no colour class) used to fall back to Tailwind's grey #e5e7eb, which is exactly the Light border token.
      borderColor: {
        DEFAULT: color('border'),
      },
      // The same names used as TEXT (text-primary, text-ink-muted, text-danger ...). A link or a label needs a lighter value on a dark surface
      // than the fill of a button, so text has its own variables; nothing in a page has to choose between them.
      textColor: {
        primary: { DEFAULT: color('primary-text') },
        secondary: { DEFAULT: color('secondary-text') },
        success: { DEFAULT: color('success-text') },
        warning: { DEFAULT: color('warning-text') },
        danger: { DEFAULT: color('danger-text') },
        ink: {
          DEFAULT: color('ink-text'),
          muted: color('ink-muted-text'),
          faint: color('ink-faint-text'),
        },
      },
      fontFamily: {
        display: ['"Plus Jakarta Sans"', 'system-ui', 'sans-serif'],
        sans: ['"Public Sans"', 'system-ui', 'sans-serif'],
      },
      borderRadius: {
        sm: '8px',
        md: '12px',
        lg: '16px',
      },
      boxShadow: {
        card: 'var(--shadow-card)',
      },
    },
  },
  plugins: [],
} satisfies Config;
