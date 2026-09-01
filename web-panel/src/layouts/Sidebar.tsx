import { useState } from 'react';
import { NavLink, useLocation } from 'react-router-dom';

import { Logo } from '@/components/Logo';
import { Icon } from '@/components/ui/Icon';
import { NAV_GROUPS } from '@/layouts/nav.config';

interface SidebarProps {
  isOpen: boolean;
  onNavigate?: () => void;
}

export function Sidebar({ isOpen, onNavigate }: SidebarProps) {
  const location = useLocation();
  const [collapsedGroups, setCollapsedGroups] = useState<Record<string, boolean>>({});

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

        {NAV_GROUPS.map((group) => {
          const isCollapsed = collapsedGroups[group.label];
          return (
            <div key={group.label} className="mb-1">
              <button
                type="button"
                onClick={() => setCollapsedGroups((prev) => ({ ...prev, [group.label]: !prev[group.label] }))}
                className="flex w-full items-center justify-between px-3 py-2 text-[10.5px] font-bold uppercase tracking-wider text-white/40"
              >
                <span className="flex items-center gap-1.5">
                  {group.label}
                  {group.comingSoon && (
                    <span className="rounded-full bg-secondary/25 px-1.5 py-0.5 text-[9px] font-bold text-secondary-soft normal-case tracking-normal">
                      Planned
                    </span>
                  )}
                </span>
                <Icon name={isCollapsed ? 'chevron-right' : 'chevron-down'} size={12} />
              </button>

              {!isCollapsed && (
                <div>
                  {group.items.map((item) =>
                    item.comingSoon ? (
                      <div
                        key={item.to}
                        title="Backend for this module isn't built yet"
                        className="flex cursor-not-allowed items-center gap-2.5 rounded-md px-3 py-2 text-[13px] text-white/30"
                      >
                        {item.icon && <Icon name={item.icon} size={15} />}
                        {item.label}
                      </div>
                    ) : (
                      <NavLink
                        key={item.to}
                        to={item.to}
                        onClick={onNavigate}
                        className={({ isActive }) =>
                          `flex items-center gap-2.5 rounded-md px-3 py-2 text-[13px] font-medium transition ${
                            isActive || location.pathname.startsWith(item.to)
                              ? 'bg-white/10 text-white'
                              : 'text-white/70 hover:bg-white/5 hover:text-white'
                          }`
                        }
                      >
                        {item.icon && <Icon name={item.icon} size={15} />}
                        {item.label}
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
          <p className="text-[10px] text-white/50">Service with trust</p>
        </div>
      </div>
    </aside>
  );
}
