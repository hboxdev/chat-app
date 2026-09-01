import React, { useState } from 'react';
import { ActivityIndicator, Alert, KeyboardAvoidingView, Platform, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { api, setToken } from '../api/client';

export function AuthScreen({ onAuthed }) {
  const [step, setStep] = useState('phone');
  const [phone, setPhone] = useState('+92');
  const [email, setEmail] = useState('');
  const [challengeId, setChallengeId] = useState(null);
  const [otp, setOtp] = useState('');
  const [loading, setLoading] = useState(false);

  async function start() {
    setLoading(true);
    try {
      const data = await api.startAuth({ country: 'Pakistan', phone_number: phone, email });
      setChallengeId(data.challenge_id);
      setStep('otp');
    } catch (error) {
      Alert.alert('Login failed', error.message);
    } finally {
      setLoading(false);
    }
  }

  async function verify() {
    setLoading(true);
    try {
      const data = await api.verifyAuth({ challenge_id: challengeId, otp, device_name: Platform.OS });
      await setToken(data.token);
      onAuthed(data);
    } catch (error) {
      Alert.alert('Verification failed', error.message);
    } finally {
      setLoading(false);
    }
  }

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={styles.screen}>
      <View style={styles.card}>
        <Text style={styles.brand}>Chat Web</Text>
        <Text style={styles.title}>{step === 'phone' ? 'Create your account' : 'Verify code'}</Text>
        <Text style={styles.copy}>{step === 'phone' ? 'Enter your phone number to receive OTP.' : 'Enter the 6 digit code sent to your phone or email.'}</Text>

        {step === 'phone' ? (
          <>
            <TextInput style={styles.input} value={phone} onChangeText={setPhone} placeholder="+923001234567" keyboardType="phone-pad" />
            <TextInput style={styles.input} value={email} onChangeText={setEmail} placeholder="Email fallback (optional)" keyboardType="email-address" autoCapitalize="none" />
            <Pressable style={styles.button} onPress={start} disabled={loading}>
              {loading ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>Send code</Text>}
            </Pressable>
          </>
        ) : (
          <>
            <TextInput style={[styles.input, styles.otp]} value={otp} onChangeText={setOtp} placeholder="000000" maxLength={6} keyboardType="number-pad" />
            <Pressable style={styles.button} onPress={verify} disabled={loading}>
              {loading ? <ActivityIndicator color="#fff" /> : <Text style={styles.buttonText}>Verify and continue</Text>}
            </Pressable>
            <Pressable style={styles.softButton} onPress={() => setStep('phone')}>
              <Text style={styles.softText}>Change phone</Text>
            </Pressable>
          </>
        )}
      </View>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  screen: { flex: 1, justifyContent: 'center', padding: 22, backgroundColor: '#eef2f7' },
  card: { backgroundColor: '#fff', borderRadius: 8, padding: 24, shadowColor: '#0f172a', shadowOpacity: 0.12, shadowRadius: 22, elevation: 5 },
  brand: { color: '#2563eb', fontSize: 18, fontWeight: '900', marginBottom: 28 },
  title: { color: '#111827', fontSize: 30, fontWeight: '900', marginBottom: 8 },
  copy: { color: '#64748b', fontSize: 15, lineHeight: 22, marginBottom: 22 },
  input: { borderColor: '#cbd5e1', borderRadius: 8, borderWidth: 1, fontSize: 16, minHeight: 52, marginBottom: 14, paddingHorizontal: 14 },
  otp: { fontSize: 24, fontWeight: '900', letterSpacing: 0, textAlign: 'center' },
  button: { alignItems: 'center', backgroundColor: '#2563eb', borderRadius: 8, justifyContent: 'center', minHeight: 52 },
  buttonText: { color: '#fff', fontWeight: '900' },
  softButton: { alignItems: 'center', backgroundColor: '#eff6ff', borderColor: '#bfdbfe', borderRadius: 8, borderWidth: 1, justifyContent: 'center', marginTop: 10, minHeight: 52 },
  softText: { color: '#2563eb', fontWeight: '900' },
});

