<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

// Props received from the parent component
const props = defineProps<{
    knowledgeBaseId: number;
}>();

// Upload form state
const form = useForm<{
    file: File | null;
}>({
    file: null,
});

// Used to reset the native file input
const fileInputKey = ref(0);

const successMessage = ref('');

// Called when a user selects a file
function handleFileChange(event: Event) {
    const input = event.target as HTMLInputElement;

    form.file = input.files?.[0] ?? null;

    form.clearErrors('file');
    successMessage.value = '';
}

// Upload the selected file
function submit() {
    if (!form.file || form.processing) return;

    form.post(
        `/knowledge-bases/${props.knowledgeBaseId}/documents`,
        {
            forceFormData: true,
            preserveScroll: true,

            onSuccess: () => {
                form.reset();
                fileInputKey.value++;

                successMessage.value =
                    'Document uploaded successfully. Processing pending.';
            },
        }
    );
}
</script>

<template>
    <form @submit.prevent="submit" class="mt-5 space-y-4 border-t pt-4">
        <div>
            <h4 class="font-semibold">
                Upload Document
            </h4>

            <p class="mt-1 text-sm text-gray-500">
                Supported: PDF, TXT, MD (Max 10 MB)
            </p>
        </div>

        <!-- File selection -->
        <div>
            <input :key="fileInputKey" type="file" accept=".pdf,.txt,.md" required :disabled="form.processing"
                @change="handleFileChange" class="block w-full rounded-lg border p-3 text-sm" />
        </div>

        <!-- Selected filename -->
        <p v-if="form.file" class="text-sm text-gray-500">
            Selected: {{ form.file.name }}
        </p>

        <!-- Validation errors -->
        <p v-if="form.errors.file" class="text-sm text-red-500">
            {{ form.errors.file }}
        </p>

        <!-- Upload progress -->
        <div v-if="form.progress">
            <progress :value="form.progress.percentage" max="100" class="w-full" />

            <p class="text-sm text-gray-500">
                {{ form.progress.percentage }}%
                uploaded
            </p>
        </div>

        <!-- Submit -->
        <button type="submit" :disabled="!form.file || form.processing" class="rounded-lg bg-blue-600 px-4 py-2
                   text-white disabled:opacity-50">
            {{
                form.processing
                    ? 'Uploading...'
                    : 'Upload Document'
            }}
        </button>

        <!-- Success -->
        <p v-if="successMessage" class="text-sm text-green-500" role="status">
            {{ successMessage }}
        </p>
    </form>
</template>
