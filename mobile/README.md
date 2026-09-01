# Chat Web Expo App

Native Expo client for the existing PHP/MySQL Chat Web backend.

## Run

```bash
cd mobile
npm install
npm start
```

The API base is set in `src/api/client.js`:

```js
export const API_BASE = 'https://skyblue-goshawk-811042.hostingersite.com/api/mobile';
```

## Included

- Phone/email fallback OTP login
- Bearer-token mobile auth
- Profile setup with name, username, and photo
- Chat list
- Search users and start a private chat
- Read/send text messages with polling

