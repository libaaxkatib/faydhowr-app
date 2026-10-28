import { useState } from 'react';
import { useNavigate } from 'react-router-dom';

import { Icon } from '@/components/ui/Icon';
import { LanguageSwitcher } from '@/components/ui/LanguageSwitcher';
import { ThemeToggle } from '@/components/ui/ThemeToggle';
import { useAuth } from '@/features/auth/useAuth';
import { ChangePasswordDialog } from '@/features/auth/ChangePasswordDialog';
import { useI18n } from '@/i18n/useI18n';
import { initialsOf } from '@/utils/formatters';

interface HeaderProps {
  onMenuClick: () => void;
}

export function Header({ onMenuClick }: HeaderProps) {
  const { admin, logout } = useAuth();
  const { t, tValue } = useI18n();
  const navigate = useNavigate();
  const [isProfileOpen, setIsProfileOpen] = useState(false);
  const [isChangePasswordOpen, setIsChangePasswordOpen] = useState(false);

  return (
    <header className="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-border bg-surface px-4 lg:px-6">
      <button
        type="button"
        onClick={onMenuClick}
        aria-label={t('action.openMenu')}
        className="flex h-9 w-9 items-center justify-center rounded-sm text-ink-muted hover:bg-surface-alt lg:hidden"
      >
        <Icon name="menu" size={18} />
      </button>

      <div className="hidden max-w-sm flex-1 items-center lg:flex">
        <div className="relative w-full">
          <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint">
            <Icon name="search" size={16} />
          </span>
          <input
            type="text"
            disabled
            aria-label={t('warning.common.searchNotWired')}
            title={t('warning.common.searchNotWired')}
            placeholder={t('option.common.searchAnything')}
            className="h-10 w-full cursor-not-allowed rounded-sm border border-border bg-surface-muted pl-9 pr-3 text-sm opacity-60 text-ink outline-none transition focus:border-primary focus:bg-surface focus:ring-2 focus:ring-primary/20"
          />
        </div>
      </div>

      <div className="ml-auto flex items-center gap-1">
        <LanguageSwitcher className="mr-1" />
        <ThemeToggle className="mr-1" />
        <button
          type="button"
          disabled
          aria-label={t('warning.common.notificationsNotWired')}
          title={t('warning.common.notificationsNotWired')}
          className="relative flex h-9 w-9 cursor-not-allowed items-center justify-center rounded-sm text-ink-muted opacity-50"
        >
          <Icon name="bell" size={17} />
        </button>
        <button
          type="button"
          disabled
          aria-label={t('warning.common.messagesNotWired')}
          title={t('warning.common.messagesNotWired')}
          className="relative flex h-9 w-9 cursor-not-allowed items-center justify-center rounded-sm text-ink-muted opacity-50"
        >
          <Icon name="mail" size={17} />
        </button>
        <button
          type="button"
          onClick={() => document.documentElement.requestFullscreen?.()}
          aria-label={t('action.shell.fullscreen')}
          title={t('action.shell.fullscreen')}
          className="hidden h-9 w-9 items-center justify-center rounded-sm text-ink-muted hover:bg-surface-alt sm:flex"
        >
          <Icon name="expand" size={16} />
        </button>

        <div className="relative ml-1">
          <button
            type="button"
            onClick={() => setIsProfileOpen((open) => !open)}
            className="flex items-center gap-2.5 rounded-sm py-1.5 pl-1.5 pr-2 hover:bg-surface-alt"
          >
            <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-xs font-bold text-white">
              {admin ? initialsOf(admin.full_name) : '—'}
            </div>
            <div className="hidden text-left leading-tight sm:block">
              <p className="text-xs font-semibold text-ink">{admin?.full_name}</p>
              <p className="text-[11px] capitalize text-ink-muted">{admin ? (tValue('status.shell.role', admin.role) ?? admin.role.replace('_', ' ')) : undefined}</p>
            </div>
            <Icon name="chevron-down" size={13} className="text-ink-faint" />
          </button>

          {isProfileOpen && (
            <>
              <button
                type="button"
                aria-label={t('action.closeMenu')}
                className="fixed inset-0 z-10 cursor-default"
                onClick={() => setIsProfileOpen(false)}
              />
              <div className="absolute right-0 top-full z-20 mt-2 w-48 rounded-md border border-border bg-surface py-1.5 shadow-card">
                <button
                  type="button"
                  onClick={() => {
                    setIsProfileOpen(false);
                    navigate('/dashboard');
                  }}
                  className="flex w-full items-center gap-2.5 px-3.5 py-2 text-left text-sm text-ink hover:bg-surface-alt"
                >
                  <Icon name="home" size={15} />
                  Dashboard
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setIsProfileOpen(false);
                    setIsChangePasswordOpen(true);
                  }}
                  className="flex w-full items-center gap-2.5 px-3.5 py-2 text-left text-sm text-ink hover:bg-surface-alt"
                >
                  <Icon name="shield" size={15} />
                  {t('action.shell.changePassword')}
                </button>
                <button
                  type="button"
                  onClick={logout}
                  className="flex w-full items-center gap-2.5 px-3.5 py-2 text-left text-sm text-danger hover:bg-danger-soft"
                >
                  <Icon name="log-out" size={15} />
                  {t('action.shell.signOut')}
                </button>
              </div>
            </>
          )}
        </div>
      </div>

      <ChangePasswordDialog isOpen={isChangePasswordOpen} onClose={() => setIsChangePasswordOpen(false)} />
    </header>
  );
}
