import React, { useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import './index.css';

type ContentType = 'realisation' | 'watch';

type Item = {
  id: string;
  type: ContentType;
  title: string;
  excerpt: string;
  url: string;
  image?: string;
  hash?: string;
  detected_at?: string;
};

type LatestResponse = {
  ok: boolean;
  generated_at: string;
  items: {
    realisations: Item[];
    watch: Item[];
  };
};

type PushConfig = {
  ok: boolean;
  vapidPublicKey: string;
};

const tabs = [
  { id: 'home', label: 'Accueil' },
  { id: 'realisations', label: 'Réalisations' },
  { id: 'watch', label: 'À surveiller' }
] as const;

function apiUrl(path: string) {
  return `./api/${path}`;
}

function urlBase64ToUint8Array(base64String: string) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  return Uint8Array.from([...rawData].map((char) => char.charCodeAt(0)));
}

function useLatest() {
  const [data, setData] = useState<LatestResponse | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  async function load() {
    setLoading(true);
    setError('');
    try {
      const response = await fetch(apiUrl('latest.php'), { headers: { Accept: 'application/json' } });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      setData(await response.json());
    } catch {
      setError("Impossible de charger les données pour l'instant.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    load();
  }, []);

  return { data, error, loading, reload: load };
}

function App() {
  const { data, error, loading, reload } = useLatest();
  const [activeTab, setActiveTab] = useState('home');
  const [selected, setSelected] = useState<Item | null>(null);
  const [pushMessage, setPushMessage] = useState('');

  useEffect(() => {
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('./sw.js').catch(() => undefined);
    }
  }, []);

  const realisations = data?.items.realisations ?? [];
  const watch = data?.items.watch ?? [];
  const featured = useMemo(() => [...realisations.slice(0, 3), ...watch.slice(0, 2)], [realisations, watch]);

  async function enablePush() {
    setPushMessage('');
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      setPushMessage("Les notifications ne sont pas prises en charge par ce navigateur.");
      return;
    }

    try {
      const configResponse = await fetch(apiUrl('config-public.php'));
      const config: PushConfig = await configResponse.json();
      if (!config.vapidPublicKey) throw new Error('missing key');

      const permission = await Notification.requestPermission();
      if (permission !== 'granted') {
        setPushMessage("Les notifications n'ont pas été activées.");
        return;
      }

      const registration = await navigator.serviceWorker.ready;
      const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(config.vapidPublicKey)
      });

      const response = await fetch(apiUrl('subscribe.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(subscription)
      });
      if (!response.ok) throw new Error('subscribe failed');
      setPushMessage('Notifications activées.');
    } catch {
      setPushMessage("Activation impossible. Vérifiez la configuration des clés VAPID.");
    }
  }

  if (selected) {
    return <Detail item={selected} onBack={() => setSelected(null)} />;
  }

  return (
    <main className="mx-auto flex min-h-screen max-w-md flex-col bg-white shadow-2xl shadow-slate-200">
      <header className="bg-ink px-5 pb-5 pt-6 text-white">
        <div className="flex items-center justify-between gap-4">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-webblue">Webaction</p>
            <h1 className="mt-1 text-2xl font-bold">Réalisations et nouvelles</h1>
          </div>
          <img src="./icon.svg" alt="" className="h-12 w-12 rounded-2xl" />
        </div>
        <p className="mt-4 text-sm leading-6 text-slate-300">
          Une app légère pour suivre les derniers projets et les informations à surveiller.
        </p>
        <div className="mt-4 grid grid-cols-2 gap-2">
          <a className="rounded bg-webblue px-4 py-3 text-center text-sm font-semibold text-white" href="https://webaction.ca/fr/nous-joindre">
            Nous joindre
          </a>
          <a className="rounded border border-white/20 px-4 py-3 text-center text-sm font-semibold text-white" href="https://webaction.ca/fr/">
            Site complet
          </a>
        </div>
      </header>

      <section className="border-b border-slate-200 bg-slate-50 px-5 py-4">
        <p className="text-sm text-slate-600">Recevez une alerte quand Webaction ajoute une réalisation ou une info à surveiller.</p>
        <button onClick={enablePush} className="mt-3 w-full rounded bg-ink px-4 py-3 text-sm font-semibold text-white">
          Activer les notifications
        </button>
        {pushMessage && <p className="mt-2 text-sm text-slate-600">{pushMessage}</p>}
      </section>

      <nav className="grid grid-cols-3 border-b border-slate-200 bg-white">
        {tabs.map((tab) => (
          <button
            key={tab.id}
            onClick={() => setActiveTab(tab.id)}
            className={`px-2 py-3 text-sm font-semibold ${activeTab === tab.id ? 'text-webblue' : 'text-slate-500'}`}
          >
            {tab.label}
          </button>
        ))}
      </nav>

      <section className="flex-1 px-5 py-5">
        {loading && <State title="Chargement" body="Récupération des dernières informations..." />}
        {error && <State title="Erreur" body={error} action={reload} />}
        {!loading && !error && activeTab === 'home' && (
          <ItemList items={featured} empty="Aucun contenu détecté." onSelect={setSelected} />
        )}
        {!loading && !error && activeTab === 'realisations' && (
          <ItemList items={realisations} empty="Aucune réalisation détectée." onSelect={setSelected} />
        )}
        {!loading && !error && activeTab === 'watch' && (
          <ItemList items={watch} empty="Aucune information à surveiller détectée." onSelect={setSelected} />
        )}
      </section>
    </main>
  );
}

