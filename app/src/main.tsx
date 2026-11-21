import React from 'react';
import ReactDOM from 'react-dom/client';
import { registerSW } from 'virtual:pwa-register';
import App from './App';
import '../../css/design-tokens.css';
import './styles.css';
import './design-system.css';

document.documentElement.classList.add('kf-app');

const updateSW = registerSW({
  immediate: true,
  onNeedRefresh() {
    void updateSW(true);
  },
  onRegisteredSW(_swUrl, registration) {
    if (!registration) {
      return;
    }

    // Retire only this app's former narrower worker after the replacement activates.
    const retireLegacyWorker = async () => {
      if (registration.active?.state !== 'activated') return;
      const legacyUrl = new URL(import.meta.env.BASE_URL + 'sw.js', window.location.origin).href;
      for (const entry of await navigator.serviceWorker.getRegistrations()) {
        if (entry !== registration && entry.active?.scriptURL === legacyUrl) await entry.unregister();
      }
    };
    void retireLegacyWorker();
    registration.installing?.addEventListener('statechange', () => { void retireLegacyWorker(); });

    // Force regular update checks so installed PWAs pull new bundles without user action.
    setInterval(() => {
      void registration.update();
    }, 60 * 1000);
  }
});

if ('serviceWorker' in navigator) {
  let refreshing = false;
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (refreshing) {
      return;
    }
    refreshing = true;
    window.location.reload();
  });
}

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
);
