import React, { useEffect, useState } from 'react';
import { ActivityIndicator, Text, View } from 'react-native';
import { StatusBar } from 'expo-status-bar';
import { api, setToken } from './src/api/client';
import { AuthScreen } from './src/screens/AuthScreen';
import { ChatsScreen } from './src/screens/ChatsScreen';
import { MessagesScreen } from './src/screens/MessagesScreen';
import { SetupProfileScreen } from './src/screens/SetupProfileScreen';

export default function App() {
  const [booting, setBooting] = useState(true);
  const [user, setUser] = useState(null);
  const [setupComplete, setSetupComplete] = useState(false);
  const [chat, setChat] = useState(null);

  useEffect(() => {
    async function boot() {
      try {
        const data = await api.me();
        setUser(data.user);
        setSetupComplete(!!data.setup_complete);
      } catch (error) {
        await setToken(null);
      } finally {
        setBooting(false);
      }
    }
    boot();
  }, []);

  if (booting) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: '#eef2f7', padding: 24 }}>
        <ActivityIndicator color="#2563eb" />
        <Text style={{ color: '#64748b', marginTop: 14 }}>Opening Chat Web...</Text>
      </View>
    );
  }

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
