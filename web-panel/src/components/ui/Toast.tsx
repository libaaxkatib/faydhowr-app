import { useCallback, useState, type ReactNode } from 'react';
import { Icon } from '@/components/ui/Icon';
import { ToastContext, type ToastTone } from '@/components/ui/toastContext';

interface ToastItem {
  id: number;
  tone: ToastTone;
  message: string;
}

const toneClasses: Record<ToastTone, string> = {
  success: 'border-success/30 bg-success-soft text-success',
  error: 'border-danger/30 bg-danger-soft text-danger',
  info: 'border-secondary/30 bg-secondary-soft text-secondary',
};

const toneIcon: Record<ToastTone, 'check' | 'alert-triangle' | 'bell'> = {
  success: 'check',
  error: 'alert-triangle',
  info: 'bell',
};

let nextId = 1;

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<ToastItem[]>([]);

  const show = useCallback((message: string, tone: ToastTone = 'success') => {
    const id = nextId++;
    setToasts((current) => [...current, { id, tone, message }]);
    window.setTimeout(() => {
      setToasts((current) => current.filter((toast) => toast.id !== id));
    }, 4000);
  }, []);

  return (
    <ToastContext.Provider value={{ show }}>
      {children}
      <div className="pointer-events-none fixed bottom-5 right-5 z-[100] flex flex-col gap-2">
        {toasts.map((toast) => (
          <div
            key={toast.id}
            role="status"
            className={`pointer-events-auto flex items-center gap-2 rounded-sm border px-4 py-3 text-sm font-medium shadow-card ${toneClasses[toast.tone]}`}
          >
            <Icon name={toneIcon[toast.tone]} size={16} />
            {toast.message}
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
}
