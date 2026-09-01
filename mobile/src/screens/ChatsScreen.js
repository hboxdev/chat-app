import React, { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, StyleSheet, Text, TextInput, View } from 'react-native';
import { api } from '../api/client';
import { Avatar } from '../components/Avatar';

export function ChatsScreen({ onOpenChat }) {
  const [chats, setChats] = useState([]);
  const [users, setUsers] = useState([]);
  const [q, setQ] = useState('');
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    try {
      const data = await api.chats();
      setChats(data.chats || []);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    const timer = setTimeout(async () => {
      if (q.trim().length < 2) {
        setUsers([]);
        return;
      }
      const data = await api.users(q.trim());
      setUsers(data.users || []);
    }, 350);
    return () => clearTimeout(timer);
  }, [q]);

  async function startChat(user) {
    const data = await api.startChat(user.id);
    setQ('');
    setUsers([]);
    onOpenChat({ id: data.conversation_id, title: user.full_name, avatar_url: user.profile_image_url });
  }

  if (loading) {
    return <View style={styles.center}><ActivityIndicator /></View>;
  }

  return (
    <View style={styles.screen}>
      <Text style={styles.title}>Chats</Text>
      <TextInput style={styles.search} value={q} onChangeText={setQ} placeholder="Search people" />
      {users.length > 0 && (
        <View style={styles.results}>
          {users.map((user) => (
            <Pressable key={user.id} style={styles.row} onPress={() => startChat(user)}>
              <Avatar uri={user.profile_image_url} name={user.full_name} />
              <View style={styles.meta}><Text style={styles.name}>{user.full_name}</Text><Text style={styles.sub}>@{user.username}</Text></View>
            </Pressable>
          ))}
        </View>
      )}
      <FlatList
        data={chats}
        keyExtractor={(item) => String(item.id)}
        refreshControl={<RefreshControl refreshing={false} onRefresh={load} />}
        renderItem={({ item }) => (
          <Pressable style={styles.row} onPress={() => onOpenChat(item)}>
            <Avatar uri={item.avatar_url} name={item.title} />
            <View style={styles.meta}><Text style={styles.name}>{item.title}</Text><Text style={styles.sub} numberOfLines={1}>{item.subtitle}</Text></View>
          </Pressable>
        )}
        ListEmptyComponent={<Text style={styles.empty}>No chats yet. Search a user to start.</Text>}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  center: { alignItems: 'center', flex: 1, justifyContent: 'center' },
  screen: { flex: 1, backgroundColor: '#f8fafc', paddingHorizontal: 16, paddingTop: 58 },
  title: { color: '#111827', fontSize: 32, fontWeight: '900', marginBottom: 16 },
  search: { backgroundColor: '#fff', borderColor: '#dbe3ee', borderRadius: 8, borderWidth: 1, minHeight: 48, paddingHorizontal: 14, marginBottom: 12 },
  results: { backgroundColor: '#fff', borderColor: '#dbe3ee', borderRadius: 8, borderWidth: 1, marginBottom: 12 },
  row: { alignItems: 'center', flexDirection: 'row', gap: 12, minHeight: 70, paddingVertical: 10 },
  meta: { flex: 1, minWidth: 0 },
  name: { color: '#111827', fontSize: 16, fontWeight: '800' },
  sub: { color: '#64748b', marginTop: 3 },
  empty: { color: '#64748b', marginTop: 30, textAlign: 'center' },
});

