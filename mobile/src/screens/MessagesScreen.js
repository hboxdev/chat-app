import React, { useEffect, useRef, useState } from 'react';
import { FlatList, KeyboardAvoidingView, Platform, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { api } from '../api/client';
import { Avatar } from '../components/Avatar';

export function MessagesScreen({ chat, onBack }) {
  const [messages, setMessages] = useState([]);
  const [text, setText] = useState('');
  const listRef = useRef(null);

  async function load() {
    const data = await api.messages(chat.id);
    setMessages(data.messages || []);
  }

  useEffect(() => {
    load();
    const timer = setInterval(load, 3000);
    return () => clearInterval(timer);
  }, [chat.id]);

  async function send() {
    const message = text.trim();
    if (!message) return;
    setText('');
    await api.sendMessage(chat.id, message);
    await load();
  }

  return (
    <KeyboardAvoidingView style={styles.screen} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <View style={styles.header}>
        <Pressable onPress={onBack} style={styles.back}><Text style={styles.backText}>‹</Text></Pressable>
        <Avatar uri={chat.avatar_url} name={chat.title} size={42} />
        <Text style={styles.title} numberOfLines={1}>{chat.title}</Text>
      </View>
      <FlatList
        ref={listRef}
        data={messages}
        keyExtractor={(item) => String(item.id)}
        contentContainerStyle={styles.list}
        onContentSizeChange={() => listRef.current?.scrollToEnd({ animated: true })}
        renderItem={({ item }) => (
          <View style={[styles.bubble, item.mine ? styles.mine : styles.theirs]}>
            <Text style={[styles.message, item.mine && styles.mineText]}>{item.message}</Text>
          </View>
        )}
      />
      <View style={styles.composer}>
        <TextInput style={styles.input} value={text} onChangeText={setText} placeholder="Message" multiline />
        <Pressable style={styles.send} onPress={send}><Text style={styles.sendText}>Send</Text></Pressable>
      </View>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, backgroundColor: '#eef2f7' },
  header: { alignItems: 'center', backgroundColor: '#fff', borderBottomColor: '#dbe3ee', borderBottomWidth: 1, flexDirection: 'row', gap: 10, minHeight: 92, paddingHorizontal: 12, paddingTop: 34 },
  back: { alignItems: 'center', height: 42, justifyContent: 'center', width: 36 },
  backText: { color: '#111827', fontSize: 34 },
  title: { color: '#111827', flex: 1, fontSize: 18, fontWeight: '900' },
  list: { gap: 8, padding: 14 },
  bubble: { borderRadius: 8, maxWidth: '82%', paddingHorizontal: 13, paddingVertical: 10 },
  mine: { alignSelf: 'flex-end', backgroundColor: '#2563eb' },
  theirs: { alignSelf: 'flex-start', backgroundColor: '#fff' },
  message: { color: '#111827', fontSize: 16, lineHeight: 21 },
  mineText: { color: '#fff' },
  composer: { alignItems: 'flex-end', backgroundColor: '#fff', borderTopColor: '#dbe3ee', borderTopWidth: 1, flexDirection: 'row', gap: 10, padding: 10 },
  input: { backgroundColor: '#f8fafc', borderColor: '#dbe3ee', borderRadius: 8, borderWidth: 1, flex: 1, maxHeight: 110, minHeight: 44, paddingHorizontal: 12, paddingVertical: 10 },
  send: { alignItems: 'center', backgroundColor: '#2563eb', borderRadius: 8, justifyContent: 'center', minHeight: 44, paddingHorizontal: 16 },
  sendText: { color: '#fff', fontWeight: '900' },
});

