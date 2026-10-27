import { useState } from 'react';
import { NavLink } from 'react-router-dom';

import { Logo } from '@/components/Logo';
import { Icon } from '@/components/ui/Icon';
import { useEffectivePermissions } from '@/hooks/usePermissions';
import { useI18n } from '@/i18n/useI18n';
import { NAV_GROUPS } from '@/layouts/nav.config';
import { isGroupVisible, isItemRenderable } from '@/layouts/navVisibility';

interface SidebarProps {
  isOpen: boolean;
  onNavigate?: () => void;
}

export function Sidebar({ isOpen, onNavigate }: SidebarProps) {
  const [collapsedGroups, setCollapsedGroups] = useState<Record<string, boolean>>({});
  const { hasPermission, isSuperAdmin, isLoading } = useEffectivePermissions();
  const { t } = useI18n();

  return (
    <aside
      className={`fixed inset-y-0 left-0 z-40 flex w-[260px] flex-col bg-sidebar transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0 ${
        isOpen ? 'translate-x-0' : '-translate-x-full'
      }`}
    >
      <div className="flex h-16 shrink-0 items-center gap-2.5 px-5">
        <Logo className="h-8 w-auto" variant="inverted" />
        <span className="font-display text-[15px] font-extrabold tracking-wide text-white">FAYADHOWR</span>
      </div>

      <nav className="scrollbar-thin flex-1 overflow-y-auto px-3 pb-6">
        <NavLink
          to="/dashboard"
          onClick={onNavigate}
          className={({ isActive }) =>
            `mb-3 flex items-center gap-2.5 rounded-md px-3 py-2.5 text-sm font-semibold transition ${
              isActive ? 'bg-primary text-white' : 'text-white/85 hover:bg-white/10'
            }`
          }
        >
          <Icon name="home" size={17} />
          Dashboard
        </NavLink>

        {/* Permissions haven't resolved yet — render nothing below Dashboard rather than
            flashing every group (or none) before the real effective-permission set loads. */}
        {!isLoading &&
          NAV_GROUPS.filter((group) => isGroupVisible(group, hasPermission, isSuperAdmin)).map((group) => {
            const isCollapsed = collapsedGroups[group.label];
            const visibleItems = group.items.filter((item) => isItemRenderable(item, hasPermission, isSuperAdmin));

            return (
              <div key={group.label} className="mb-1">
                <button
                  type="button"
                  onClick={() => setCollapsedGroups((prev) => ({ ...prev, [group.label]: !prev[group.label] }))}
                  className="flex w-full items-center justify-between px-3 py-2 text-[10.5px] font-bold uppercase tracking-wider text-white/40"
                >
                  <span className="flex items-center gap-1.5">
                    {group.labelKey ? t(group.labelKey) : group.label}
                    {group.comingSoon && (
                      <span className="rounded-full bg-secondary/25 px-1.5 py-0.5 text-[9px] font-bold text-secondary-soft normal-case tracking-normal">
                        {t('nav.shell.planned')}
                      </span>
                    )}
                  </span>
                  <Icon name={isCollapsed ? 'chevron-right' : 'chevron-down'} size={12} />
                </button>

                {!isCollapsed && (
                  <div>
                    {visibleItems.map((item) =>
                      item.comingSoon ? (
                        <div
                          key={`${item.to}:${item.label}`}
                          title={t('warning.shell.moduleNotBuilt')}
                          className="flex cursor-not-allowed items-center gap-2.5 rounded-md px-3 py-2 text-[13px] text-white/30"
                        >
                          {item.icon && <Icon name={item.icon} size={15} />}
                          {item.labelKey ? t(item.labelKey) : item.label}
                        </div>
                      ) : (
                        <NavLink
                          key={`${item.to}:${item.label}`}
                          to={item.to}
                          end={item.end}
                          onClick={onNavigate}
                          className={({ isActive }) =>
                            `flex items-center gap-2.5 rounded-md px-3 py-2 text-[13px] font-medium transition ${
                              isActive
                                ? 'bg-white/10 text-white'
                                : 'text-white/70 hover:bg-white/5 hover:text-white'
                            }`
                          }
                        >
                          {item.icon && <Icon name={item.icon} size={15} />}
                          {item.labelKey ? t(item.labelKey) : item.label}
                        </NavLink>
                      ),
                    )}
                  </div>
                )}
              </div>
            );
          })}
      </nav>

      <div className="flex items-center gap-2.5 border-t border-white/10 px-5 py-4">
        <Logo className="h-6 w-auto" variant="inverted" />
        <div className="leading-tight">
          <p className="text-xs font-bold text-white">FAYADHOWR</p>
          <p className="text-[10px] text-white/50">{t('nav.shell.tagline')}</p>
        </div>
      </div>
    </aside>
  );
}
