import { useContext } from 'react';

import { ThemeContext, type ThemeContextValue } from '@/theme/themeContext';

export function useTheme(): ThemeContextValue {
  const context = useContext(ThemeContext);
  if (context === null) throw new Error('useTheme must be used inside <ThemeProvider>.');

  return context;
}
