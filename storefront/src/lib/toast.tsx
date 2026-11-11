import { createContext, useCallback, useContext, useState, type ReactNode } from 'react';
import { CheckCircle2, AlertTriangle, Info, X } from 'lucide-react';

type Kind = 'success' | 'error' | 'info';
interface Toast { id: number; kind: Kind; text: string }
const Ctx = createContext<{ push: (text: string, kind?: Kind) => void }>({ push: () => {} });
export const useToast = () => useContext(Ctx);

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<Toast[]>([]);
  const push = useCallback((text: string, kind: Kind = 'success') => {
    const id = Date.now() + Math.random();
    setItems((s) => [...s.slice(-3), { id, kind, text }]);
    setTimeout(() => setItems((s) => s.filter((x) => x.id !== id)), kind === 'error' ? 6500 : 3800);
  }, []);
  const Icon = { success: CheckCircle2, error: AlertTriangle, info: Info };
  return (
    <Ctx.Provider value={{ push }}>
      {children}
      <div aria-live="polite" className="pointer-events-none fixed inset-x-0 bottom-4 z-[100] flex flex-col items-center gap-2 px-4">
        {items.map((x) => {
          const I = Icon[x.kind];
          return (
            <div key={x.id} role="status" className="rise pointer-events-auto flex max-w-md items-start gap-3 rounded-2xl border border-line bg-paper px-4 py-3 text-sm shadow-soft">
              <I size={18} className={x.kind === 'success' ? 'text-success' : x.kind === 'error' ? 'text-danger' : 'text-info'} />
              <span className="flex-1">{x.text}</span>
              <button aria-label="Dismiss" onClick={() => setItems((s) => s.filter((y) => y.id !== x.id))} className="text-muted"><X size={14} /></button>
            </div>
          );
        })}
      </div>
    </Ctx.Provider>
  );
}
