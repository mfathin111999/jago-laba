<script setup lang="ts">
import { computed, ref } from 'vue';
import JlIcon from '../Components/JlIcon.vue';
import { Head } from '@inertiajs/vue3';
import { ROUTES } from '../lib/routes';

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
const dialogOpen = ref(true);
const displayName = computed(() => user.value?.name || user.value?.username || 'Pengguna');
const WORKSPACES: Record<string, { title: string; description: string; button: string; icon: string }> = {
    konveksi: { title: 'Manajemen Konveksi', description: 'Kelola produksi, bahan baku, HPP, persediaan, dan keuangan konveksi.', button: 'Masuk ke Konveksi', icon: 'package' },
    marketplace: { title: 'Manajemen Marketplace', description: 'Pantau pesanan, laba, pencairan dana, iklan, dan retur marketplace.', button: 'Masuk ke Marketplace', icon: 'store' },
};

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
    const response = await fetch(ROUTES.auth.me, { headers: { Accept: 'application/json' } });
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
        const response = await fetch(ROUTES.auth.login, {
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
        const response = await fetch(ROUTES.auth.workspace, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ workspace }),
        });
        const payload = await readJson(response);
        if (!response.ok || payload.success !== true) throw new Error(payload.message || 'Workspace tidak dapat dipilih.');
        window.location.href = workspace === 'marketplace' ? ROUTES.dashboard : ROUTES.konveksi;
    } catch (exception) {
        error.value = exception instanceof Error ? exception.message : 'Workspace tidak dapat dipilih.';
        selecting.value = null;
    }
}

async function logout() {
    error.value = '';
    try {
        const response = await fetch(ROUTES.auth.logout, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() } });
        if (!response.ok) throw new Error('Belum berhasil keluar. Silakan coba lagi.');
        window.location.reload();
    } catch (exception) {
        error.value = exception instanceof Error ? exception.message : 'Belum berhasil keluar.';
    }
}
</script>

<template>
    <Head :title="authenticated ? 'Pilih Ruang Kerja · Jago Laba' : 'Login · Jago Laba'" />
    <div class="ws">
        <template v-if="!authenticated">
            <section class="login-card">
                <div class="brand"><span class="brandmark">JL</span> Jago Laba</div>
                <span class="eyebrow" style="display: block; margin-top: 22px">Akses aplikasi</span>
                <h1>Masuk ke Jago Laba</h1>
                <p>Masuk menggunakan akun Jago Laba Anda.</p>
                <form @submit.prevent="login">
                    <label>Username<input v-model="username" autocomplete="username" required placeholder="Username" /></label>
                    <label>Password<input v-model="password" autocomplete="current-password" required type="password" placeholder="Password" /></label>
                    <p v-if="error" class="error" role="alert">{{ error }}</p>
                    <button class="submit" :disabled="loading" type="submit">{{ loading ? 'Memeriksa…' : 'Masuk' }}</button>
                </form>
            </section>
        </template>

        <template v-else>
            <main class="shell" :inert="dialogOpen">
                <div class="top"><div class="brand"><span class="brandmark">JL</span> Jago Laba</div><button class="logout" type="button" @click="logout">Keluar</button></div>
                <div class="intro"><h1>Pilih ruang kerja</h1><p>Anda masuk sebagai {{ displayName }}. Pilihan ini dapat diganti kapan saja.</p></div>
                <div class="cards">
                    <button v-for="key in workspaces.filter((k) => WORKSPACES[k])" :key="key" type="button" class="card" :class="key" :disabled="selecting !== null" @click="selectWorkspace(key)"><span class="icon"><JlIcon :name="WORKSPACES[key].icon" /></span><h2>{{ WORKSPACES[key].title }}</h2><p>{{ WORKSPACES[key].description }}</p><span class="action">{{ selecting === key ? 'Membuka ruang kerja…' : WORKSPACES[key].button }} →</span></button>
                </div>
            </main>
            <div v-if="dialogOpen" class="backdrop">
                <section class="dialog" role="dialog" aria-modal="true" aria-labelledby="dialogTitle" tabindex="-1">
                    <div class="dialog-head"><div><span class="eyebrow">JAGO LABA · RUANG KERJA</span><h1 id="dialogTitle">Selamat datang di Jago Laba</h1><p class="lead">Pilih ruang kerja untuk mulai mengelola bisnis Anda.</p></div><button class="close" type="button" aria-label="Tutup dialog" @click="dialogOpen = false">✕</button></div>
                    <p class="hello">Halo, <strong>{{ displayName }}</strong></p>
                    <div v-if="error" class="error show" role="alert">{{ error }}</div>
                    <div class="cards">
                        <button v-for="key in workspaces.filter((k) => WORKSPACES[k])" :key="key" type="button" class="card" :class="key" :disabled="selecting !== null" @click="selectWorkspace(key)"><span class="icon"><JlIcon :name="WORKSPACES[key].icon" /></span><h2>{{ WORKSPACES[key].title }}</h2><p>{{ WORKSPACES[key].description }}</p><span class="action">{{ selecting === key ? 'Membuka ruang kerja…' : WORKSPACES[key].button }} →</span></button>
                    </div>
                    <div class="foot"><span>Ruang kerja aktif hanya berubah setelah Anda memilih.</span><button class="logout" type="button" @click="logout">Keluar dari akun</button></div>
                </section>
            </div>
        </template>
    </div>
</template>
