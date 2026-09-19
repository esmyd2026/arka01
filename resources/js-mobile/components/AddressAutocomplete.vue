<script setup>
import { ref, shallowRef, onBeforeUnmount } from 'vue';
import { loadGooglePlaces } from '../utils/googleMaps';

// Versión móvil de resources/js/Components/AddressAutocomplete.vue: misma
// lógica de Google Places (sesión de autocompletado, debounce, token de
// selección contra respuestas fuera de orden), sin las clases de Tailwind
// del resto de la web — este proyecto móvil todavía no usa Tailwind.
const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue', 'place-selected', 'clear']);

let placesLib = null;
let placesLoading = null;
let sessionToken = null;
let debounceTimer = null;
let suggestionRequest = 0;
let selectionToken = 0;

const suggestions = shallowRef([]);
const open = ref(false);

function ensurePlacesLoaded() {
    if (placesLib) return Promise.resolve(placesLib);
    if (!placesLoading) {
        placesLoading = loadGooglePlaces().then((lib) => {
            placesLib = lib;
            return lib;
        });
    }
    return placesLoading;
}

function newSessionToken() {
    if (!placesLib) return null;
    sessionToken = new placesLib.AutocompleteSessionToken();
    return sessionToken;
}

function onInput(event) {
    const value = event.target.value;
    emit('update:modelValue', value);

    clearTimeout(debounceTimer);

    if (!value.trim() || value.trim().length < 3) {
        suggestions.value = [];
        open.value = false;
        return;
    }

    debounceTimer = setTimeout(async () => {
        await ensurePlacesLoaded();
        fetchSuggestions(value);
    }, 300);
}

async function fetchSuggestions(text) {
    if (!placesLib?.AutocompleteSuggestion) return;

    const requestId = ++suggestionRequest;

    try {
        const { suggestions: results } = await placesLib.AutocompleteSuggestion.fetchAutocompleteSuggestions({
            input: text,
            sessionToken: sessionToken ?? newSessionToken(),
            includedRegionCodes: ['ec'],
        });

        if (requestId !== suggestionRequest) return;
        suggestions.value = results ?? [];
        open.value = suggestions.value.length > 0;
    } catch (error) {
        console.warn('Arka01: Google Places respondió pero no se pudieron armar las sugerencias.', error);
        if (requestId !== suggestionRequest) return;
        suggestions.value = [];
        open.value = false;
    }
}

async function selectSuggestion(suggestion) {
    const myToken = ++selectionToken;
    open.value = false;

    try {
        const place = suggestion.placePrediction.toPlace();
        await place.fetchFields({ fields: ['location', 'formattedAddress'] });
        if (myToken !== selectionToken) return;

        emit('update:modelValue', place.formattedAddress ?? suggestion.placePrediction.text.text);
        emit('place-selected', {
            lat: place.location.lat(),
            lng: place.location.lng(),
            address: place.formattedAddress ?? suggestion.placePrediction.text.text,
        });
    } catch {
        if (myToken !== selectionToken) return;
        emit('update:modelValue', suggestion.placePrediction.text.text);
    }

    if (myToken !== selectionToken) return;
    suggestions.value = [];
    sessionToken = null;
}

function clearField() {
    emit('update:modelValue', '');
    emit('clear');
    suggestions.value = [];
    open.value = false;
    sessionToken = null;
}

function close() {
    open.value = false;
}

onBeforeUnmount(() => clearTimeout(debounceTimer));
</script>

<template>
    <div class="address-autocomplete">
        <input
            type="text"
            :value="modelValue"
            :placeholder="placeholder"
            autocomplete="off"
            @input="onInput"
            @keydown.escape="close"
            @focus="() => { ensurePlacesLoaded(); open = suggestions.length > 0; }"
        />
        <button v-if="modelValue?.trim()" type="button" class="clear-btn" aria-label="Limpiar" @click="clearField">×</button>

        <div v-if="open" class="backdrop" @click="close" />

        <ul v-if="open && suggestions.length" class="suggestions">
            <li v-for="suggestion in suggestions" :key="suggestion.placePrediction.placeId">
                <button type="button" @click="selectSuggestion(suggestion)">
                    {{ suggestion.placePrediction.text.text }}
                </button>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.address-autocomplete {
    position: relative;
}
input {
    width: 100%;
    min-height: 3.35rem;
    padding: 0.75rem 2.5rem 0.75rem 0.9rem;
    border: 1px solid var(--arka-border);
    border-radius: .95rem;
    outline: none;
    background: var(--arka-field);
    color: var(--arka-text);
    font-size: .9rem;
    box-sizing: border-box;
}
input:focus { border-color: var(--arka-primary); box-shadow: 0 0 0 3px var(--arka-primary-soft); }
input::placeholder { color: var(--arka-muted); }
.clear-btn {
    position: absolute;
    right: 0.5rem;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    font-size: 1.3rem;
    line-height: 1;
    color: var(--arka-muted);
    padding: 0.25rem;
}
.backdrop {
    position: fixed;
    inset: 0;
    z-index: 40;
}
.suggestions {
    position: absolute;
    z-index: 50;
    top: calc(100% + 0.25rem);
    left: 0;
    right: 0;
    max-height: 14rem;
    overflow-y: auto;
    background: var(--arka-card);
    border: 1px solid var(--arka-border);
    border-radius: .95rem;
    box-shadow: var(--arka-shadow);
    list-style: none;
    margin: 0;
    padding: 0.25rem 0;
}
.suggestions li button {
    display: block;
    width: 100%;
    text-align: left;
    padding: 0.5rem 0.75rem;
    background: none;
    border: none;
    font-size: 0.85rem;
    color: var(--arka-text);
}
.suggestions li button:hover {
    background: var(--arka-primary-soft);
}
</style>
