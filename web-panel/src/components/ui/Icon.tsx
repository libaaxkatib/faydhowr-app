import type { SVGProps } from 'react';

export type IconName =
  | 'menu'
  | 'search'
  | 'bell'
  | 'mail'
  | 'expand'
  | 'chevron-down'
  | 'chevron-right'
  | 'chevron-left'
  | 'chevron-up'
  | 'home'
  | 'users'
  | 'calendar'
  | 'file-text'
  | 'cart'
  | 'credit-card'
  | 'box'
  | 'star'
  | 'image'
  | 'bar-chart'
  | 'settings'
  | 'shield'
  | 'list'
  | 'plus'
  | 'pencil'
  | 'trash'
  | 'eye'
  | 'x'
  | 'check'
  | 'loader'
  | 'alert-triangle'
  | 'arrow-left'
  | 'log-out'
  | 'more-horizontal'
  | 'refresh'
  | 'filter'
  | 'megaphone'
  | 'briefcase'
  | 'inbox'
  | 'phone'
  | 'message-circle'
  | 'map-pin'
  | 'flag';

interface IconProps extends SVGProps<SVGSVGElement> {
  name: IconName;
  size?: number;
}

/**
 * Small, hand-drawn line-icon set built from primitives (line/circle/rect/polyline)
 * to avoid depending on an icon package that wasn't part of the approved stack.
 */
