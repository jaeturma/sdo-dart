<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import Dropdown from '@/Components/Dropdown.vue';

const page = usePage();
const unreadCount = computed(() => page.props.unreadNotificationsCount ?? 0);

const notifications = ref([]);
const loading = ref(false);
const loaded = ref(false);

const loadNotifications = async () => {
    loading.value = true;
    try {
        const { data } = await window.axios.get(route('notifications.index'));
        notifications.value = data;
        loaded.value = true;
    } finally {
        loading.value = false;
    }
};

const onOpen = () => {
    if (! loaded.value) loadNotifications();
};

const openNotification = (notification) => {
    // Built from the current origin via Ziggy's route() helper, not the
    // absolute URL stored in notification.data — that URL is baked in with
    // whatever host/scheme was active at the moment the notification was
    // created, which goes stale the instant the app is reached via a
    // different host (custom domain vs. LAN IP vs. a future domain change),
    // silently breaking navigation for any notification created earlier
    // under a different host.
    const navigate = () => router.visit(route('documents.show', notification.data.document_id));

    if (notification.read_at) {
        navigate();
        return;
    }

    // Optimistic local update so the dropdown list itself reflects "read"
    // immediately, without waiting on the request below.
    notification.read_at = new Date().toISOString();

    // Previously fire-and-forget, racing the navigation below: since
    // unreadNotificationsCount is recomputed fresh on every Inertia request
    // (HandleInertiaRequests), whichever of the two requests the server
    // happened to process first decided whether the badge on the next page
    // reflected this read — often losing the race, so the count visibly
    // failed to decrement. Waiting for this to settle (success or failure,
    // via .finally) before navigating guarantees the mark-as-read has
    // already been attempted by the time the next page's props are computed.
    window.axios.patch(route('notifications.read', notification.id))
        .catch(() => {})
        .finally(navigate);
};

const markAllRead = () => {
    router.patch(route('notifications.read-all'), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            notifications.value = notifications.value.map(n => ({ ...n, read_at: n.read_at ?? new Date().toISOString() }));
        },
    });
};

const formatDate = d => d
    ? new Date(d).toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
    : '';

let pollTimer = null;
onMounted(() => {
    pollTimer = setInterval(() => {
        router.reload({ only: ['unreadNotificationsCount'], preserveScroll: true, preserveState: true });
    }, 30000);
});
onBeforeUnmount(() => clearInterval(pollTimer));
</script>

<template>
    <div class="relative ms-3">
        <Dropdown
            panel-class="fixed inset-x-3 top-16 origin-top sm:absolute sm:inset-x-auto sm:top-auto sm:end-0 sm:w-80 sm:origin-top-right"
        >
            <template #trigger>
                <button
                    type="button"
                    class="relative inline-flex items-center justify-center rounded-md border border-transparent bg-white p-2 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                    aria-label="Notifications"
                    @click="onOpen"
                >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/>
                    </svg>
                    <span
                        v-if="unreadCount > 0"
                        class="absolute -right-0.5 -top-0.5 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-red-500 px-1 text-xs font-bold text-white"
                    >
                        {{ unreadCount > 99 ? '99+' : unreadCount }}
                    </span>
                </button>
            </template>

            <template #content>
                <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2">
                    <h3 class="text-sm font-semibold text-gray-700">Notifications</h3>
                    <button
                        v-if="notifications.some(n => !n.read_at)"
                        type="button"
                        class="text-xs text-blue-600 hover:underline"
                        @click.stop="markAllRead"
                    >
                        Mark all read
                    </button>
                </div>

                <div class="max-h-96 overflow-y-auto">
                    <p v-if="loading" class="px-4 py-6 text-center text-sm text-gray-400">Loading…</p>
                    <p v-else-if="notifications.length === 0" class="px-4 py-6 text-center text-sm text-gray-400">
                        No notifications yet.
                    </p>
                    <button
                        v-for="n in notifications"
                        :key="n.id"
                        type="button"
                        class="block w-full border-b border-gray-50 px-4 py-3 text-left last:border-0 hover:bg-blue-50"
                        :class="! n.read_at ? 'bg-blue-50/50' : ''"
                        @click="openNotification(n)"
                    >
                        <p class="text-sm font-medium text-gray-900">{{ n.data.headline }}</p>
                        <p class="mt-0.5 text-xs text-gray-600">{{ n.data.body }}</p>
                        <p class="mt-1 text-xs text-gray-400">{{ formatDate(n.created_at) }}</p>
                    </button>
                </div>
            </template>
        </Dropdown>
    </div>
</template>
