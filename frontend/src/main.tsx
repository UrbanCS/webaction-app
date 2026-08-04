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

type DetailResponse = {
  ok: boolean;
  detail?: {
    title: string;
    html: string;
    image?: string;
    url: string;
  };
};

type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
};

const tabs = [
  { id: 'realisations', label: 'Réalisations' },
  { id: 'watch', label: 'À surveiller' }
] as const;

function apiUrl(path: string) {
  return `./api/${path}`;
}

function versionedImageUrl(item: Item) {
  if (!item.image) return '';
  const url = new URL(item.image, window.location.href);
  url.searchParams.set('pwa_v', item.hash || item.detected_at || 'current');
  return url.toString();
}

function urlBase64ToUint8Array(base64String: string) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  return Uint8Array.from([...rawData].map((char) => char.charCodeAt(0)));
}

async function savePushSubscription(subscription: PushSubscription) {
  const response = await fetch(apiUrl('subscribe.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(subscription)
  });
  if (!response.ok) throw new Error('subscribe failed');
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

    const refreshVisibleApp = () => {
      if (document.visibilityState === 'visible') load();
    };
    window.addEventListener('focus', refreshVisibleApp);
    document.addEventListener('visibilitychange', refreshVisibleApp);
    return () => {
      window.removeEventListener('focus', refreshVisibleApp);
      document.removeEventListener('visibilitychange', refreshVisibleApp);
    };
  }, []);

  return { data, error, loading, reload: load };
}

