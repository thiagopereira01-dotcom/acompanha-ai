self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (_) {
    data = { body: event.data ? event.data.text() : '' };
  }
    const title = data.title || 'Acompanha-Ai';
    const options = {
      body: data.body || 'Ha uma ocorrencia que precisa da sua atencao.',
    tag: data.protocolo || 'acompanha-ai',
    renotify: true,
    requireInteraction: data.nivel === 'vermelho',
    data: {
      url: data.url || './',
      protocolo: data.protocolo || ''
    }
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const dest = (event.notification.data && event.notification.data.url) || './';
  event.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (let i = 0; i < all.length; i++) {
      const client = all[i];
      if ('focus' in client) {
        await client.focus();
        if (client.navigate) {
          try { await client.navigate(dest); } catch (_) {}
        } else if (client.postMessage) {
          client.postMessage({ tipo: 'abrir_ocorrencia', url: dest });
        }
        return;
      }
    }
    if (self.clients.openWindow) {
      await self.clients.openWindow(dest);
    }
  })());
});
