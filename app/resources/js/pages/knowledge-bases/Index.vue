<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { ref } from 'vue';
import DocumentUploadForm from '@/components/DocumentUploadForm.vue';

// TypeScript interface for our knowledge base

interface Document {
    id: number;
    original_name: string;
    mime_type: string;
    size_bytes: number;
    status: string;
    created_at: string | null;
}

interface KnowledgeBase {
    id: number;
    name: string;
    description: string | null;
    documents: Document[];
}


// Data received from Laravel through Inertia
defineProps<{
    knowledgeBases: KnowledgeBase[];
}>();

// State to track the currently edited record
const editingId = ref<number | null>(null);

// Create form
const form = useForm({
    name: '',
    description: '',
});

// Edit form
const editForm = useForm({
    name: '',
    description: '',
});

// Create knowledge base
function submit() {
    form.post('/knowledge-bases', {
        onSuccess: () => form.reset(),
    });
}

// Start editing
function startEditing(kb: KnowledgeBase) {
    editingId.value = kb.id;

    editForm.name = kb.name;
    editForm.description = kb.description ?? '';

    editForm.clearErrors();
}

// Save edited knowledge base
function saveEdit() {
    if (editingId.value === null) return;

    editForm.put(`/knowledge-bases/${editingId.value}`, {
        onSuccess: () => {
            editingId.value = null;
            editForm.reset();
        },
    });
}

// Cancel editing
function cancelEdit() {
    editingId.value = null;
    editForm.reset();
    editForm.clearErrors();
}

// Delete knowledge base
function deleteKnowledgeBase(id: number) {
    if (!window.confirm('Delete this knowledge base?')) {
        return;
    }

    router.delete(`/knowledge-bases/${id}`, {
        preserveScroll: true,
    });
}


// Convert bytes into a readable file size
function formatFileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

// Format the document creation date
function formatDate(date: string | null): string {
    if (!date) return 'Unknown';

    const parsed = new Date(date);

    if (Number.isNaN(parsed.getTime())) {
        return 'Unknown';
    }

    return parsed.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}
function deleteDocument(
    knowledgeBaseId: number,
    documentId: number
) {
    if (!window.confirm('Delete this document?')) {
        return;
    }

    router.delete(
        `/knowledge-bases/${knowledgeBaseId}/documents/${documentId}`,
        {
            preserveScroll: true,
        }
    );
}

</script>

