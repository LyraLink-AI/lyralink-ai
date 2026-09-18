import React, { useEffect, useMemo, useState } from 'react';
import {
  ActivityIndicator,
  Linking,
  SafeAreaView,
  ScrollView,
  StatusBar,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from 'react-native';
import * as SecureStore from 'expo-secure-store';

const API_BASE = 'http://lyralinkai.com';
const TOKEN_KEY = 'lyralink_mobile_token';

async function postForm(path, data, token) {
  const body = new URLSearchParams();
  Object.entries(data).forEach(([key, value]) => body.append(key, String(value ?? '')));
  const res = await fetch(`${API_BASE}${path}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      'X-Mobile-Client': 'ios',
      'X-Device-Name': 'Lyralink iPhone App',
    },
    body: body.toString(),
  });
  return res.json();
}

async function postJson(path, data, token) {
  const res = await fetch(`${API_BASE}${path}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      'X-Mobile-Client': 'ios',
      'X-Device-Name': 'Lyralink iPhone App',
    },
    body: JSON.stringify(data),
  });
  return res.json();
}

export default function App() {
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [tab, setTab] = useState('chat');
  const [token, setToken] = useState('');
  const [user, setUser] = useState(null);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [username, setUsername] = useState('');
  const [authMsg, setAuthMsg] = useState('');
  const [input, setInput] = useState('');
  const [messages, setMessages] = useState([
    { role: 'assistant', content: 'Welcome to Lyralink on iPhone. Sign in to sync your chats securely.' },
  ]);

  useEffect(() => {
    bootstrap();
  }, []);

  const loggedIn = !!user?.logged_in;
  const planLabel = useMemo(() => user?.plan || 'free', [user]);

  async function bootstrap() {
    try {
      const saved = (await SecureStore.getItemAsync(TOKEN_KEY)) || '';
      setToken(saved);
      if (saved) {
        const session = await postForm('/api/auth.php', { action: 'check' }, saved);
        if (session?.logged_in) {
          setUser(session);
        } else {
          await SecureStore.deleteItemAsync(TOKEN_KEY);
          setToken('');
        }
      }
    } catch (err) {
      setAuthMsg(err.message || 'Failed to initialize app');
    } finally {
      setLoading(false);
    }
  }

  async function login() {
    setBusy(true);
    setAuthMsg('');
    try {
      const data = await postForm('/api/auth.php', {
        action: 'login',
        email,
        password,
        mobile_client: 'ios',
        device_name: 'Lyralink iPhone App',
      });
      if (!data?.success) {
        setAuthMsg(data?.error || 'Login failed');
        return;
      }
      const issuedToken = data?.mobile_auth?.token || '';
      if (issuedToken) {
        await SecureStore.setItemAsync(TOKEN_KEY, issuedToken);
        setToken(issuedToken);
      }
      const session = await postForm('/api/auth.php', { action: 'check' }, issuedToken || token);
      setUser(session);
      setTab('chat');
    } catch (err) {
      setAuthMsg(err.message || 'Login failed');
    } finally {
      setBusy(false);
    }
  }

  async function register() {
    setBusy(true);
    setAuthMsg('');
    try {
      const data = await postForm('/api/auth.php', {
        action: 'register',
        username,
        email,
        password,
        mobile_client: 'ios',
        device_name: 'Lyralink iPhone App',
      });
      if (data?.success) {
        setAuthMsg(data?.needs_email_verification
          ? 'Account created. Verify your email, then sign in.'
          : 'Account created. You can now sign in.');
      } else {
        setAuthMsg(data?.error || 'Registration failed');
      }
    } catch (err) {
      setAuthMsg(err.message || 'Registration failed');
    } finally {
      setBusy(false);
    }
  }

  async function logout() {
    try {
      await postForm('/api/auth.php', { action: 'logout' }, token);
    } finally {
      await SecureStore.deleteItemAsync(TOKEN_KEY);
      setToken('');
      setUser(null);
      setMessages([{ role: 'assistant', content: 'You have been signed out.' }]);
      setTab('account');
    }
  }

  async function requestDeletion() {
    setBusy(true);
    setAuthMsg('');
    try {
      const data = await postForm('/api/auth.php', { action: 'request_data_deletion' }, token);
      setAuthMsg(data?.success ? 'Account deletion request submitted.' : (data?.error || 'Deletion request failed'));
    } catch (err) {
      setAuthMsg(err.message || 'Deletion request failed');
    } finally {
      setBusy(false);
    }
  }

  async function clearSavedChats() {
    setBusy(true);
    setAuthMsg('');
    try {
      const data = await postForm('/api/auth.php', { action: 'delete_saved_chat_data' }, token);
      if (data?.success) {
        setMessages([{ role: 'assistant', content: 'Saved chat data was removed from your account.' }]);
        setAuthMsg('Saved chat data deleted.');
      } else {
        setAuthMsg(data?.error || 'Unable to delete chat data');
      }
    } catch (err) {
      setAuthMsg(err.message || 'Unable to delete chat data');
    } finally {
      setBusy(false);
    }
  }

  async function sendMessage() {
    if (!input.trim() || busy) return;
    const nextMessages = [...messages, { role: 'user', content: input.trim() }];
    setMessages(nextMessages);
    setInput('');
    setBusy(true);
    try {
      const data = await postJson('/api/chat.php', { messages: nextMessages }, token);
      const reply = data?.reply || data?.message || data?.error || 'No response received.';
      setMessages([...nextMessages, { role: 'assistant', content: reply }]);
    } catch (err) {
      setMessages([...nextMessages, { role: 'assistant', content: err.message || 'Request failed.' }]);
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return (
      <SafeAreaView style={styles.safeArea}>
        <StatusBar barStyle="light-content" />
        <View style={styles.centered}>
          <ActivityIndicator size="large" color="#a78bfa" />
          <Text style={styles.loadingText}>Loading secure session…</Text>
        </View>
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.safeArea}>
      <StatusBar barStyle="light-content" />

      <View style={styles.header}>
        <View>
          <Text style={styles.brand}>Lyralink AI</Text>
          <Text style={styles.subtle}>iPhone preview · plan: {planLabel}</Text>
        </View>
        <TouchableOpacity style={styles.pill} onPress={() => setTab('legal')}>
          <Text style={styles.pillText}>Review Ready</Text>
        </TouchableOpacity>
      </View>

      {tab === 'chat' && (
        <View style={styles.flexFill}>
          <ScrollView style={styles.scroll} contentContainerStyle={styles.messagesWrap}>
            {messages.map((msg, idx) => (
              <View key={`${msg.role}-${idx}`} style={[styles.bubble, msg.role === 'user' ? styles.userBubble : styles.aiBubble]}>
                <Text style={styles.bubbleRole}>{msg.role === 'user' ? 'You' : 'Lyralink'}</Text>
                <Text style={styles.bubbleText}>{msg.content}</Text>
              </View>
            ))}
          </ScrollView>

          <View style={styles.composer}>
            <TextInput
              style={styles.input}
              value={input}
              onChangeText={setInput}
              placeholder="Ask anything…"
              placeholderTextColor="#64748b"
              multiline
            />
            <TouchableOpacity style={styles.sendBtn} onPress={sendMessage}>
              <Text style={styles.sendText}>{busy ? '...' : 'Send'}</Text>
            </TouchableOpacity>
          </View>
        </View>
      )}

      {tab === 'account' && (
        <ScrollView style={styles.scroll} contentContainerStyle={styles.accountWrap}>
          {loggedIn ? (
            <View style={styles.card}>
              <Text style={styles.cardTitle}>Signed in as {user.username}</Text>
              <Text style={styles.cardText}>Your account stays on your existing Lyralink backend. The app uses secure token-based access.</Text>
              <TouchableOpacity style={styles.primaryBtn} onPress={clearSavedChats}>
                <Text style={styles.primaryBtnText}>Delete Saved Chats</Text>
              </TouchableOpacity>
              <TouchableOpacity style={[styles.secondaryBtn, { marginTop: 10 }]} onPress={requestDeletion}>
                <Text style={styles.secondaryBtnText}>Request Account Deletion</Text>
              </TouchableOpacity>
              <TouchableOpacity style={[styles.secondaryBtn, { marginTop: 10 }]} onPress={logout}>
                <Text style={styles.secondaryBtnText}>Log out</Text>
              </TouchableOpacity>
              {!!authMsg && <Text style={styles.notice}>{authMsg}</Text>}
            </View>
          ) : (
            <View style={styles.card}>
              <Text style={styles.cardTitle}>Sign in</Text>
              <TextInput style={styles.field} value={email} onChangeText={setEmail} placeholder="Email" placeholderTextColor="#64748b" autoCapitalize="none" keyboardType="email-address" />
              <TextInput style={styles.field} value={password} onChangeText={setPassword} placeholder="Password" placeholderTextColor="#64748b" secureTextEntry />
              <TouchableOpacity style={styles.primaryBtn} onPress={login}>
                <Text style={styles.primaryBtnText}>{busy ? 'Please wait…' : 'Login'}</Text>
              </TouchableOpacity>

              <Text style={[styles.cardTitle, { marginTop: 20 }]}>Create account</Text>
              <TextInput style={styles.field} value={username} onChangeText={setUsername} placeholder="Username" placeholderTextColor="#64748b" autoCapitalize="none" />
              <TouchableOpacity style={styles.secondaryBtn} onPress={register}>
                <Text style={styles.secondaryBtnText}>Register</Text>
              </TouchableOpacity>

              {!!authMsg && <Text style={styles.notice}>{authMsg}</Text>}
            </View>
          )}
        </ScrollView>
      )}

      {tab === 'legal' && (
        <ScrollView style={styles.scroll} contentContainerStyle={styles.accountWrap}>
          <View style={styles.card}>
            <Text style={styles.cardTitle}>App Store compliance</Text>
            <Text style={styles.cardText}>Digital upgrades should use Apple in-app purchase on iPhone. Privacy, support, and account deletion are exposed from the same backend.</Text>
            <TouchableOpacity style={styles.linkRow} onPress={() => Linking.openURL(`${API_BASE}/pages/privacy.php`)}>
              <Text style={styles.linkText}>Open Privacy Policy</Text>
            </TouchableOpacity>
            <TouchableOpacity style={styles.linkRow} onPress={() => Linking.openURL(`${API_BASE}/pages/tos.php`)}>
              <Text style={styles.linkText}>Open Terms of Service</Text>
            </TouchableOpacity>
            <TouchableOpacity style={styles.linkRow} onPress={() => Linking.openURL(`${API_BASE}/pages/support.php`)}>
              <Text style={styles.linkText}>Open Support</Text>
            </TouchableOpacity>
          </View>
        </ScrollView>
      )}

      <View style={styles.tabBar}>
        {['chat', 'account', 'legal'].map((name) => (
          <TouchableOpacity key={name} style={styles.tabBtn} onPress={() => setTab(name)}>
            <Text style={[styles.tabText, tab === name && styles.tabTextActive]}>{name.toUpperCase()}</Text>
          </TouchableOpacity>
        ))}
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1, backgroundColor: '#0a0a0f' },
  flexFill: { flex: 1 },
  centered: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 12 },
  loadingText: { color: '#94a3b8' },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingHorizontal: 18,
    paddingVertical: 14,
    borderBottomWidth: 1,
    borderColor: '#1e1e2e',
  },
  brand: { color: '#f8fafc', fontSize: 22, fontWeight: '700' },
  subtle: { color: '#94a3b8', marginTop: 2 },
  pill: { paddingHorizontal: 12, paddingVertical: 8, borderRadius: 20, backgroundColor: '#241234' },
  pillText: { color: '#c4b5fd', fontWeight: '600' },
  scroll: { flex: 1 },
  messagesWrap: { padding: 16, gap: 12 },
  bubble: { padding: 14, borderRadius: 14, borderWidth: 1 },
  aiBubble: { backgroundColor: '#13131b', borderColor: '#2b2142' },
  userBubble: { backgroundColor: '#1f1130', borderColor: '#4c1d95', alignSelf: 'flex-end', width: '92%' },
  bubbleRole: { color: '#c4b5fd', fontWeight: '700', marginBottom: 6 },
  bubbleText: { color: '#e2e8f0', lineHeight: 21 },
  composer: {
    flexDirection: 'row',
    gap: 10,
    padding: 14,
    borderTopWidth: 1,
    borderColor: '#1e1e2e',
  },
  input: {
    flex: 1,
    minHeight: 48,
    maxHeight: 120,
    backgroundColor: '#111118',
    borderColor: '#232336',
    borderWidth: 1,
    borderRadius: 12,
    color: '#f8fafc',
    paddingHorizontal: 14,
    paddingVertical: 12,
  },
  sendBtn: { backgroundColor: '#7c3aed', borderRadius: 12, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 18 },
  sendText: { color: '#fff', fontWeight: '700' },
  accountWrap: { padding: 16, gap: 12 },
  card: { backgroundColor: '#111118', borderWidth: 1, borderColor: '#1e1e2e', borderRadius: 16, padding: 16 },
  cardTitle: { color: '#f8fafc', fontSize: 18, fontWeight: '700', marginBottom: 8 },
  cardText: { color: '#94a3b8', lineHeight: 21, marginBottom: 10 },
  field: {
    backgroundColor: '#0d0d14',
    color: '#f8fafc',
    borderWidth: 1,
    borderColor: '#24243a',
    borderRadius: 12,
    paddingHorizontal: 12,
    paddingVertical: 12,
    marginBottom: 10,
  },
  primaryBtn: { backgroundColor: '#7c3aed', borderRadius: 12, padding: 12, alignItems: 'center', marginTop: 4 },
  primaryBtnText: { color: '#fff', fontWeight: '700' },
  secondaryBtn: { backgroundColor: '#1f2937', borderRadius: 12, padding: 12, alignItems: 'center' },
  secondaryBtnText: { color: '#e2e8f0', fontWeight: '700' },
  notice: { color: '#c4b5fd', marginTop: 12, lineHeight: 20 },
  linkRow: { paddingVertical: 10 },
  linkText: { color: '#a78bfa', fontWeight: '600' },
  tabBar: {
    flexDirection: 'row',
    borderTopWidth: 1,
    borderColor: '#1e1e2e',
    backgroundColor: '#0c0c12',
  },
  tabBtn: { flex: 1, paddingVertical: 14, alignItems: 'center' },
  tabText: { color: '#64748b', fontWeight: '700', fontSize: 12 },
  tabTextActive: { color: '#c4b5fd' },
});