export function Icon({ name, size = 18, ...rest }: IconProps) {
  const common = {
    width: size,
    height: size,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.75,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    ...rest,
  };

  switch (name) {
    case 'menu':
      return (
        <svg {...common}>
          <line x1="3" y1="6" x2="21" y2="6" />
          <line x1="3" y1="12" x2="21" y2="12" />
          <line x1="3" y1="18" x2="21" y2="18" />
        </svg>
      );
    case 'search':
      return (
        <svg {...common}>
          <circle cx="11" cy="11" r="7" />
          <line x1="21" y1="21" x2="16.2" y2="16.2" />
        </svg>
      );
    case 'bell':
      return (
        <svg {...common}>
          <path d="M6 10a6 6 0 0 1 12 0c0 4 1.5 5.5 1.5 5.5H4.5S6 14 6 10Z" />
          <path d="M10 19a2 2 0 0 0 4 0" />
        </svg>
      );
    case 'mail':
      return (
        <svg {...common}>
          <rect x="3" y="5" width="18" height="14" rx="2" />
          <polyline points="3,7 12,13 21,7" />
        </svg>
      );
    case 'expand':
      return (
        <svg {...common}>
          <polyline points="9,3 3,3 3,9" />
          <polyline points="15,3 21,3 21,9" />
          <polyline points="3,15 3,21 9,21" />
          <polyline points="21,15 21,21 15,21" />
        </svg>
      );
    case 'chevron-down':
      return (
        <svg {...common}>
          <polyline points="6,9 12,15 18,9" />
        </svg>
      );
    case 'chevron-up':
      return (
        <svg {...common}>
          <polyline points="6,15 12,9 18,15" />
        </svg>
      );
    case 'chevron-right':
      return (
        <svg {...common}>
          <polyline points="9,6 15,12 9,18" />
        </svg>
      );
    case 'chevron-left':
      return (
        <svg {...common}>
          <polyline points="15,6 9,12 15,18" />
        </svg>
      );
    case 'home':
      return (
        <svg {...common}>
          <polyline points="4,11 12,4 20,11" />
          <path d="M6 10v9a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1v-9" />
        </svg>
      );
    case 'users':
      return (
        <svg {...common}>
          <circle cx="9" cy="8" r="3.25" />
          <path d="M3.5 19a5.5 5.5 0 0 1 11 0" />
          <circle cx="17" cy="9" r="2.5" />
          <path d="M15.5 19a4.2 4.2 0 0 1 5.5-3.4" />
        </svg>
      );
    case 'calendar':
      return (
        <svg {...common}>
          <rect x="3.5" y="5" width="17" height="15.5" rx="2" />
          <line x1="3.5" y1="10" x2="20.5" y2="10" />
          <line x1="8" y1="3" x2="8" y2="6.5" />
          <line x1="16" y1="3" x2="16" y2="6.5" />
        </svg>
      );
    case 'file-text':
      return (
        <svg {...common}>
          <path d="M6 3h9l4 4v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" />
          <path d="M14 3v4a1 1 0 0 0 1 1h4" />
          <line x1="8" y1="13" x2="16" y2="13" />
          <line x1="8" y1="17" x2="14" y2="17" />
        </svg>
      );
    case 'cart':
      return (
        <svg {...common}>
          <path d="M3 4h2.2l2.2 11.4a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.6L20.5 8H6" />
          <circle cx="10" cy="20.5" r="1.4" />
          <circle cx="17" cy="20.5" r="1.4" />
        </svg>
      );
    case 'credit-card':
      return (
        <svg {...common}>
          <rect x="3" y="6" width="18" height="12" rx="2" />
          <line x1="3" y1="10.5" x2="21" y2="10.5" />
          <line x1="6.5" y1="14.5" x2="10" y2="14.5" />
        </svg>
      );
    case 'box':
      return (
        <svg {...common}>
          <path d="M12 3 20.5 7.5V16.5L12 21 3.5 16.5V7.5Z" />
          <polyline points="3.5,7.5 12,12 20.5,7.5" />
          <line x1="12" y1="12" x2="12" y2="21" />
        </svg>
      );
    case 'star':
      return (
        <svg {...common}>
          <polygon points="12,2.5 14.9,9 22,9.6 16.6,14.2 18.2,21.2 12,17.4 5.8,21.2 7.4,14.2 2,9.6 9.1,9" />
        </svg>
      );
    case 'image':
      return (
        <svg {...common}>
          <rect x="3" y="4.5" width="18" height="15" rx="2" />
          <circle cx="9" cy="10" r="1.6" />
          <polyline points="4,18 9.5,12.5 13,16 17,12 20,15" />
        </svg>
      );
    case 'bar-chart':
      return (
        <svg {...common}>
          <line x1="6" y1="20" x2="6" y2="12" />
          <line x1="12" y1="20" x2="12" y2="6" />
          <line x1="18" y1="20" x2="18" y2="15" />
          <line x1="3" y1="20" x2="21" y2="20" />
        </svg>
      );
    case 'settings':
      return (
        <svg {...common}>
          <circle cx="12" cy="12" r="3.2" />
          <path d="M12 3.5v2.4M12 18.1v2.4M20.5 12h-2.4M5.9 12H3.5M17.7 6.3l-1.7 1.7M8 16l-1.7 1.7M17.7 17.7 16 16M8 8 6.3 6.3" />
        </svg>
      );
    case 'shield':
      return (
        <svg {...common}>
          <path d="M12 3 19 6v6c0 4.4-3 7.6-7 9-4-1.4-7-4.6-7-9V6Z" />
          <polyline points="9,12 11.2,14.2 15,10" />
        </svg>
      );
    case 'list':
      return (
        <svg {...common}>
          <line x1="8.5" y1="6" x2="21" y2="6" />
          <line x1="8.5" y1="12" x2="21" y2="12" />
          <line x1="8.5" y1="18" x2="21" y2="18" />
          <line x1="3.5" y1="6" x2="3.51" y2="6" />
          <line x1="3.5" y1="12" x2="3.51" y2="12" />
          <line x1="3.5" y1="18" x2="3.51" y2="18" />
        </svg>
      );
    case 'plus':
      return (
        <svg {...common}>
          <line x1="12" y1="5" x2="12" y2="19" />
          <line x1="5" y1="12" x2="19" y2="12" />
        </svg>
      );
    case 'pencil':
      return (
        <svg {...common}>
          <path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17l-1 3Z" />
          <line x1="14.5" y1="6.5" x2="17.5" y2="9.5" />
        </svg>
      );
    case 'trash':
      return (
        <svg {...common}>
          <line x1="4" y1="7" x2="20" y2="7" />
          <path d="M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" />
          <path d="M9.5 7V4.5a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1V7" />
          <line x1="10" y1="11" x2="10" y2="17" />
          <line x1="14" y1="11" x2="14" y2="17" />
        </svg>
      );
    case 'eye':
      return (
        <svg {...common}>
          <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />
          <circle cx="12" cy="12" r="2.6" />
        </svg>
      );
    case 'x':
      return (
        <svg {...common}>
          <line x1="6" y1="6" x2="18" y2="18" />
          <line x1="18" y1="6" x2="6" y2="18" />
        </svg>
      );
    case 'check':
      return (
        <svg {...common}>
          <polyline points="5,13 10,18 19,7" />
        </svg>
      );
    case 'loader':
      return (
        <svg {...common} className={`animate-spin ${rest.className ?? ''}`}>
          <circle cx="12" cy="12" r="8.5" opacity={0.25} />
          <path d="M20.5 12a8.5 8.5 0 0 0-8.5-8.5" />
        </svg>
      );
    case 'alert-triangle':
      return (
        <svg {...common}>
          <path d="M12 4 21.5 20.5H2.5Z" />
          <line x1="12" y1="10" x2="12" y2="14.5" />
          <line x1="12" y1="17" x2="12.01" y2="17" />
        </svg>
      );
    case 'arrow-left':
      return (
        <svg {...common}>
          <line x1="19" y1="12" x2="5" y2="12" />
          <polyline points="11,6 5,12 11,18" />
        </svg>
      );
    case 'log-out':
      return (
        <svg {...common}>
          <path d="M9 20H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h4" />
          <polyline points="15,16 20,12 15,8" />
          <line x1="20" y1="12" x2="9" y2="12" />
        </svg>
      );
    case 'more-horizontal':
      return (
        <svg {...common}>
          <circle cx="5" cy="12" r="1.4" />
          <circle cx="12" cy="12" r="1.4" />
          <circle cx="19" cy="12" r="1.4" />
        </svg>
      );
    case 'refresh':
      return (
        <svg {...common}>
          <path d="M20 11a8 8 0 0 0-14.9-3.6M4 13a8 8 0 0 0 14.9 3.6" />
          <polyline points="4.5,4 5.1,7.4 8.5,6.8" />
          <polyline points="19.5,20 18.9,16.6 15.5,17.2" />
        </svg>
      );
    case 'filter':
      return (
        <svg {...common}>
          <polygon points="4,4 20,4 14,12.5 14,19 10,21 10,12.5" />
        </svg>
      );
    case 'megaphone':
      return (
        <svg {...common}>
          <path d="M4 10v4a1 1 0 0 0 1 1h1.5l9 4V5l-9 4H5a1 1 0 0 0-1 1Z" />
          <path d="M19 9.5v5" />
          <path d="M8 15v3.5a1.5 1.5 0 0 0 3 0V15" />
        </svg>
      );
    case 'briefcase':
      return (
        <svg {...common}>
          <rect x="3" y="7.5" width="18" height="12" rx="2" />
          <path d="M8.5 7.5V6a1.5 1.5 0 0 1 1.5-1.5h4A1.5 1.5 0 0 1 15.5 6v1.5" />
          <line x1="3" y1="12.5" x2="21" y2="12.5" />
        </svg>
      );
    case 'inbox':
      return (
        <svg {...common}>
          <path d="M3.5 12h5l1.5 3h4l1.5-3h5" />
          <path d="M6 5h12l2.5 7v6a1 1 0 0 1-1 1H4.5a1 1 0 0 1-1-1v-6Z" />
        </svg>
      );
    case 'phone':
      return (
        <svg {...common}>
          <path d="M5 4h3.2l1.3 4-2 1.3a11 11 0 0 0 5.2 5.2l1.3-2 4 1.3V17a2 2 0 0 1-2.2 2A16 16 0 0 1 3 5.2 2 2 0 0 1 5 4Z" />
        </svg>
      );
    case 'message-circle':
      return (
        <svg {...common}>
          <path d="M12 3.5c-4.7 0-8.5 3.2-8.5 7.2 0 2.3 1.3 4.4 3.3 5.7-.1 1-.5 2.1-1.3 3 1.5-.2 2.8-.8 3.8-1.6a10 10 0 0 0 2.7.4c4.7 0 8.5-3.2 8.5-7.2S16.7 3.5 12 3.5Z" />
        </svg>
      );
    case 'map-pin':
      return (
        <svg {...common}>
          <path d="M12 21.5S5 15 5 9.8a7 7 0 0 1 14 0C19 15 12 21.5 12 21.5Z" />
          <circle cx="12" cy="9.7" r="2.4" />
        </svg>
      );
    case 'flag':
      return (
        <svg {...common}>
          <path d="M5 3.5v17" />
          <path d="M5 4.5h11l-2.5 4L16 12.5H5" />
        </svg>
      );
    default:
      return null;
  }
}