<template>

    <Head title="Knowledge Bases" />

    <!-- <AppLayout> -->
    <div class="mx-auto max-w-4xl space-y-8 p-6 sm:px-6 lg:px-8">
        <h1 class="text-2xl font-bold">Knowledge Bases</h1>

        <!-- CREATE FORM -->
        <form @submit.prevent="submit" class="space-y-4 rounded-lg border p-5 md:w-3xl">
            <h2 class="text-lg font-semibold">Create Knowledge Base</h2>

            <div>
                <label class="mb-1 block text-sm"> Name </label>

                <input v-model="form.name" placeholder="Knowledge base name" maxlength="255" required
                    class="w-full rounded border p-3" />

                <p class="text-sm text-red-500">
                    {{ form.errors.name }}
                </p>
            </div>

            <div>
                <label class="mb-1 block text-sm"> Description </label>

                <textarea v-model="form.description" placeholder="Description (optional)"
                    class="w-full rounded border p-3"></textarea>

                <p class="text-sm text-red-500">
                    {{ form.errors.description }}
                </p>
            </div>

            <button type="submit" :disabled="form.processing"
                class="rounded bg-blue-600 px-4 py-2 text-white disabled:opacity-50">
                {{ form.processing ? 'Saving...' : 'Create' }}
            </button>
        </form>

        <!-- KNOWLEDGE BASE LIST -->
        <div class="space-y-3">
            <h2 class="text-lg font-semibold">Your Knowledge Bases</h2>

            <p v-if="knowledgeBases.length === 0">
                No knowledge bases yet.
            </p>

            <div v-for="kb in knowledgeBases" :key="kb.id" class="rounded-lg border p-4">
                <!-- EDIT MODE -->
                <template v-if="editingId === kb.id">
                    <form @submit.prevent="saveEdit" class="space-y-3">
                        <input v-model="editForm.name" required class="w-full rounded border p-2" />

                        <p class="text-sm text-red-500">
                            {{ editForm.errors.name }}
                        </p>

                        <textarea v-model="editForm.description" class="w-full rounded border p-2"></textarea>

                        <p class="text-sm text-red-500">
                            {{ editForm.errors.description }}
                        </p>

                        <div class="flex gap-3">
                            <button type="submit" :disabled="editForm.processing"
                                class="rounded bg-green-600 px-4 py-2 text-white disabled:opacity-50">
                                Save
                            </button>

                            <button type="button" @click="cancelEdit" class="rounded border px-4 py-2">
                                Cancel
                            </button>
                        </div>
                    </form>
                </template>

                <!-- VIEW MODE -->
                <template v-else>
                    <h3 class="font-semibold">
                        {{ kb.name }}
                    </h3>

                    <p class="mt-1 text-sm text-gray-500">
                        {{ kb.description }}
                    </p>
                    <!-- {{ kb.documents }} -->
                    <div class="mt-4 flex gap-3">
                        <button type="button" @click="startEditing(kb)"
                            class="rounded bg-blue-600 px-4 py-2 text-white">
                            Edit
                        </button>

                        <button type="button" @click="deleteKnowledgeBase(kb.id)"
                            class="rounded bg-red-600 px-4 py-2 text-white">
                            Delete
                        </button>
                    </div>
                    <DocumentUploadForm :knowledge-base-id="kb.id" />

                    <!-- UPLOADED DOCUMENTS -->
                    <div class="mt-6 border-t pt-5">

                        <div class="mb-4 flex items-center justify-between">
                            <h4 class="font-semibold">
                                Uploaded Documents
                            </h4>

                            <span class="text-sm text-gray-500">
                                {{ kb.documents?.length ?? 0 }} files
                            </span>
                        </div>

                        <!-- Empty state -->
                        <div v-if="kb.documents?.length === 0" class="rounded-lg border border-dashed p-6 text-center">
                            <p class="text-sm text-gray-500">
                                No documents uploaded yet.
                            </p>
                        </div>

                        <!-- Document list -->
                        <div v-else class="space-y-3">

                            <div v-for="document in kb.documents" :key="document.id" class="flex flex-col gap-3 rounded-lg border p-4
                   sm:flex-row sm:items-center sm:justify-between">

                                <!-- File information -->
                                <div class="min-w-0">

                                    <p class="break-all text-sm font-medium">
                                        {{ document.original_name }}
                                    </p>

                                    <p class="mt-1 text-xs text-gray-500">
                                        {{ formatFileSize(document.size_bytes) }}
                                        ·
                                        {{ formatDate(document.created_at) }}
                                    </p>

                                </div>

                                <!-- Processing status -->
                                <span class="w-fit rounded-full px-3 py-1 text-xs font-medium capitalize" :class="{
                                    'bg-yellow-100 text-yellow-800':
                                        document.status === 'pending',

                                    'bg-blue-100 text-blue-800':
                                        document.status === 'processing',

                                    'bg-green-100 text-green-800':
                                        document.status === 'ready',

                                    'bg-red-100 text-red-800':
                                        document.status === 'failed',
                                }">
                                    {{ document.status }}
                                </span>
                                <a :href="`/knowledge-bases/${kb.id}/documents/${document.id}/download`"
                                    class="rounded border px-3 py-2 text-sm">
                                    Download
                                </a>

                                <button type="button" @click="deleteDocument(kb.id, document.id)"
                                    class="rounded bg-red-600 px-3 py-2 text-sm text-white">
                                    Delete
                                </button>

                            </div>

                        </div>

                    </div>

                </template>
            </div>
        </div>
    </div>
    <!-- </AppLayout> -->
</template>
