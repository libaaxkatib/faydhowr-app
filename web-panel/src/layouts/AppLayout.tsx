import { useState } from 'react';
import { Outlet } from 'react-router-dom';

import { Sidebar } from '@/layouts/Sidebar';
import { Header } from '@/layouts/Header';
import { useI18n } from '@/i18n/useI18n';
import { useLoadTranslationOverrides } from '@/hooks/useTranslationOverrides';

export function AppLayout() {
  const { t } = useI18n();
  useLoadTranslationOverrides();
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);

  return (
    <div className="flex min-h-screen bg-surface-muted lg:flex-row">
      <Sidebar isOpen={isSidebarOpen} onNavigate={() => setIsSidebarOpen(false)} />

      {isSidebarOpen && (
        <button
          type="button"
          aria-label={t('action.closeMenu')}
          className="fixed inset-0 z-30 bg-ink/40 lg:hidden"
          onClick={() => setIsSidebarOpen(false)}
        />
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <Header onMenuClick={() => setIsSidebarOpen((open) => !open)} />
        <main className="flex-1 p-4 lg:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
