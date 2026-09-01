import type { Config } from 'tailwindcss';

export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        primary: {
          DEFAULT: '#0E339D',
          ink: '#0A2678',
          soft: '#E7ECF9',
        },
        secondary: {
          DEFAULT: '#0694AC',
          soft: '#E1F4F7',
        },
        surface: {
          DEFAULT: '#FFFFFF',
          muted: '#F7F9FC',
          alt: '#EEF2F9',
        },
        ink: {
          DEFAULT: '#1F2937',
          muted: '#6B7280',
          faint: '#94A3B8',
        },
        border: {
          DEFAULT: '#E5E7EB',
        },
        success: { DEFAULT: '#178A4C', soft: '#E1F6EA' },
        warning: { DEFAULT: '#B5730B', soft: '#FCF0DA' },
        danger: { DEFAULT: '#C33232', soft: '#FBE6E6' },
        sidebar: {
          DEFAULT: '#0A1B3F',
          hover: '#13285C',
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
        card: '0 1px 2px rgba(20,30,60,.04), 0 8px 24px -12px rgba(20,30,60,.12)',
      },
    },
  },
  plugins: [],
} satisfies Config;