function ItemList({ items, empty, onSelect }: { items: Item[]; empty: string; onSelect: (item: Item) => void }) {
  if (!items.length) return <State title="Vide" body={empty} />;
  return (
    <div className="space-y-4">
      {items.map((item) => (
        <button key={`${item.type}-${item.id}`} onClick={() => onSelect(item)} className="w-full overflow-hidden rounded border border-slate-200 bg-white text-left shadow-sm">
          {item.image && <img src={item.image} alt="" className="h-40 w-full object-cover" loading="lazy" />}
          <span className="block p-4">
            <span className="text-xs font-bold uppercase tracking-wide text-webblue">{item.type === 'watch' ? 'À surveiller' : 'Réalisation'}</span>
            <span className="mt-1 block text-lg font-bold text-slate-950">{item.title}</span>
            {item.excerpt && <span className="mt-2 line-clamp-3 block text-sm leading-6 text-slate-600">{item.excerpt}</span>}
          </span>
        </button>
      ))}
    </div>
  );
}

function Detail({ item, onBack }: { item: Item; onBack: () => void }) {
  return (
    <main className="mx-auto min-h-screen max-w-md bg-white">
      <button onClick={onBack} className="m-4 rounded border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-800">
        Retour
      </button>
      {item.image && <img src={item.image} alt="" className="h-56 w-full object-cover" />}
      <section className="px-5 py-5">
        <p className="text-xs font-bold uppercase tracking-wide text-webblue">{item.type === 'watch' ? 'À surveiller' : 'Réalisation'}</p>
        <h1 className="mt-2 text-2xl font-bold text-slate-950">{item.title}</h1>
        {item.excerpt && <p className="mt-4 text-base leading-7 text-slate-700">{item.excerpt}</p>}
        <a href={item.url} className="mt-6 inline-flex rounded bg-ink px-4 py-3 text-sm font-semibold text-white">
          Voir la source
        </a>
      </section>
    </main>
  );
}

function State({ title, body, action }: { title: string; body: string; action?: () => void }) {
  return (
    <div className="rounded border border-slate-200 bg-slate-50 p-5 text-center">
      <h2 className="font-bold text-slate-950">{title}</h2>
      <p className="mt-2 text-sm leading-6 text-slate-600">{body}</p>
      {action && (
        <button onClick={action} className="mt-4 rounded bg-webblue px-4 py-2 text-sm font-semibold text-white">
          Réessayer
        </button>
      )}
    </div>
  );
}

createRoot(document.getElementById('root')!).render(<App />);