function App() {
  const { data, error, loading, reload } = useLatest();
  const [activeTab, setActiveTab] = useState('realisations');
  const [selected, setSelected] = useState<Item | null>(null);
  const [showInstallHelp, setShowInstallHelp] = useState(false);
  const [installPrompt, setInstallPrompt] = useState<BeforeInstallPromptEvent | null>(null);
  const [pushMessage, setPushMessage] = useState('');
  const [pushEnabled, setPushEnabled] = useState(false);

  useEffect(() => {
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('./sw.js').catch(() => undefined);
      navigator.serviceWorker.ready
        .then((registration) => registration.pushManager.getSubscription())
        .then(async (subscription) => {
          const enabled = Boolean(subscription) && 'Notification' in window && Notification.permission === 'granted';
          setPushEnabled(enabled);
          if (enabled && subscription) {
            await savePushSubscription(subscription);
          }
        })
        .catch(() => undefined);
    }
  }, []);

  useEffect(() => {
    const handler = (event: Event) => {
      event.preventDefault();
      setInstallPrompt(event as BeforeInstallPromptEvent);
    };
    window.addEventListener('beforeinstallprompt', handler);
    return () => window.removeEventListener('beforeinstallprompt', handler);
  }, []);

  const realisations = data?.items.realisations ?? [];
  const watch = data?.items.watch ?? [];
  const isApple = /iphone|ipad|ipod|macintosh/i.test(navigator.userAgent);

  async function installApp() {
    if (installPrompt) {
      await installPrompt.prompt();
      await installPrompt.userChoice.catch(() => undefined);
      setInstallPrompt(null);
      return;
    }
    setShowInstallHelp(true);
  }

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

      await savePushSubscription(subscription);
      setPushEnabled(true);
      setPushMessage('Notifications activées.');
    } catch {
      setPushMessage("Activation impossible. Vérifiez la configuration des clés VAPID.");
    }
  }

  async function disablePush() {
    setPushMessage('');
    try {
      const registration = await navigator.serviceWorker.ready;
      const subscription = await registration.pushManager.getSubscription();
      if (!subscription) {
        setPushEnabled(false);
        setPushMessage('Notifications désactivées.');
        return;
      }

      await fetch(apiUrl('unsubscribe.php'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ endpoint: subscription.endpoint })
      });
      await subscription.unsubscribe();
      setPushEnabled(false);
      setPushMessage('Notifications désactivées.');
    } catch {
      setPushMessage('Impossible de désactiver les notifications pour le moment.');
    }
  }

  if (selected) {
    return <Detail item={selected} onBack={() => setSelected(null)} />;
  }

  if (showInstallHelp) {
    return <InstallHelp onBack={() => setShowInstallHelp(false)} />;
  }

  return (
    <main className="mx-auto flex min-h-screen max-w-md flex-col bg-white shadow-2xl shadow-slate-200">
      <header className="bg-ink px-5 pb-5 pt-6 text-white">
        <div className="flex items-center justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold">Réalisations et nouvelles</h1>
          </div>
          <img src="./logo-webaction.png" alt="Webaction" className="h-12 w-32 object-contain" />
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
        <button onClick={installApp} className="mt-3 w-full rounded border border-white/20 bg-white/10 px-4 py-3 text-sm font-semibold text-white">
          {isApple && !installPrompt ? "Voir comment installer" : "Installer l'app"}
        </button>
      </header>

      <section className="border-b border-slate-200 bg-slate-50 px-5 py-4">
        <p className="text-sm text-slate-600">Recevez une alerte quand Webaction ajoute une réalisation ou une info à surveiller.</p>
        <button onClick={pushEnabled ? disablePush : enablePush} className="mt-3 w-full rounded bg-ink px-4 py-3 text-sm font-semibold text-white">
          {pushEnabled ? 'Désactiver les notifications' : 'Activer les notifications'}
        </button>
        {pushEnabled && !pushMessage && <p className="mt-2 text-sm text-slate-600">Notifications activées.</p>}
        {pushMessage && <p className="mt-2 text-sm text-slate-600">{pushMessage}</p>}
      </section>

      <nav className="grid grid-cols-2 border-b border-slate-200 bg-white">
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

function InstallHelp({ onBack }: { onBack: () => void }) {
  return (
    <main className="mx-auto min-h-screen max-w-md bg-slate-50">
      <header className="bg-ink px-5 py-5 text-white">
        <button onClick={onBack} className="rounded border border-white/20 px-4 py-2 text-sm font-semibold">
          Retour
        </button>
        <h1 className="mt-5 text-2xl font-bold">Installer l'application</h1>
        <p className="mt-3 text-sm leading-6 text-slate-300">
          Ajoutez Webaction à l'écran d'accueil pour l'ouvrir comme une application.
        </p>
      </header>
      <section className="space-y-4 px-5 py-5">
        <InstallCard
          title="iPhone / iPad"
          body="Ouvrez l'app dans Safari, appuyez sur Partager, puis choisissez Ajouter à l'écran d'accueil."
        />
        <InstallCard
          title="Android"
          body="Ouvrez l'app dans Chrome, appuyez sur le menu du navigateur, puis choisissez Ajouter à l'écran d'accueil ou Installer l'application."
        />
        <InstallCard
          title="Windows"
          body="Ouvrez l'app dans Chrome ou Edge, puis utilisez l'icône d'installation dans la barre d'adresse ou le menu du navigateur."
        />
      </section>
    </main>
  );
}

function InstallCard({ title, body }: { title: string; body: string }) {
  return (
    <article className="rounded border border-slate-200 bg-white p-5 shadow-sm">
      <h2 className="text-lg font-bold text-slate-950">{title}</h2>
      <p className="mt-3 text-sm leading-6 text-slate-600">{body}</p>
    </article>
  );
}

function ItemList({ items, empty, onSelect }: { items: Item[]; empty: string; onSelect: (item: Item) => void }) {
  if (!items.length) return <State title="Vide" body={empty} />;
  return (
    <div className="space-y-4">
      {items.map((item) => (
        <button key={`${item.type}-${item.id}`} onClick={() => onSelect(item)} className="w-full overflow-hidden rounded border border-slate-200 bg-white text-left shadow-sm">
          {item.image && <img src={versionedImageUrl(item)} alt="" className="h-40 w-full object-cover" loading="lazy" />}
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
  const [detailHtml, setDetailHtml] = useState('');
  const [detailImage, setDetailImage] = useState(item.image || '');
  const [detailTitle, setDetailTitle] = useState(item.title);
  const [loading, setLoading] = useState(item.url.includes('webaction.ca'));
  const [error, setError] = useState('');

  useEffect(() => {
    let ignore = false;
    async function loadDetail() {
      if (!item.url.includes('webaction.ca')) {
        return;
      }
      setLoading(true);
      setError('');
      try {
        const response = await fetch(apiUrl(`detail.php?url=${encodeURIComponent(item.url)}`));
        const payload: DetailResponse = await response.json();
        if (!response.ok || !payload.ok || !payload.detail) throw new Error('detail');
        if (!ignore) {
          setDetailTitle(payload.detail.title || item.title);
          setDetailHtml(payload.detail.html || '');
          setDetailImage(payload.detail.image || item.image || '');
        }
      } catch {
        if (!ignore) setError("Le détail complet n'a pas pu être chargé.");
      } finally {
        if (!ignore) setLoading(false);
      }
    }
    loadDetail();
    return () => {
      ignore = true;
    };
  }, [item]);

  return (
    <main className="mx-auto min-h-screen max-w-md bg-white">
      <button onClick={onBack} className="m-4 rounded border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-800">
        Retour
      </button>
      {detailImage && <img src={detailImage} alt="" className="h-56 w-full object-cover" />}
      <section className="px-5 py-5">
        <p className="text-xs font-bold uppercase tracking-wide text-webblue">{item.type === 'watch' ? 'À surveiller' : 'Réalisation'}</p>
        <h1 className="mt-2 text-2xl font-bold text-slate-950">{detailTitle}</h1>
        {loading && <p className="mt-4 text-sm text-slate-500">Chargement du détail...</p>}
        {error && <p className="mt-4 text-sm text-slate-500">{error}</p>}
        {detailHtml ? (
          <div
            className="detail-content mt-5 text-base leading-7 text-slate-700"
            dangerouslySetInnerHTML={{ __html: detailHtml }}
          />
        ) : (
          item.excerpt && <p className="mt-4 text-base leading-7 text-slate-700">{item.excerpt}</p>
        )}
        <a href={item.url} className="mt-6 inline-flex rounded bg-ink px-4 py-3 text-sm font-semibold text-white">
          Ouvrir sur le site
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
