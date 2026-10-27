import { createContext } from 'react';

import type { ResolvedTheme, ThemeMode } from '@/theme/theme';

export interface ThemeContextValue {
  /** What the user chose: light, dark or system. */
  mode: ThemeMode;
  /** What is actually painted: light or dark. */
  resolved: ResolvedTheme;
  setMode: (mode: ThemeMode) => void;
}

export const ThemeContext = createContext<ThemeContextValue | null>(null);
