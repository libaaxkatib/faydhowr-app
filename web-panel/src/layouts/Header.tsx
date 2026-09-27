import { useState } from 'react';
import { useNavigate } from 'react-router-dom';

import { Icon } from '@/components/ui/Icon';
import { useAuth } from '@/features/auth/useAuth';
import { ChangePasswordDialog } from '@/features/auth/ChangePasswordDialog';
import { initialsOf } from '@/utils/formatters';

interface HeaderProps {
  onMenuClick: () => void;
}

export function Header({ onMenuClick }: HeaderProps) {
  const { admin, logout } = useAuth();
  const navigate = useNavigate();
  const [isProfileOpen, setIsProfileOpen] = useState(false);
  const [isChangePasswordOpen, setIsChangePasswordOpen] = useState(false);

  return (
    <header className="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-border bg-surface px-4 lg:px-6">
      <button
        type="button"
        onClick={onMenuClick}
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
            placeholder="Search anything…"
            className="h-10 w-full rounded-sm border border-border bg-surface-muted pl-9 pr-14 text-sm text-ink outline-none transition focus:border-primary focus:bg-surface focus:ring-2 focus:ring-primary/20"
          />
          <kbd className="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 rounded border border-border bg-surface px-1.5 py-0.5 text-[10px] font-medium text-ink-faint">
            Ctrl K
          </kbd>
        </div>
      </div>

      <div className="ml-auto flex items-center gap-1">
        <button
          type="button"
          title="Notifications — not yet wired to a backend endpoint"
          className="relative flex h-9 w-9 items-center justify-center rounded-sm text-ink-muted hover:bg-surface-alt"
        >
          <Icon name="bell" size={17} />
        </button>
        <button
          type="button"
          title="Messages — not yet wired to a backend endpoint"
          className="relative flex h-9 w-9 items-center justify-center rounded-sm text-ink-muted hover:bg-surface-alt"
        >
          <Icon name="mail" size={17} />
        </button>
        <button
          type="button"
          onClick={() => document.documentElement.requestFullscreen?.()}
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
              <p className="text-[11px] capitalize text-ink-muted">{admin?.role.replace('_', ' ')}</p>
            </div>
            <Icon name="chevron-down" size={13} className="text-ink-faint" />
          </button>

          {isProfileOpen && (
            <>
              <button
                type="button"
                aria-label="Close menu"
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
                  Change password
                </button>
                <button
                  type="button"
                  onClick={logout}
                  className="flex w-full items-center gap-2.5 px-3.5 py-2 text-left text-sm text-danger hover:bg-danger-soft"
                >
                  <Icon name="log-out" size={15} />
                  Sign out
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
