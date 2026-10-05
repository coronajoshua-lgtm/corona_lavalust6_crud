const API_URL = (import.meta.env.VITE_API_URL || 'http://127.0.0.1:3000/api')
  .replace(/\/+$/, '');

export async function request(path, options = {}, retry = true) {
  const accessToken = localStorage.getItem('accessToken');
  let response;
  try {
    response = await fetch(`${API_URL}${path}`, {
      ...options,
      headers: {
        ...(options.body ? { 'Content-Type': 'application/json' } : {}),
        ...(accessToken ? { Authorization: `Bearer ${accessToken}` } : {}),
        ...options.headers,
      },
    });
  } catch (error) {
    if (error instanceof TypeError) {
      throw new Error(`Cannot reach the LavaLust API at ${API_URL}. Start it with "php lava serve --port=3000" and try again.`);
    }
    throw error;
  }

  if (response.status === 401 && retry && localStorage.getItem('refreshToken')) {
    const refreshResponse = await fetch(`${API_URL}/auth/refresh`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ refresh_token: localStorage.getItem('refreshToken') }),
    });
    if (refreshResponse.ok) {
      const refreshed = await refreshResponse.json();
      localStorage.setItem('accessToken', refreshed.tokens.access_token);
      localStorage.setItem('refreshToken', refreshed.tokens.refresh_token);
      return request(path, options, false);
    }
    localStorage.removeItem('accessToken');
    localStorage.removeItem('refreshToken');
  }

  const data = await response.json();
  if (!response.ok) {
    const error = new Error(data.error || data.message || `Request failed (${response.status})`);
    error.status = response.status;
    throw error;
  }
  return data;
}

export function saveTokens(tokens) {
  localStorage.setItem('accessToken', tokens.access_token);
  localStorage.setItem('refreshToken', tokens.refresh_token);
}
