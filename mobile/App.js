import React, { useRef, useState } from 'react';
import { ActivityIndicator, Pressable, SafeAreaView, StyleSheet, Text, View } from 'react-native';
import { StatusBar } from 'expo-status-bar';
import { WebView } from 'react-native-webview';

const CHAT_WEB_URL = 'https://skyblue-goshawk-811042.hostingersite.com/?mobile_app=1&v=20260901';

export default function App() {
  const webViewRef = useRef(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  function reload() {
    setError('');
    setLoading(true);
    webViewRef.current?.reload();
  }

  return (
    <SafeAreaView style={styles.screen}>
      <StatusBar style="dark" />
      <WebView
        ref={webViewRef}
        source={{ uri: CHAT_WEB_URL }}
        style={styles.webview}
        startInLoadingState
        cacheEnabled={false}
        cacheMode="LOAD_NO_CACHE"
        javaScriptEnabled
        domStorageEnabled
        sharedCookiesEnabled
        thirdPartyCookiesEnabled
        mediaPlaybackRequiresUserAction={false}
        allowsInlineMediaPlayback
        allowsBackForwardNavigationGestures
        pullToRefreshEnabled
        originWhitelist={['https://*', 'http://*']}
        onLoadStart={() => {
          setLoading(true);
          setError('');
        }}
        onLoadEnd={() => setLoading(false)}
        onError={(event) => {
          setLoading(false);
          setError(event.nativeEvent.description || 'Could not load Chat Web.');
        }}
        onHttpError={(event) => {
          if (event.nativeEvent.statusCode >= 500) {
            setError(`Server error ${event.nativeEvent.statusCode}`);
          }
        }}
      />

      {loading && !error ? (
        <View style={styles.overlay}>
          <ActivityIndicator color="#2563eb" />
          <Text style={styles.overlayText}>Opening Chat Web...</Text>
        </View>
      ) : null}

      {error ? (
        <View style={styles.errorPanel}>
          <Text style={styles.errorTitle}>Chat Web did not load</Text>
          <Text style={styles.errorText}>{error}</Text>
          <Pressable style={styles.button} onPress={reload}>
            <Text style={styles.buttonText}>Retry</Text>
          </Pressable>
        </View>
      ) : null}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  screen: {
    backgroundColor: '#eef2f7',
    flex: 1,
  },
  webview: {
    flex: 1,
  },
  overlay: {
    ...StyleSheet.absoluteFillObject,
    alignItems: 'center',
    backgroundColor: '#eef2f7',
    justifyContent: 'center',
  },
  overlayText: {
    color: '#64748b',
    fontWeight: '700',
    marginTop: 12,
  },
  errorPanel: {
    ...StyleSheet.absoluteFillObject,
    alignItems: 'center',
    backgroundColor: '#eef2f7',
    justifyContent: 'center',
    padding: 24,
  },
  errorTitle: {
    color: '#111827',
    fontSize: 22,
    fontWeight: '900',
    marginBottom: 8,
    textAlign: 'center',
  },
  errorText: {
    color: '#64748b',
    lineHeight: 22,
    marginBottom: 18,
    textAlign: 'center',
  },
  button: {
    alignItems: 'center',
    backgroundColor: '#2563eb',
    borderRadius: 8,
    minHeight: 48,
    justifyContent: 'center',
    paddingHorizontal: 24,
  },
  buttonText: {
    color: '#fff',
    fontWeight: '900',
  },
});
