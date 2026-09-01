import React, { useState } from 'react';
import { StatusBar } from 'expo-status-bar';
import { AuthScreen } from './src/screens/AuthScreen';
import { ChatsScreen } from './src/screens/ChatsScreen';
import { MessagesScreen } from './src/screens/MessagesScreen';
import { SetupProfileScreen } from './src/screens/SetupProfileScreen';

export default function App() {
  const [user, setUser] = useState(null);
  const [setupComplete, setSetupComplete] = useState(false);
  const [chat, setChat] = useState(null);

  return (
    <>
      <StatusBar style="dark" />
      {!user ? (
        <AuthScreen onAuthed={(data) => { setUser(data.user); setSetupComplete(!!data.setup_complete); }} />
      ) : !setupComplete ? (
        <SetupProfileScreen user={user} onDone={(fresh) => { setUser(fresh); setSetupComplete(true); }} />
      ) : chat ? (
        <MessagesScreen chat={chat} onBack={() => setChat(null)} />
      ) : (
        <ChatsScreen onOpenChat={setChat} />
      )}
    </>
  );
}
