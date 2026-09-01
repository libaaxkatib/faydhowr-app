import logoSrc from '@/assets/logo.png';

interface LogoProps {
  className?: string;
  /** Renders a light wordmark for use on the dark sidebar background. */
  variant?: 'default' | 'inverted';
}

/**
 * Wraps the real Fayadhowr logo asset (`src/assets/logo.png`, copied from
 * `mobile/assets/images/Fayadhowr-66.png` — the only Fayadhowr logo asset
 * present in the repository; no SVG version exists yet). Kept as a single
 * component so a vector export can replace the `<img>` here later without
 * touching the sidebar/header/login call sites.
 */
export function Logo({ className = 'h-8 w-auto', variant = 'default' }: LogoProps) {
  return (
    <img
      src={logoSrc}
      alt="Fayadhowr"
      className={`${className} ${variant === 'inverted' ? 'brightness-0 invert' : ''}`}
    />
  );
}
