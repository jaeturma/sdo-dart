<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    modelValue: [File, Object],
    accept:     { type: String, default: undefined },
    hint:       { type: String, default: undefined },
    error:      { type: String, default: undefined },
    id:         { type: String, default: undefined },
});

const emit = defineEmits(['update:modelValue']);

const inputRef = ref(null);
const fileName = computed(() => props.modelValue?.name ?? '');

const onChange = (e) => emit('update:modelValue', e.target.files[0] ?? null);
</script>

<template>
    <div>
        <div
            class="flex cursor-pointer items-center gap-3 rounded-lg border border-gray-300 bg-gray-50 px-4 py-2.5 transition-colors hover:border-blue-400 hover:bg-blue-50"
            @click="inputRef.click()"
        >
            <svg class="h-5 w-5 shrink-0 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
            </svg>
            <span
                class="min-w-0 flex-1 truncate text-sm"
                :class="fileName ? 'text-gray-900' : 'text-gray-400'"
            >
                {{ fileName || 'Click to choose a file…' }}
            </span>
            <span class="shrink-0 rounded bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-700">
                Browse
            </span>
            <input
                :id="id"
                ref="inputRef"
                type="file"
                class="sr-only"
                :accept="accept"
                @change="onChange"
            />
        </div>
        <p v-if="hint && !error" class="mt-1 text-xs text-gray-500">{{ hint }}</p>
        <p v-if="error" class="mt-1 text-sm text-red-600">{{ error }}</p>
    </div>
</template>
