// Appels à l'API JSON. Les écritures envoient toujours du JSON (protection CSRF côté serveur).

export class ApiError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

async function request(method, path, body) {
  let res;
  try {
    res = await fetch(path, {
      method,
      credentials: 'same-origin',
      headers: body === undefined ? {} : { 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    throw new ApiError(0, 'Pas de connexion au serveur');
  }
  if (res.status === 204) return null;
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new ApiError(res.status, data.error || `Erreur ${res.status}`);
  return data;
}

export const api = {
  me: () => request('GET', '/api/me'),
  login: (email, password) => request('POST', '/api/login', { email, password }),
  logout: () => request('POST', '/api/logout', {}),
  program: () => request('GET', '/api/program'),
  exercises: () => request('GET', '/api/exercises'),
  exercise: (slug) => request('GET', `/api/exercises/${encodeURIComponent(slug)}`),
};
