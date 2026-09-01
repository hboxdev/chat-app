import AsyncStorage from '@react-native-async-storage/async-storage';

export const API_BASE = 'https://skyblue-goshawk-811042.hostingersite.com/api/mobile';
const TOKEN_KEY = 'chatweb_mobile_token';

export async function getToken() {
  return AsyncStorage.getItem(TOKEN_KEY);
}

export async function setToken(token) {
  if (token) {
    await AsyncStorage.setItem(TOKEN_KEY, token);
  } else {
    await AsyncStorage.removeItem(TOKEN_KEY);
  }
}

async function request(path, options = {}) {
  const token = await getToken();
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), options.timeoutMs || 10000);
  const headers = {
    Accept: 'application/json',
    ...(options.headers || {}),
  };
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }
  if (options.body && !(options.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(options.body);
  }

  try {
    const response = await fetch(`${API_BASE}${path}`, { ...options, headers, signal: controller.signal });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || data.message || 'Request failed');
    }
    return data;
  } catch (error) {
    if (error.name === 'AbortError') {
      throw new Error('Server took too long to respond.');
    }
    throw error;
  } finally {
    clearTimeout(timeout);
  }
}

export const api = {
  startAuth: (payload) => request('/start.php', { method: 'POST', body: payload }),
  verifyAuth: (payload) => request('/verify.php', { method: 'POST', body: payload }),
  me: () => request('/me.php', { timeoutMs: 5000 }),
  checkUsername: (username) => request(`/check_username.php?username=${encodeURIComponent(username)}`),
  setupProfile: (payload) => {
    const form = new FormData();
    form.append('full_name', payload.full_name);
    form.append('username', payload.username);
    if (payload.photo) {
      form.append('profile_image', {
        uri: payload.photo.uri,
        name: payload.photo.fileName || 'profile.jpg',
        type: payload.photo.mimeType || 'image/jpeg',
      });
    }
    return request('/setup_profile.php', { method: 'POST', body: form });
  },
  chats: () => request('/chats.php'),
  messages: (conversationId) => request(`/messages.php?conversation_id=${conversationId}`),
  sendMessage: (conversationId, message) => request('/messages.php', { method: 'POST', body: { conversation_id: conversationId, message } }),
  users: (q) => request(`/users.php?q=${encodeURIComponent(q)}`),
  startChat: (userId) => request('/start_chat.php', { method: 'POST', body: { user_id: userId } }),
};
