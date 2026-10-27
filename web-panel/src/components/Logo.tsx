import logoSrc from '@/assets/logo.png';

interface LogoProps {
  className?: string;
  /**
   * 'inverted': the white wordmark for the always-dark sidebar. 'auto': the normal full-colour logo in Light Mode and the same white wordmark in
   * Dark Mode, for the one place the logo sits on a background that changes with the theme (the sign-in page). The asset itself is never changed.
   */
  variant?: 'default' | 'inverted' | 'auto';
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
      className={`${className} ${variant === 'inverted' ? 'brightness-0 invert' : ''} ${variant === 'auto' ? 'logo-auto' : ''}`}
    />
  );
}
