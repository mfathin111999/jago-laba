<script setup lang="ts">
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';

type User = { name?: string; username?: string } | null;

const props = defineProps<{
    title: string;
    status: string;
    authenticated: boolean;
    user: User;
    workspaces: string[];
    active_workspace: string | null;
}>();

const username = ref('');
const password = ref('');
const error = ref('');
const loading = ref(false);
const selecting = ref<string | null>(null);
const authenticated = ref(props.authenticated);
const user = ref<User>(props.user);
const workspaces = ref(props.workspaces);

async function readJson(response: Response): Promise<Record<string, any>> {
    try {
        return await response.json();
    } catch {
        return {};
    }
}

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function refreshSession() {
    const response = await fetch('/auth/me', { headers: { Accept: 'application/json' } });
    const payload = await readJson(response);
    if (!response.ok) throw new Error(payload.message || 'Sesi tidak dapat dibaca.');
    authenticated.value = true;
    user.value = payload.user;
    workspaces.value = payload.context?.allowed_workspaces ?? [];
}

async function login() {
    error.value = '';
    loading.value = true;

    try {
        const response = await fetch('/auth/login', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ username: username.value, password: password.value }),
        });
        const payload = await readJson(response);
        if (!response.ok) throw new Error(payload.message || 'Username atau password tidak valid.');
        await refreshSession();
        password.value = '';
    } catch (exception) {
        error.value = exception instanceof Error ? exception.message : 'Login gagal.';
    } finally {
        loading.value = false;
    }
}

async function selectWorkspace(workspace: string) {
    error.value = '';
    selecting.value = workspace;

    try {
        const response = await fetch('/auth/workspace', {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ workspace }),
        });
        const payload = await readJson(response);
        if (!response.ok || payload.success !== true) throw new Error(payload.message || 'Workspace tidak dapat dipilih.');
        window.location.href = workspace === 'marketplace' ? '/pilot/marketplace' : '/konveksi';
    } catch (exception) {
        error.value = exception instanceof Error ? exception.message : 'Workspace tidak dapat dipilih.';
        selecting.value = null;
    }
}

async function logout() {
    error.value = '';
    try {
        const response = await fetch('/auth/logout', { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() } });
        if (!response.ok) throw new Error('Belum berhasil keluar. Silakan coba lagi.');
        window.location.reload();
    } catch (exception) {
        error.value = exception instanceof Error ? exception.message : 'Belum berhasil keluar.';
    }
}
</script>

<template>
    <Head :title="authenticated ? 'Pilih ruang kerja · Jago Laba' : 'Login · Jago Laba'" />
    <main class="foundation-shell">
        <section class="foundation-card">
            <div class="foundation-brand"><span class="foundation-mark">JL</span><span><strong>JAGO LABA</strong><small>Ruang kerja bisnis Anda</small></span></div>

            <template v-if="!authenticated">
                <div class="foundation-heading"><span class="eyebrow">Akses aplikasi</span><h1>Masuk ke Jago Laba</h1><p>Masuk menggunakan akun Jago Laba Anda.</p></div>
                <form class="foundation-form" @submit.prevent="login">
                    <label>Username<input v-model="username" autocomplete="username" required placeholder="Username" /></label>
                    <label>Password<input v-model="password" autocomplete="current-password" required type="password" placeholder="Password" /></label>
                    <p v-if="error" class="foundation-error" role="alert">{{ error }}</p>
                    <button class="button button-primary" :disabled="loading" type="submit">{{ loading ? 'Memeriksa…' : 'Masuk' }}</button>
                </form>
            </template>

            <template v-else>
                <div class="foundation-heading"><span class="eyebrow">Jago Laba · Ruang kerja</span><h1>Selamat datang{{ user?.name ? `, ${user.name}` : '' }}</h1><p>Pilih ruang kerja yang ingin Anda gunakan.</p></div>
                <div class="workspace-cards">
                    <button v-if="workspaces.includes('konveksi')" class="workspace-card workspace-konveksi" :disabled="selecting !== null" type="button" @click="selectWorkspace('konveksi')"><span class="workspace-icon">K</span><strong>Manajemen Konveksi</strong><small>Produk, bahan baku, HPP, persediaan, dan keuangan konveksi.</small><b>{{ selecting === 'konveksi' ? 'Membuka…' : 'Masuk ke Konveksi →' }}</b></button>
                    <button v-if="workspaces.includes('marketplace')" class="workspace-card workspace-marketplace" :disabled="selecting !== null" type="button" @click="selectWorkspace('marketplace')"><span class="workspace-icon">M</span><strong>Manajemen Marketplace</strong><small>Pesanan, laba, pengeluaran, pencairan dana, dan retur marketplace.</small><b>{{ selecting === 'marketplace' ? 'Membuka…' : 'Masuk ke Marketplace →' }}</b></button>
                </div>
                <p v-if="error" class="foundation-error" role="alert">{{ error }}</p>
                <div class="foundation-footer"><span>Workspace aktif: {{ active_workspace || 'belum dipilih' }}</span><button class="link-button" type="button" @click="logout">Keluar dari akun</button></div>
            </template>
        </section>
    </main>
</template>
