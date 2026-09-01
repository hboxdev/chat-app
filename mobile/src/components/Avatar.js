import React from 'react';
import { Image, StyleSheet, Text, View } from 'react-native';

export function Avatar({ uri, name, size = 48 }) {
  const initial = (name || '?').trim().slice(0, 1).toUpperCase();
  return (
    <View style={[styles.avatar, { width: size, height: size, borderRadius: size / 2 }]}>
      {uri ? <Image source={{ uri }} style={StyleSheet.absoluteFillObject} /> : <Text style={styles.initial}>{initial}</Text>}
    </View>
  );
}

const styles = StyleSheet.create({
  avatar: {
    alignItems: 'center',
    backgroundColor: '#dbeafe',
    justifyContent: 'center',
    overflow: 'hidden',
  },
  initial: {
    color: '#1d4ed8',
    fontWeight: '900',
  },
});

