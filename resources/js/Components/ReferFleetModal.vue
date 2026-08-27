<script setup>
import { computed, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { useForm } from '@inertiajs/vue3';

// "Recomendar mi flota" (pedido explícito del usuario): buscar a un amigo
// (otro cliente) por su usuario o código, y recomendarle uno o varios
// conductores de ESTA flota puntual — seleccionando todo o uno a uno. Cada
// conductor elegido recibe una FleetInvitation (initiated_by = 'referral')
// a nombre de este cliente, para la flota del amigo — nunca entra sin que
// el conductor la acepte.
const props = defineProps({
    show: { type: Boolean, default: false },
    fleet: { type: Object, required: true },
});

const emit = defineEmits(['close']);

// Paso 1: buscar al amigo.
const searchTerm = ref('');
const searchResults = ref([]);
const searching = ref(false);
const lastSearchedTerm = ref('');
let searchTimeout = null;

const runSearch = () => {
    clearTimeout(searchTimeout);

    if (searchTerm.value.trim().length < 2) {
        searchResults.value = [];
        lastSearchedTerm.value = '';
        return;
    }

    searchTimeout = setTimeout(async () => {
        searching.value = true;
        try {
            const { data } = await window.axios.get(route('fleet.referral.search-friends', props.fleet.id), {
                params: { q: searchTerm.value },
            });
            searchResults.value = data.friends;
            lastSearchedTerm.value = searchTerm.value;
        } finally {
            searching.value = false;
        }
    }, 300);
};

const showNoFriendFound = computed(
    () => !searching.value && lastSearchedTerm.value === searchTerm.value && searchResults.value.length === 0
);

// Paso 2: elegir amigo y conductores.
const selectedFriend = ref(null);
const form = useForm({
    friend_user_id: null,
    driver_user_ids: [],
    message: '',
});

function chooseFriend(friend) {
    selectedFriend.value = friend;
    form.friend_user_id = friend.user_id;
}

function backToSearch() {
    selectedFriend.value = null;
    form.friend_user_id = null;
}

const drivers = computed(() => (props.fleet.active_members ?? []).map((member) => member.driver));
const allSelected = computed(() => drivers.value.length > 0 && form.driver_user_ids.length === drivers.value.length);

function toggleSelectAll() {
    form.driver_user_ids = allSelected.value ? [] : drivers.value.map((driver) => driver.id);
}

function toggleDriver(driverId) {
    const index = form.driver_user_ids.indexOf(driverId);
    if (index === -1) {
        form.driver_user_ids.push(driverId);
    } else {
        form.driver_user_ids.splice(index, 1);
    }
}

function submit() {
    form.post(route('fleet.referral.store', props.fleet.id), {
        preserveScroll: true,
        onSuccess: () => close(),
    });
}

function close() {
    form.reset();
    form.clearErrors();
    selectedFriend.value = null;
    searchTerm.value = '';
    searchResults.value = [];
    lastSearchedTerm.value = '';
    emit('close');
}

// Si el modal se reabre para otra flota, arranca de cero.
watch(
    () => props.show,
    (isOpen) => {
        if (!isOpen) return;
        selectedFriend.value = null;
        form.reset();
        form.clearErrors();
    }
);
</script>

<template>
    <Modal :show="show" @close="close" max-width="lg">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-arka-text">Recomendar mi flota</h2>
            <p class="mt-1 text-sm text-arka-text-muted">
                Busque a un amigo por su usuario o código de socio y recomiéndele uno o varios de sus conductores de
                confianza. Le llega a cada uno como una invitación normal, con su nombre para que sepan quién lo
                recomendó.
            </p>

            <!-- Paso 1: buscar amigo -->
            <div v-if="!selectedFriend" class="mt-5">
                <InputLabel value="Usuario o código de socio del amigo" />
                <!-- Bug reportado por el usuario ("el botón no funciona"): no
                     estaba roto, buscaba por coincidencia EXACTA (por
                     privacidad, mismo criterio que el resto de buscadores por
                     código) pero no lo aclaraba en ningún lado — quien
                     escribía un nombre o un usuario a medias solo veía "no
                     encontramos a nadie", sin entender por qué. -->
                <TextInput
                    v-model="searchTerm"
                    type="text"
                    class="mt-1 block w-full"
                    placeholder="Ej: @maria o 512"
                    @input="runSearch"
                />
                <p class="mt-1 text-xs text-arka-text-muted">
                    Debe ser el usuario exacto (con o sin @) o el código de socio completo — no busca por nombre.
                </p>

                <ul v-if="searchResults.length" class="mt-4 divide-y divide-arka-text-muted/10">
                    <li v-for="friend in searchResults" :key="friend.user_id" class="py-3 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3 min-w-0">
                            <UserAvatar :user="friend" size-class="h-11 w-11 text-sm shrink-0" />
                            <div class="min-w-0">
                                <p class="text-arka-text font-medium truncate">{{ friend.name }}</p>
                                <p class="text-sm text-arka-text-muted truncate">
                                    <span v-if="friend.username">@{{ friend.username }}</span>
                                    <span v-if="friend.member_code"> · #{{ friend.member_code }}</span>
                                    <span v-if="friend.city"> · {{ friend.city }}</span>
                                </p>
                            </div>
                        </div>
                        <SecondaryButton class="shrink-0" @click="chooseFriend(friend)">Elegir</SecondaryButton>
                    </li>
                </ul>

                <p v-if="showNoFriendFound" class="mt-4 text-sm text-arka-text-muted">
                    No encontramos a nadie con ese usuario o código exacto. Revise que esté bien escrito — no busca por nombre.
                </p>
            </div>

            <!-- Paso 2: elegir conductores -->
            <div v-else class="mt-5">
                <div class="flex items-center justify-between gap-3 rounded-arka border border-arka-primary/30 bg-arka-primary/5 p-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <UserAvatar :user="selectedFriend" size-class="h-10 w-10 text-sm shrink-0" />
                        <p class="text-sm text-arka-text truncate">
                            Recomendando a <span class="font-medium">{{ selectedFriend.name }}</span>
                        </p>
                    </div>
                    <button type="button" class="shrink-0 text-xs font-semibold text-arka-primary hover:text-arka-primary-bright" @click="backToSearch">
                        Cambiar
                    </button>
                </div>
                <InputError class="mt-2" :message="form.errors.friend_user_id" />

                <div class="mt-4 flex items-center justify-between">
                    <p class="text-sm font-medium text-arka-text">Conductores de esta flota</p>
                    <button
                        v-if="drivers.length > 1"
                        type="button"
                        class="text-xs font-semibold text-arka-primary hover:text-arka-primary-bright"
                        @click="toggleSelectAll"
                    >
                        {{ allSelected ? 'Quitar selección' : 'Seleccionar todos' }}
                    </button>
                </div>

                <ul class="mt-2 max-h-72 divide-y divide-arka-text-muted/10 overflow-y-auto rounded-arka border border-arka-text-muted/10">
                    <li v-for="driver in drivers" :key="driver.id" class="flex items-center gap-3 p-3">
                        <input
                            :id="`refer-driver-${driver.id}`"
                            type="checkbox"
                            class="h-4 w-4 rounded border-arka-text-muted/30 text-arka-primary focus:ring-arka-primary"
                            :checked="form.driver_user_ids.includes(driver.id)"
                            @change="toggleDriver(driver.id)"
                        />
                        <label :for="`refer-driver-${driver.id}`" class="flex min-w-0 flex-1 items-center gap-3 cursor-pointer">
                            <UserAvatar :user="driver" size-class="h-9 w-9 text-xs shrink-0" />
                            <span class="truncate text-sm text-arka-text">{{ driver.name }}</span>
                        </label>
                    </li>
                </ul>
                <InputError class="mt-2" :message="form.errors.driver_user_ids" />

                <p class="mt-3 text-sm text-arka-text-muted">{{ form.driver_user_ids.length }} conductor(es) seleccionado(s)</p>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <SecondaryButton @click="close">Cancelar</SecondaryButton>
                <PrimaryButton v-if="selectedFriend" :disabled="form.processing || form.driver_user_ids.length === 0" @click="submit">
                    Recomendar
                </PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
