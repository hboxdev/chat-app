import * as ImagePicker from 'expo-image-picker';
import React, { useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Image, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { api } from '../api/client';

export function SetupProfileScreen({ user, onDone }) {
  const [fullName, setFullName] = useState(user?.full_name?.startsWith('User ') ? '' : user?.full_name || '');
  const [username, setUsername] = useState(user?.username || '');
  const [photo, setPhoto] = useState(null);
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    const timer = setTimeout(async () => {
      if (username.length < 3) {
        setStatus('');
        return;
      }
      try {
        const data = await api.checkUsername(username);
        setStatus(data.message);
      } catch (error) {
        setStatus(error.message);
      }
    }, 400);
    return () => clearTimeout(timer);
  }, [username]);

  async function pickPhoto() {
    const result = await ImagePicker.launchImageLibraryAsync({
      mediaTypes: ImagePicker.MediaTypeOptions.Images,
      quality: 0.85,
      allowsEditing: true,
      aspect: [1, 1],
    });
    if (!result.canceled) {
      setPhoto(result.assets[0]);
    }
  }

  async function finish() {
    setLoading(true);
    try {
      const data = await api.setupProfile({ full_name: fullName, username, photo });
      onDone(data.user);
    } catch (error) {
      Alert.alert('Setup failed', error.message);
    } finally {
      setLoading(false);
    }
  }

  return (
    <View style={styles.screen}>
      <View style={styles.card}>
        <Text style={styles.title}>Complete profile</Text>
        <Text style={styles.copy}>Add your name, username, and optional photo.</Text>
        <Pressable style={styles.photo} onPress={pickPhoto}>
          {photo || user?.profile_image_url ? (
            <Image source={{ uri: photo?.uri || user.profile_image_url }} style={StyleSheet.absoluteFillObject} />
          ) : (
            <Text style={styles.camera}>+</Text>
          )}
        </Pressable>
        <TextInput style={styles.input} value={fullName} onChangeText={setFullName} placeholder="Full name" />
        <TextInput
          style={styles.input}
          value={username}
          onChangeText={(value) => setUsername(value.toLowerCase().replace(/[^a-z0-9_.]/g, ''))}
          placeholder="username"
          autoCapitalize="none"
        />
        <Text style={styles.status}>{status}</Text>
        <Pressable style={styles.button} onPress={finish} disabled={loading}>
          {loading ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>Finish</Text>}
        </Pressable>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, justifyContent: 'center', padding: 22, backgroundColor: '#eef2f7' },
  card: { backgroundColor: '#fff', borderRadius: 8, padding: 24 },
  title: { color: '#111827', fontSize: 30, fontWeight: '900', marginBottom: 8 },
  copy: { color: '#64748b', fontSize: 15, marginBottom: 20 },
  photo: { alignSelf: 'center', alignItems: 'center', backgroundColor: '#eff6ff', borderColor: '#bfdbfe', borderRadius: 72, borderStyle: 'dashed', borderWidth: 2, height: 144, justifyContent: 'center', marginBottom: 22, overflow: 'hidden', width: 144 },
  camera: { color: '#2563eb', fontSize: 46, fontWeight: '300' },
  input: { borderColor: '#cbd5e1', borderRadius: 8, borderWidth: 1, fontSize: 16, minHeight: 52, marginBottom: 12, paddingHorizontal: 14 },
  status: { color: '#64748b', fontSize: 13, minHeight: 22 },
  button: { alignItems: 'center', backgroundColor: '#2563eb', borderRadius: 8, justifyContent: 'center', minHeight: 52, marginTop: 8 },
  buttonText: { color: '#fff', fontWeight: '900' },
});

